<?php

namespace App\Http\Controllers;

use App\Jobs\ProvisionHotspot;
use App\Models\HotspotNetwork;
use App\Models\MikrotikRouter;
use App\Models\SplashPage;
use App\Services\Mikrotik\HotspotProvisioner;
use App\Services\Mikrotik\SubnetAllocator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    /** Hotspot networks per router. */
    private const MAX_NETWORKS = 8;

    public function index(SubnetAllocator $allocator)
    {
        return view('routers.index', [
            'routers' => MikrotikRouter::query()->with('hotspotNetworks')->orderBy('name')->paginate(50),
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
            'nextHotspot' => $allocator->nextHotspot(self::MAX_NETWORKS, partial: true),
            'sizes' => SubnetAllocator::sizeOptions(),
            'defaultPrefix' => $allocator->defaultPrefix(),
            'plan' => $allocator->summary(),
            'models' => config('mikrotik_models'),
            'vlanDefaults' => config('hotspot.vlans'),
            'designs' => $this->designs(),
            'maxNetworks' => self::MAX_NETWORKS,
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
            'ports.*' => ['required', 'string', function ($attr, $value, $fail) {
                if (! in_array($value, MikrotikRouter::ROLES, true) && ! MikrotikRouter::isAccessRole((string) $value)) {
                    $fail('Choose a role for every port.');
                }
            }],
            'wan_mode' => ['required', Rule::in(['keep', 'dhcp', 'static'])],
            'wan_address' => ['exclude_unless:wan_mode,static', 'required', $this->cidrRule()],
            'wan_gateway' => ['exclude_unless:wan_mode,static', 'required', 'ipv4'],
            ...$this->vlanRules(),
            'mgmt_native' => ['sometimes', 'boolean'],
            ...$this->positionRules(),
            'networks' => ['required', 'array', 'min:1', 'max:'.self::MAX_NETWORKS],
            ...$this->networkRules('networks.*.'),
        ], [
            'name.regex' => 'Use letters, numbers, spaces, dots, dashes or underscores.',
            'ports.required' => 'Choose a router model or read the ports from the router.',
            'networks.required' => 'Add at least one hotspot network.',
            ...$this->positionMessages(),
            ...$this->networkMessages('networks.*.'),
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
        $accessCount = count(array_filter($roles, fn ($r) => MikrotikRouter::isAccessRole($r)));
        if (($count['wan'] ?? 0) !== 1) {
            throw ValidationException::withMessages(['ports' => 'Choose exactly one WAN port.']);
        }
        if (($count['trunk'] ?? 0) + $accessCount < 1) {
            throw ValidationException::withMessages(['ports' => 'Choose at least one trunk or hotspot port.']);
        }

        $networks = $this->newNetworks(array_values($data['networks']), first: true);
        // Typed addresses and user limits are checked now, so mistakes come back with the form.
        $this->assignAddresses($data['networks'], $networks, $allocator, 'networks.');
        $vlans = $this->checkedVlans($data['vlans'], $networks, array_keys($roles));
        $vlans['mgmt']['native'] = $request->boolean('mgmt_native');

        $vlanIds = $networks->pluck('vlan_id')->all();
        foreach ($roles as $port => $role) {
            if (str_starts_with($role, 'access:') && ! in_array((int) substr($role, 7), $vlanIds, true)) {
                throw ValidationException::withMessages(['ports' => "{$port} is set to a hotspot network that is not in the list."]);
            }
        }

        $router = new MikrotikRouter([
            ...Arr::except($data, ['ports', 'vlans', 'mgmt_native', 'networks']),
            'vlans' => $vlans,
            'use_ssl' => $request->boolean('use_ssl'),
            'port_roles' => $roles,
            'wan_interface' => array_search('wan', $roles, true),
        ]);

        // Fail fast: wrong IP, credentials, port names, or a plan that would cut the connection.
        try {
            $router->fill($provisioner->probe($router, $networks));
        } catch (Throwable $e) {
            return back()
                ->withInput($request->except('password'))
                ->withErrors(['host' => 'Could not use this router: '.HotspotProvisioner::explain($e, $router)]);
        }

        try {
            // Lock so two admins adding routers at once never get the same subnets.
            Cache::lock('hotspot:subnet-allocation', 10)->block(5, function () use ($router, $networks, $allocator, $data) {
                DB::transaction(function () use ($router, $networks, $allocator, $data) {
                    $router->fill($allocator->next());
                    $router->status = MikrotikRouter::STATUS_PENDING;
                    $router->save();

                    // Again inside the lock: another admin may have taken an address meanwhile.
                    $this->assignAddresses($data['networks'], $networks, $allocator, 'networks.');
                    $router->hotspotNetworks()->saveMany($networks);
                });
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return back()
                ->withInput($request->except('password'))
                ->withErrors(['networks' => $e->getMessage()]);
        }

        $request->session()->forget(self::TEST_KEY);
        ProvisionHotspot::dispatch($router);

        return redirect()
            ->route('routers.show', $router)
            ->with('status', "{$router->name} added. The configuration is being applied.");
    }

    public function show(MikrotikRouter $router, SubnetAllocator $allocator)
    {
        $router->load('hotspotNetworks.loginPage', 'hotspotNetworks.adPage');

        return view('routers.show', [
            'router' => $router,
            'designs' => $this->designs(),
            'nextHotspot' => $allocator->nextHotspot(1, partial: true)[0] ?? null,
            'sizes' => SubnetAllocator::sizeOptions(),
            'defaultPrefix' => $allocator->defaultPrefix(),
            'maxNetworks' => self::MAX_NETWORKS,
            'vlanDefaults' => config('hotspot.vlans'),
        ]);
    }

    /** Adds a hotspot network to a router (carried on its trunk ports) and applies it. */
    public function addNetwork(Request $request, MikrotikRouter $router, SubnetAllocator $allocator)
    {
        $data = $request->validate($this->networkRules(), $this->networkMessages());
        $existing = $router->hotspotNetworks()->get();

        if ($existing->count() >= self::MAX_NETWORKS) {
            throw ValidationException::withMessages(['network' => 'A router can have up to '.self::MAX_NETWORKS.' hotspot networks.']);
        }
        $network = $this->newNetworks([$data], first: $existing->isEmpty())->first();

        $taken = [
            ...$existing->pluck('vlan_id')->all(),
            ...array_map(fn ($net) => (int) $router->vlan($net)['id'], array_keys(MikrotikRouter::NETWORKS)),
        ];
        if (in_array($network->vlan_id, $taken, true)) {
            throw ValidationException::withMessages(['vlan_id' => "VLAN {$network->vlan_id} is already used on this router."]);
        }
        $names = [
            ...$existing->pluck('interface')->all(),
            ...array_map(fn ($net) => $router->vlan($net)['name'], array_keys(MikrotikRouter::NETWORKS)),
            ...array_keys($router->port_roles ?? []),
            HotspotProvisioner::TRUNK, HotspotProvisioner::LAN_BRIDGE,
        ];
        if (in_array($network->interface, $names, true)) {
            throw ValidationException::withMessages(['interface' => "{$network->interface} is already an interface on this router."]);
        }

        $this->assignAddresses([$data], collect([$network]), $allocator);
        try {
            Cache::lock('hotspot:subnet-allocation', 10)->block(5, function () use ($router, $network, $allocator, $data) {
                $this->assignAddresses([$data], collect([$network]), $allocator);
                $router->hotspotNetworks()->save($network);
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(['network' => $e->getMessage()]);
        }

        $router->forceFill(['status' => MikrotikRouter::STATUS_PENDING, 'last_error' => null])->save();
        ProvisionHotspot::dispatch($router);

        return redirect()->route('routers.show', $router)
            ->with('status', "{$network->name} added on VLAN {$network->vlan_id} ({$network->subnet}). The configuration is being applied.");
    }

    /**
     * Renames a network, changes its pages, address or user limit. Designs apply on
     * the next page load; a new login page type, URL, address or limit is re-applied to the router.
     */
    public function updateNetwork(Request $request, HotspotNetwork $network, SubnetAllocator $allocator)
    {
        $rules = Arr::only($this->networkRules(), ['name', 'login_mode', 'login_url', 'login_page_id', 'ad_page_id', 'max_users']);
        $rules['subnet'] = ['required', 'string', 'max:18'];
        $data = $request->validate($rules, $this->networkMessages());
        $data['login_url'] = $data['login_mode'] === 'custom' ? ($data['login_url'] ?? null) : null;

        $network->fill(Arr::except($data, ['subnet', 'max_users']));
        Cache::lock('hotspot:subnet-allocation', 10)->block(5, function () use ($network, $allocator, $data) {
            $this->assignAddresses([$data], collect([$network]), $allocator, exceptNetworkId: $network->id);
        });
        $reapply = $network->isDirty(['login_mode', 'login_url', 'subnet', 'max_users']);
        $network->save();

        $router = $network->router;
        if ($reapply) {
            $router->forceFill(['status' => MikrotikRouter::STATUS_PENDING, 'last_error' => null])->save();
            ProvisionHotspot::dispatch($router);
        }

        return redirect()->route('routers.show', $router)->with('status', $reapply
            ? "{$network->name} saved. Updating the router."
            : "{$network->name} saved. Phones see the change on their next visit.");
    }

    /** Map position, so lines from its switches and access points can be drawn on the dashboard. */
    public function updatePosition(Request $request, MikrotikRouter $router)
    {
        $router->update($request->validate($this->positionRules(), $this->positionMessages()));

        return redirect()->route('routers.show', $router)->with('status', $router->latitude !== null
            ? "{$router->name} is on the dashboard map."
            : "{$router->name} was taken off the dashboard map.");
    }

    /** Optional, but both or neither. */
    private function positionRules(): array
    {
        return [
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
        ];
    }

    private function positionMessages(): array
    {
        return [
            'latitude.required_with' => 'Enter both latitude and longitude, or leave both empty.',
            'longitude.required_with' => 'Enter both latitude and longitude, or leave both empty.',
        ];
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

    /** Rules for one hotspot network; $prefix is "networks.*." inside the Add router form. */
    private function networkRules(string $prefix = ''): array
    {
        $design = ['nullable', 'integer', Rule::exists('splash_pages', 'id')];

        return [
            "{$prefix}name" => ['required', 'string', 'max:60'],
            "{$prefix}vlan_id" => ['required', 'integer', 'between:2,4094'],
            "{$prefix}interface" => ['required', 'string', 'regex:/^[A-Za-z0-9._-]{1,32}$/'],
            "{$prefix}login_mode" => ['required', Rule::in(array_keys(HotspotNetwork::LOGIN_MODES))],
            "{$prefix}login_url" => ['nullable', "required_if:{$prefix}login_mode,custom", 'url:http,https', 'max:255'],
            "{$prefix}login_page_id" => $design,
            "{$prefix}ad_page_id" => $design,
            // Empty subnet = next free one of this size from the address plan
            "{$prefix}subnet" => ['nullable', 'string', 'max:18'],
            "{$prefix}prefix" => ['nullable', 'integer', 'between:'.SubnetAllocator::MIN_PREFIX.','.SubnetAllocator::MAX_PREFIX],
            // Empty = no limit
            "{$prefix}max_users" => ['nullable', 'integer', 'min:'.SubnetAllocator::MIN_USER_LIMIT],
        ];
    }

    private function networkMessages(string $prefix = ''): array
    {
        return [
            "{$prefix}name.required" => 'Give each hotspot network a name, e.g. Public WiFi.',
            "{$prefix}interface.regex" => 'Interface names use letters, numbers, dots, dashes or underscores (up to 32).',
            "{$prefix}login_url.required_if" => 'Enter the external login page URL.',
            "{$prefix}max_users.min" => 'The user limit must be at least '.SubnetAllocator::MIN_USER_LIMIT.', or empty for no limit.',
        ];
    }

    /**
     * Gives each network its subnet, gateway and DHCP range: the address typed in
     * (checked for overlaps) or the next free one of the chosen size, with the
     * DHCP range cut to the user limit. Errors point at the row's field.
     *
     * @param  array<int|string, array>  $rows  validated input, keyed as in the form
     * @param  Collection<int, HotspotNetwork>  $networks  same order as $rows
     */
    private function assignAddresses(array $rows, Collection $networks, SubnetAllocator $allocator, string $errorPrefix = '', ?int $exceptNetworkId = null): void
    {
        $taken = [];
        $keys = array_keys($rows);

        foreach (array_values($rows) as $i => $row) {
            $field = fn (string $name) => $errorPrefix === '' ? $name : "{$errorPrefix}{$keys[$i]}.{$name}";
            $maxUsers = isset($row['max_users']) && $row['max_users'] !== '' ? (int) $row['max_users'] : null;

            try {
                $subnet = trim((string) ($row['subnet'] ?? '')) !== ''
                    ? $allocator->checkHotspotSubnet($row['subnet'], $exceptNetworkId, $taken)
                    : $allocator->nextHotspotSubnet(isset($row['prefix']) ? (int) $row['prefix'] : null, $taken);
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages([$field('subnet') => $e->getMessage()]);
            }
            try {
                $allocator->checkUserLimit($maxUsers, $subnet);
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages([$field('max_users') => $e->getMessage()]);
            }

            $taken[] = $subnet;
            $networks[$i]->fill($allocator->hotspotAddressing($subnet, $maxUsers));
        }
    }

    /** @return Collection<int, HotspotNetwork> unsaved networks; the first on a router keeps the original RouterOS names */
    private function newNetworks(array $rows, bool $first): Collection
    {
        return collect($rows)->values()->map(fn (array $n, int $i) => new HotspotNetwork([
            'name' => trim($n['name']),
            'key' => HotspotNetwork::keyFor((int) $n['vlan_id'], $first && $i === 0),
            'vlan_id' => (int) $n['vlan_id'],
            'interface' => $n['interface'],
            'login_mode' => $n['login_mode'],
            'login_url' => $n['login_mode'] === 'custom' ? $n['login_url'] : null,
            'login_page_id' => $n['login_page_id'] ?? null,
            'ad_page_id' => $n['ad_page_id'] ?? null,
        ]));
    }

    /** Designs to choose from; makes sure the default exists. */
    private function designs(): Collection
    {
        SplashPage::current();

        return SplashPage::query()->orderBy('id')->get(['id', 'name']);
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

    /**
     * VLAN IDs and interface names (management, test and every hotspot network)
     * must be unique and must not clash with ports or bridges.
     */
    private function checkedVlans(array $input, Collection $networks, array $portNames): array
    {
        $vlans = [];
        foreach (array_keys(MikrotikRouter::NETWORKS) as $net) {
            $vlans[$net] = ['id' => (int) $input[$net]['id'], 'name' => $input[$net]['name']];
        }

        $ids = [...array_column($vlans, 'id'), ...$networks->pluck('vlan_id')->all()];
        if (count(array_unique($ids)) !== count($ids)) {
            throw ValidationException::withMessages(['vlans' => 'Each network needs its own VLAN ID.']);
        }

        $names = [...array_column($vlans, 'name'), ...$networks->pluck('interface')->all()];
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
