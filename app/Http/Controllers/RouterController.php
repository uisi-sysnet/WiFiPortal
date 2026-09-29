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
    /** Session key holding the last successful API test. */
    private const TEST_KEY = 'router_api_test';

    /** How long a passed test stays valid, in seconds. */
    private const TEST_TTL = 900;

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

    /** AJAX: the "Test API connection" button. A success unlocks Add and configure. */
    public function testConnection(Request $request, HotspotProvisioner $provisioner)
    {
        $data = $request->validate($this->connectionRules());
        $data['use_ssl'] = $request->boolean('use_ssl');
        $router = new MikrotikRouter($data);

        try {
            $facts = $provisioner->testConnection($router);
        } catch (Throwable $e) {
            $request->session()->forget(self::TEST_KEY);

            return response()->json(['message' => HotspotProvisioner::explain($e, $router)], 422);
        }

        $this->rememberTest($request);

        return response()->json($facts);
    }

    /** AJAX: read the real port list from a router before it is saved. Also counts as a passed test. */
    public function detect(Request $request, HotspotProvisioner $provisioner)
    {
        $data = $request->validate($this->connectionRules());
        $data['use_ssl'] = $request->boolean('use_ssl');
        $router = new MikrotikRouter($data);

        try {
            $info = $provisioner->inspect($router);
        } catch (Throwable $e) {
            $request->session()->forget(self::TEST_KEY);

            return response()->json(['message' => 'Could not read the router: '.HotspotProvisioner::explain($e, $router)], 422);
        }

        $this->rememberTest($request);

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
            'login_mode' => ['required', Rule::in(array_keys(MikrotikRouter::LOGIN_MODES))],
            'login_url' => ['exclude_unless:login_mode,custom', 'required', 'url:http,https', 'max:255'],
        ], [
            'name.regex' => 'Use letters, numbers, spaces, dots, dashes or underscores.',
            'ports.required' => 'Choose a router model or read the ports from the router.',
        ]);

        // The connection must have been tested successfully with exactly these details.
        if (! $this->passedTest($request)) {
            throw ValidationException::withMessages([
                'host' => 'Test the API connection and make sure it succeeds before adding the router.',
            ]);
        }

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
                ->withErrors(['host' => 'Could not use this router: '.HotspotProvisioner::explain($e, $router)]);
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

        $request->session()->forget(self::TEST_KEY);
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

    /**
     * A fingerprint of the connection details, keyed with APP_KEY so the
     * password never sits in the session in a reversible form.
     */
    private function fingerprint(Request $request): string
    {
        return hash_hmac('sha256', implode("\n", [
            strtolower(trim((string) $request->input('host'))),
            (int) $request->input('api_port'),
            $request->boolean('use_ssl') ? 'ssl' : 'plain',
            (string) $request->input('username'),
            (string) $request->input('password'),
        ]), (string) config('app.key'));
    }

    private function rememberTest(Request $request): void
    {
        $request->session()->put(self::TEST_KEY, [
            'fingerprint' => $this->fingerprint($request),
            'at' => now()->getTimestamp(),
        ]);
    }

    private function passedTest(Request $request): bool
    {
        $test = $request->session()->get(self::TEST_KEY);

        return is_array($test)
            && now()->getTimestamp() - (int) $test['at'] <= self::TEST_TTL
            && hash_equals((string) $test['fingerprint'], $this->fingerprint($request));
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
