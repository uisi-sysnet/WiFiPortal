<?php

namespace App\Http\Controllers;

use App\Models\HotspotGuest;
use App\Models\Barangay;
use App\Models\HotspotNetwork;
use App\Services\Dashboard\ApClientReport;
use App\Services\Portal\RegistrationStats;
use App\Services\Reports\UsersReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Users: charts and totals of everyone who registered on a captive portal
 * (all time, or by year, month, week or day), then the list of registrations,
 * newest first, with search and filters. The numbers export as a PDF report.
 * Also clients connected per access point (logged by devices:poll), as a table,
 * a CSV and a heat map.
 */
class UserController extends Controller
{
    public const PERIODS = ['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'all' => 'All time'];

    public function index(Request $request, RegistrationStats $stats, ApClientReport $apReport)
    {
        $f = $this->filters($request);
        $base = $this->baseQuery($f);
        $apWindow = $apReport->window($f['range'] ?? 'month');
        $ap = $apReport->perAp($apWindow);

        $users = $this->listQuery($base, $f)
            ->paginate(50)
            ->withQueryString();

        return view('users.index', [
            'users' => $users,
            'filters' => $f,
            'counts' => $this->statusCounts($base),
            'today' => HotspotGuest::query()->where('created_at', '>=', today())->count(),
            'networks' => $this->networks(),
            'periods' => self::PERIODS,
            'stats' => $this->stats($stats, $f),
            'ranges' => RegistrationStats::RANGES,
            'ap' => $ap,
            'apTotals' => $apReport->totals($apWindow, $ap),
            'apWindow' => $apWindow,
        ]);
    }

    /**
     * Heat map of clients per access point: where people use the WiFi. Average or
     * peak over a period, or the live count. Access points need a map position.
     */
    public function heatmap(Request $request, ApClientReport $apReport)
    {
        $r = $this->apFilters($request);
        $window = $apReport->window($r['range'], $r['range'] === 'custom' ? [$r['from'], $r['to']] : null);
        $rows = $apReport->perAp($window, $r['barangay'] ?? null);

        return view('users.heatmap', [
            'filters' => $r,
            'window' => $window,
            'points' => $apReport->heatPoints($rows, $r['metric']),
            'barangays' => $apReport->perBarangay($rows, $r['metric']),
            'totals' => $apReport->totals($window, $rows),
            'rows' => $rows,
            'barangayList' => Barangay::query()->orderBy('name')->get(['id', 'name']),
            'ranges' => RegistrationStats::RANGES,
            'mapCenter' => app(DashboardController::class)->center(),
        ]);
    }

    /** Clients per access point for the period, as a spreadsheet. */
    public function apClientsCsv(Request $request, ApClientReport $apReport)
    {
        $r = $this->apFilters($request);
        $window = $apReport->window($r['range'], $r['range'] === 'custom' ? [$r['from'], $r['to']] : null);
        $rows = $apReport->perAp($window, $r['barangay'] ?? null);
        $tz = (string) config('hotspot.history.timezone');

        return response()->streamDownload(function () use ($rows, $tz, $window) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Clients per access point: '.$window['title']]);
            fputcsv($out, ['Access point', 'Barangay', 'Location', 'Status', 'Clients now', 'Average clients', 'Peak clients', 'Peak at ('.$tz.')', 'Hours logged', 'Share of clients (%)']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['name'], $row['barangay'], $row['landmark'], $row['status'], $row['now'], $row['average'], $row['peak'],
                    $row['peak_at']?->copy()->setTimezone($tz)->format('Y-m-d H:00'), $row['hours'], round($row['share'], 1),
                ]);
            }
            fclose($out);
        }, 'clients-per-access-point-'.($r['range'] === 'custom' ? $r['from'].'-to-'.$r['to'] : $r['range']).'-'.now($tz)->format('Y-m-d-Hi').'.csv',
            ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    private function apFilters(Request $request): array
    {
        $tz = (string) config('hotspot.history.timezone');
        $r = $request->validate([
            'range' => ['nullable', 'in:'.implode(',', [...array_keys(RegistrationStats::RANGES), 'custom'])],
            'from' => ['exclude_unless:range,custom', 'required', 'date_format:Y-m-d', 'before_or_equal:to'],
            'to' => ['exclude_unless:range,custom', 'required', 'date_format:Y-m-d', 'before_or_equal:'.now($tz)->toDateString()],
            'metric' => ['nullable', 'in:average,peak,now'],
            'barangay' => ['nullable', 'integer'],
        ], [
            'from.before_or_equal' => 'The start date must be on or before the end date.',
            'to.before_or_equal' => 'The end date cannot be in the future.',
        ], ['from' => 'start date', 'to' => 'end date']);

        return ['range' => $r['range'] ?? 'week', 'metric' => $r['metric'] ?? 'average'] + $r;
    }

    /**
     * One-page report of the numbers (no list of people): totals, users per period,
     * the unique/repeated pie and a breakdown, for All, Year, Month, Week, Day or a
     * date range, following the page's network and visitor/resident/student filters.
     * format=pdf downloads a PDF; format=png returns the page for the browser to capture.
     */
    public function report(Request $request, UsersReport $reports)
    {
        $tz = (string) config('hotspot.history.timezone');
        $r = $request->validate([
            'range' => ['required', 'in:'.implode(',', [...array_keys(RegistrationStats::RANGES), 'custom'])],
            'from' => ['exclude_unless:range,custom', 'required', 'date_format:Y-m-d', 'before_or_equal:to'],
            'to' => ['exclude_unless:range,custom', 'required', 'date_format:Y-m-d', 'before_or_equal:'.now($tz)->toDateString()],
            'type' => ['nullable', 'in:visitor,resident,student'],
            'network' => ['nullable', 'integer'],
            'format' => ['nullable', 'in:pdf,png'],
            'aps' => ['nullable', 'boolean'],
        ], [
            'from.before_or_equal' => 'The start date must be on or before the end date.',
            'to.before_or_equal' => 'The end date cannot be in the future.',
        ], ['from' => 'start date', 'to' => 'end date']);

        $data = $reports->data($r, $request->boolean('aps'), $request->user()?->signature(), $request->user()?->id);

        // Image: the same page as HTML; the Users page turns it into a PNG in the browser
        if (($r['format'] ?? 'pdf') === 'png') {
            return response()->view('users.report', $data + ['image' => true])->header('Cache-Control', 'no-store');
        }

        return $reports->pdf($data)->download($reports->filename($r));
    }

    private function filters(Request $request): array
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,waiting,expired'],
            'type' => ['nullable', 'in:visitor,resident,student'],
            'network' => ['nullable', 'integer'],
            'period' => ['nullable', 'in:'.implode(',', array_keys(self::PERIODS))],
            'range' => ['nullable', 'in:'.implode(',', array_keys(RegistrationStats::RANGES))],
        ]);

        return $f + ['period' => 'all'];
    }

    /** Everything but the status filter, so the status counts follow the other filters. */
    private function baseQuery(array $f): Builder
    {
        $period = $f['period'];

        return HotspotGuest::query()
            ->when($f['q'] ?? null, function ($q, $term) {
                $like = '%'.mb_strtolower(trim($term)).'%';
                // Mobiles are stored as +639XXXXXXXXX; people type 0917...
                $digits = preg_replace('/\D/', '', $term);
                $mobile = str_starts_with($digits, '09') ? '%63'.substr($digits, 1).'%' : null;
                $q->where(fn ($w) => $w
                    ->whereRaw('lower(name) like ?', [$like])
                    ->orWhereRaw('lower(contact) like ?', [$like])
                    ->orWhereRaw('lower(mac) like ?', [$like])
                    ->orWhereRaw('lower(username) like ?', [$like])
                    ->orWhereRaw('lower(citizen_number) like ?', [$like])
                    ->orWhereRaw('lower(student_number) like ?', [$like])
                    ->orWhereRaw('lower(school) like ?', [$like])
                    ->orWhere('ip', 'like', $like)
                    ->when($mobile, fn ($m) => $m->orWhere('contact', 'like', $mobile)));
            })
            ->when($f['type'] ?? null, fn ($q, $type) => $q->where('category', $type))
            ->when($f['network'] ?? null, fn ($q, $id) => $q->where('hotspot_network_id', $id))
            ->when($period !== 'all', fn ($q) => $q->where('created_at', '>=', match ($period) {
                'today' => today(), '7d' => now()->subDays(7), default => now()->subDays(30),
            }));
    }

    private function listQuery(Builder $base, array $f): Builder
    {
        return (clone $base)
            ->when($f['status'] ?? null, fn ($q, $state) => $q->inState($state))
            ->with(['router:id,name', 'network:id,name'])
            ->latest()->latest('id');
    }

    private function statusCounts(Builder $base): array
    {
        return [
            'active' => (clone $base)->inState('active')->count(),
            'waiting' => (clone $base)->inState('waiting')->count(),
            'expired' => (clone $base)->inState('expired')->count(),
        ];
    }

    /** Charts and totals follow the network and visitor/resident/student filters. */
    private function stats(RegistrationStats $stats, array $f): array
    {
        return $stats->summary($f['range'] ?? 'month', ['network' => $f['network'] ?? null, 'type' => $f['type'] ?? null]);
    }

    private function networks()
    {
        return HotspotNetwork::query()->with('router:id,name')->orderBy('mikrotik_router_id')->orderBy('name')
            ->get(['id', 'name', 'mikrotik_router_id']);
    }
}
