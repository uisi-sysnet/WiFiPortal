<?php

namespace App\Services\Reports;

use App\Models\CapacityAlert;
use App\Models\MikrotikRouter;
use App\Models\NetworkDevice;
use App\Models\SystemEvent;
use App\Services\Dashboard\ApClientReport;
use App\Services\Dashboard\UserHistory;
use App\Services\Portal\RegistrationStats;
use App\Services\Radius\RadiusLog;
use Illuminate\Support\Carbon;

/**
 * The state of the network for the scheduled report (email body and Telegram):
 * what is down now, what went down during the period, users and registrations,
 * clients per access point, capacity, and RADIUS logins.
 */
class NetworkSummary
{
    public function __construct(
        private RegistrationStats $stats,
        private ApClientReport $aps,
        private UserHistory $history,
        private RadiusLog $radius,
    ) {
    }

    /** @param  string  $range  day | week | month */
    public function build(string $range): array
    {
        $tz = (string) config('hotspot.history.timezone');
        $window = $this->aps->window($range);
        $from = $window['from'] ?? now()->subDay();

        $routers = MikrotikRouter::query()->orderBy('name')->get(['id', 'name', 'location', 'link_status', 'last_seen_at', 'active_users']);
        $devices = NetworkDevice::query()->with('barangay:id,name')->orderBy('name')
            ->get(['id', 'type', 'name', 'status', 'last_seen_at', 'barangay_id', 'location']);
        $down = fn ($list) => $list->where('status', 'offline')->map(fn ($d) => [
            'name' => $d->name,
            'where' => trim(implode(', ', array_filter([$d->barangay?->name, $d->location]))),
            'since' => $d->last_seen_at?->copy()->setTimezone($tz),
        ])->values()->all();

        $events = SystemEvent::query()->where('created_at', '>=', $from)->whereIn('kind', ['router', 'ap', 'switch', 'capacity']);
        $apRows = $this->aps->perAp($window);
        $apTotals = $this->aps->totals($window, $apRows);
        $users = $this->history->series($range);
        $reg = $this->stats->summary($range, []);

        return [
            'range' => $range,
            'title' => $window['title'],
            'from' => $from->copy()->setTimezone($tz),
            'to' => now($tz),
            'routers' => [
                'total' => $routers->count(),
                'online' => $routers->where('link_status', 'online')->count(),
                'down' => $routers->where('link_status', 'offline')->map(fn ($r) => [
                    'name' => $r->name, 'where' => (string) $r->location, 'since' => $r->last_seen_at?->copy()->setTimezone($tz),
                ])->values()->all(),
            ],
            'aps' => ['total' => $devices->where('type', 'ap')->count(), 'online' => $devices->where('type', 'ap')->where('status', 'online')->count(),
                'down' => $down($devices->where('type', 'ap'))],
            'switches' => ['total' => $devices->where('type', 'switch')->count(), 'online' => $devices->where('type', 'switch')->where('status', 'online')->count(),
                'down' => $down($devices->where('type', 'switch'))],
            'outages' => (clone $events)->where('level', 'down')->count(),
            'recoveries' => (clone $events)->where('level', 'ok')->count(),
            'recent' => (clone $events)->whereIn('level', ['down', 'warn'])->latest('id')->limit(10)->get()
                ->map(fn ($e) => ['level' => $e->level, 'title' => $e->title, 'at' => $e->created_at->copy()->setTimezone($tz)])->all(),
            'capacity' => CapacityAlert::query()->open()->worstFirst()->with(['router:id,name', 'network:id,name'])->limit(10)->get()
                ->map(fn ($a) => ['level' => $a->level, 'title' => $a->title(), 'message' => $a->message])->all(),
            'users' => [
                'now' => (int) $routers->where('link_status', 'online')->sum('active_users'),
                'peak' => $users['peak'] ? ['value' => $users['peak']['value'], 'at' => Carbon::parse($users['peak']['at'])->setTimezone($tz)] : null,
            ],
            'registrations' => [
                'total' => $reg['accumulated'], 'unique' => $reg['unique'], 'repeated' => $reg['repeated'],
                'residents' => $reg['breakdown']['residents'], 'visitors' => $reg['breakdown']['visitors'], 'students' => $reg['breakdown']['students'],
            ],
            'clients' => [
                'average' => $apTotals['average'],
                'busiest' => $apTotals['busiest'] ? ['clients' => $apTotals['busiest']['clients'], 'at' => $apTotals['busiest']['at']->copy()->setTimezone($tz)] : null,
                'top' => array_map(fn ($r) => ['name' => $r['name'], 'barangay' => $r['barangay'], 'average' => $r['average']],
                    array_slice(array_values(array_filter($apRows, fn ($r) => $r['average'] !== null)), 0, 5)),
                'barangays' => array_slice(array_values(array_filter($this->aps->perBarangay($apRows), fn ($b) => $b['value'] > 0)), 0, 5),
            ],
            'radius' => $this->radius->available() ? $this->radius->counts($from) : null,
        ];
    }

    /** Short text for Telegram (HTML): the headline numbers. */
    public function telegramText(array $s): string
    {
        $down = count($s['routers']['down']) + count($s['aps']['down']) + count($s['switches']['down']);
        $lines = [
            '<b>Network report</b>: '.e($s['title']),
            ($down ? '🔴' : '🟢').' Routers '.$s['routers']['online'].'/'.$s['routers']['total'].' online, access points '
                .$s['aps']['online'].'/'.$s['aps']['total'].', switches '.$s['switches']['online'].'/'.$s['switches']['total'],
            '👥 Users online now: '.number_format($s['users']['now'])
                .($s['users']['peak'] ? ', peak '.number_format($s['users']['peak']['value']).' at '.$s['users']['peak']['at']->format('M j H:i') : ''),
            '📝 Registrations: '.number_format($s['registrations']['total']).' ('.number_format($s['registrations']['unique']).' unique)',
            '⚠️ Outages: '.$s['outages'].', recovered: '.$s['recoveries'].', capacity alerts open: '.count($s['capacity']),
        ];
        if ($s['radius']) {
            $lines[] = '🔐 RADIUS logins accepted: '.number_format($s['radius']['accepted']).', rejected: '.number_format($s['radius']['rejected']);
        }

        return implode("\n", $lines);
    }
}
