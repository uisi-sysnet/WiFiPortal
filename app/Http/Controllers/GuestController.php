<?php

namespace App\Http\Controllers;

use App\Models\HotspotGuest;
use Illuminate\Http\Request;

class GuestController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));
        $status = $request->query('status');

        $guests = HotspotGuest::query()
            ->with(['router:id,name', 'network:id,name'])
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $pattern = '%'.mb_strtolower($search).'%';
                $query->whereRaw('LOWER(name) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(contact) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(username) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(mac) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(ip) LIKE ?', [$pattern]);
            }))
            ->when($status === 'connected', fn ($query) => $query->whereNotNull('connected_at')->whereNull('revoked_at'))
            ->when($status === 'revoked', fn ($query) => $query->whereNotNull('revoked_at'))
            ->when($status === 'expired', fn ($query) => $query->whereNotNull('expires_at')->where('expires_at', '<=', now())->whereNull('revoked_at'))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('guests.index', [
            'guests' => $guests,
            'search' => $search,
            'status' => $status,
            'counts' => [
                'all' => HotspotGuest::count(),
                'connected' => HotspotGuest::whereNotNull('connected_at')->whereNull('revoked_at')->count(),
                'revoked' => HotspotGuest::whereNotNull('revoked_at')->count(),
            ],
        ]);
    }
}
