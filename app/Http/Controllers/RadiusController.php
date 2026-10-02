<?php

namespace App\Http\Controllers;

use App\Models\MikrotikRouter;
use App\Services\Radius\RadiusLog;
use Illuminate\Http\Request;

/**
 * RADIUS: troubleshooting logins. Overview (is it working?), login attempts
 * (accepted / rejected and the likely reason), sessions (online now and past,
 * with time and data used), and a look-up of one phone or login.
 */
class RadiusController extends Controller
{
    public const VIEWS = ['overview' => 'Overview', 'attempts' => 'Login attempts', 'sessions' => 'Sessions', 'lookup' => 'Look up a phone'];

    public function index(Request $request, RadiusLog $radius)
    {
        $f = $request->validate([
            'view' => ['nullable', 'in:'.implode(',', array_keys(self::VIEWS))],
            'q' => ['nullable', 'string', 'max:64'],
            'result' => ['nullable', 'in:accepted,rejected'],
            'status' => ['nullable', 'in:online,all'],
            'router' => ['nullable', 'integer'],
        ]);
        $view = $f['view'] ?? 'overview';

        $data = ['view' => $view, 'filters' => $f, 'views' => self::VIEWS, 'configured' => $radius->configured(), 'available' => $radius->available()];
        if (! $data['available']) {
            return view('radius.index', $data);
        }

        return view('radius.index', $data + match ($view) {
            'attempts' => ['attempts' => $radius->attempts($f)],
            'sessions' => ['sessions' => $radius->sessions($f + ['status' => 'online']), 'routers' => MikrotikRouter::orderBy('name')->get(['id', 'name'])],
            'lookup' => ['lookup' => filled($f['q'] ?? null) ? $radius->lookup($f['q']) : null],
            default => [
                'health' => $radius->health(),
                'recentRejects' => $radius->attempts(['result' => 'rejected'], 8)->items(),
                'online' => $radius->sessions(['status' => 'online'], 8),
            ],
        });
    }
}
