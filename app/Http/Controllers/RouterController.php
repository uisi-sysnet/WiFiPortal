<?php

namespace App\Http\Controllers;

use App\Jobs\ProvisionHotspot;
use App\Models\MikrotikRouter;
use App\Services\Mikrotik\HotspotProvisioner;
use App\Services\Mikrotik\SubnetAllocator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        } catch (RuntimeException $e) {
            $next = null;
        }

        return view('routers.create', [
            'next' => $next,
            'plan' => $allocator->summary(),
        ]);
    }

    public function store(Request $request, HotspotProvisioner $provisioner, SubnetAllocator $allocator)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._ -]+$/', 'unique:mikrotik_routers,name'],
            'location' => ['nullable', 'string', 'max:255'],
            'host' => ['required', 'string', 'max:255'],
            'api_port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:128'],
            'wan_interface' => ['required', 'string', 'max:64'],
            'hotspot_interface' => ['required', 'string', 'max:64', 'different:wan_interface'],
        ], [
            'name.regex' => 'Use letters, numbers, spaces, dots, dashes or underscores.',
            'hotspot_interface.different' => 'The hotspot interface must not be the WAN interface.',
        ]);
        $data['use_ssl'] = $request->boolean('use_ssl');

        $router = new MikrotikRouter($data);

        // Fail fast on wrong IP, credentials or interface names.
        try {
            $router->fill($provisioner->probe($router));
        } catch (Throwable $e) {
            return back()
                ->withInput($request->except('password'))
                ->withErrors(['host' => 'Could not use this router: '.$e->getMessage()]);
        }

        try {
            // Lock so two admins adding routers at once never get the same subnet.
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
            ->with('status', "{$router->name} added. The hotspot configuration is being applied.");
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
        $subnet = $router->subnet;
        $router->delete();

        return redirect()
            ->route('routers.index')
            ->with('status', "{$name} removed and {$subnet} returned to the address plan. The router itself was not changed.");
    }
}
