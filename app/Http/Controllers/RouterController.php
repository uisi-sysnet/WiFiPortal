<?php

namespace App\Http\Controllers;

use App\Jobs\ProvisionHotspot;
use App\Models\MikrotikRouter;
use App\Services\Mikrotik\HotspotProvisioner;
use App\Services\Mikrotik\SubnetAllocator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class RouterController extends Controller
{
    public function index(SubnetAllocator $allocator)
    {
        return view('routers.index', [
            'routers' => MikrotikRouter::query()->orderBy('name')->paginate(50),
            'plan' => $allocator->summary(),
        ]);
    }

    public function create(SubnetAllocator $allocator)
    {
        try {
            $next = $allocator->next();
        } catch (RuntimeException) {
            $next = null;
        }

        return view('routers.create', [
            'next' => $next,
            'plan' => $allocator->summary(),
            'models' => config('mikrotik_models'),
            'vlanDefaults' => config('hotspot.vlans'),
        ]);
    }

    /** AJAX: read the real port list from a router before it is saved. */
    public function detect(Request $request, HotspotProvisioner $provisioner)
    {
        $data = $request->validate($this->connectionRules());
        $data['use_ssl'] = $request->boolean('use_ssl');

        try {
            $info = $provisioner->inspect(new MikrotikRouter($data));
        } catch (Throwable $e) {
            return response()->json(['message' => 'Could not read the router: '.$e->getMessage()], 422);
        }

        return response()->json(Arr::except($info, ['api_interface']));
    }

    public function store(Request $request, HotspotProvisioner $provisioner, SubnetAllocator $allocator)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._ -]+$/', 'unique:mikrotik_routers,name'],
            'location' => ['nullable', 'string', 'max:255'],
            ...$this->connectionRules(),
            'model' => ['required', Rule::in([...array_keys(config('mikrotik_models')), 'detected'])],
            'ports' => ['required', 'array', 'min:2'],
            'ports.*' => ['required', Rule::in(MikrotikRouter::ROLES)],
            'wan_mode' => ['required', Rule::in(['keep', 'dhcp', 'static'])],
            'wan_address' => ['exclude_unless:wan_mode,static', 'required', $this->cidrRule()],
            'wan_gateway' => ['exclude_unless:wan_mode,static', 'required', 'ipv4'],
            ...$this->vlanRules(),
            'mgmt_native' => ['sometimes', 'boolean'],
        ], [
            'name.regex' => 'Use letters, numbers, spaces, dots, dashes or underscores.',
            'ports.required' => 'Choose a router model or read the ports from the router.',
        ]);

        $roles = $data['ports'];
        foreach (array_keys($roles) as $port) {
            if (! preg_match('/^[A-Za-z0-9._-]{1,64}$/', (string) $port)) {
                throw ValidationException::withMessages(['ports' => "\"{$port}\" is not a valid port name."]);
            }
        }
        $count = array_count_values($roles);
        if (($count['wan'] ?? 0) !== 1) {
            throw ValidationException::withMessages(['ports' => 'Choose exactly one WAN port.']);
        }
        if (($count['trunk'] ?? 0) + ($count['access'] ?? 0) < 1) {
            throw ValidationException::withMessages(['ports' => 'Choose at least one trunk or hotspot port.']);
        }

        $vlans = $this->checkedVlans($data['vlans'], array_keys($roles));
        $vlans['mgmt']['native'] = $request->boolean('mgmt_native');

        $router = new MikrotikRouter([
            ...Arr::except($data, ['ports', 'vlans', 'mgmt_native']),
            'vlans' => $vlans,
            'use_ssl' => $request->boolean('use_ssl'),
            'port_roles' => $roles,
            'wan_interface' => array_search('wan', $roles, true),
        ]);

        // Fail fast: wrong IP, credentials, port names, or a plan that would cut the connection.
        try {
            $router->fill($provisioner->probe($router));
        } catch (Throwable $e) {
            return back()
                ->withInput($request->except('password'))
                ->withErrors(['host' => 'Could not use this router: '.$e->getMessage()]);
        }

        try {
            // Lock so two admins adding routers at once never get the same subnets.
            Cache::lock('hotspot:subnet-allocation', 10)->block(5, function () use ($router, $allocator) {
                $router->fill($allocator->next());
                $router->status = MikrotikRouter::STATUS_PENDING;
                $router->save();
            });
        } catch (Throwable $e) {
            return back()
                ->withInput($request->except('password'))
                ->withErrors(['name' => $e->getMessage()]);
        }

        ProvisionHotspot::dispatch($router);

        return redirect()
            ->route('routers.show', $router)
            ->with('status', "{$router->name} added. The configuration is being applied.");
    }

    public function show(MikrotikRouter $router)
    {
        return view('routers.show', ['router' => $router]);
    }

    public function provision(MikrotikRouter $router)
    {
        $router->forceFill(['status' => MikrotikRouter::STATUS_PENDING, 'last_error' => null])->save();
        ProvisionHotspot::dispatch($router);

        return redirect()
            ->route('routers.show', $router)
            ->with('status', 'Configuration queued for '.$router->name.'.');
    }

    public function destroy(MikrotikRouter $router)
    {
        $name = $router->name;
        $router->delete();

        return redirect()
            ->route('routers.index')
            ->with('status', "{$name} removed and its subnets returned to the address plan. The router itself was not changed.");
    }

    private function connectionRules(): array
    {
        return [
            'host' => ['required', 'string', 'max:255'],
            'api_port' => ['required', 'integer', 'between:1,65535'],
            'use_ssl' => ['sometimes', 'boolean'],
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:128'],
        ];
    }

    private function vlanRules(): array
    {
        $rules = ['vlans' => ['required', 'array']];
        foreach (array_keys(MikrotikRouter::NETWORKS) as $net) {
            $rules["vlans.{$net}.id"] = ['required', 'integer', 'between:2,4094'];
            $rules["vlans.{$net}.name"] = ['required', 'string', 'regex:/^[A-Za-z0-9._-]{1,32}$/'];
        }

        return $rules;
    }

    /** VLAN IDs and interface names must be unique and must not clash with ports or bridges. */
    private function checkedVlans(array $input, array $portNames): array
    {
        $vlans = [];
        foreach (array_keys(MikrotikRouter::NETWORKS) as $net) {
            $vlans[$net] = ['id' => (int) $input[$net]['id'], 'name' => $input[$net]['name']];
        }

        $ids = array_column($vlans, 'id');
        if (count(array_unique($ids)) !== count($ids)) {
            throw ValidationException::withMessages(['vlans' => 'Each network needs its own VLAN ID.']);
        }

        $names = array_column($vlans, 'name');
        $reserved = [...$portNames, HotspotProvisioner::TRUNK, HotspotProvisioner::LAN_BRIDGE];
        if (count(array_unique($names)) !== count($names) || array_intersect($names, $reserved)) {
            throw ValidationException::withMessages(['vlans' => 'Each VLAN interface needs its own name, different from the port and bridge names.']);
        }

        return $vlans;
    }

    private function cidrRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            [$ip, $prefix] = array_pad(explode('/', (string) $value, 2), 2, '');
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || ! ctype_digit($prefix) || $prefix < 1 || $prefix > 32) {
                $fail('Enter the address with its prefix, like 203.0.113.10/29.');
            }
        };
    }
}
