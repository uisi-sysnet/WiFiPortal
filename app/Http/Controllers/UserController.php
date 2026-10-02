<?php

namespace App\Http\Controllers;

use App\Models\HotspotGuest;
use App\Models\HotspotNetwork;
use App\Services\Portal\RegistrationStats;
use App\Support\PieChart;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Users: charts and totals of everyone who registered on a captive portal
 * (all time, or by year, month, week or day), then the list of registrations,
 * newest first, with search and filters. The numbers export as a PDF report.
 */
class UserController extends Controller
{
    public const PERIODS = ['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'all' => 'All time'];

    public function index(Request $request, RegistrationStats $stats)
    {
        $f = $this->filters($request);
        $base = $this->baseQuery($f);

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
        ]);
    }

    /**
     * One-page report of the numbers (no list of people): totals, users per period,
     * the unique/repeated pie and a breakdown, for All, Year, Month, Week, Day or a
     * date range, following the page's network and visitor/resident/student filters.
     * format=pdf downloads a PDF; format=png returns the page for the browser to capture.
     */
    public function report(Request $request, RegistrationStats $stats)
    {
        $tz = (string) config('hotspot.history.timezone');
        $r = $request->validate([
            'range' => ['required', 'in:'.implode(',', [...array_keys(RegistrationStats::RANGES), 'custom'])],
            'from' => ['exclude_unless:range,custom', 'required', 'date_format:Y-m-d', 'before_or_equal:to'],
            'to' => ['exclude_unless:range,custom', 'required', 'date_format:Y-m-d', 'before_or_equal:'.now($tz)->toDateString()],
            'type' => ['nullable', 'in:visitor,resident,student'],
            'network' => ['nullable', 'integer'],
            'format' => ['nullable', 'in:pdf,png'],
        ], [
            'from.before_or_equal' => 'The start date must be on or before the end date.',
            'to.before_or_equal' => 'The end date cannot be in the future.',
        ], ['from' => 'start date', 'to' => 'end date']);

        $summary = $stats->summary(
            $r['range'],
            ['network' => $r['network'] ?? null, 'type' => $r['type'] ?? null],
            $r['range'] === 'custom' ? [$r['from'], $r['to']] : null,
        );

        // Busiest hotspot networks, by name ("Router / Network")
        $counts = $summary['breakdown']['networks'];
        $names = HotspotNetwork::with('router:id,name')->whereIn('id', array_keys($counts))->get()
            ->mapWithKeys(fn ($n) => [$n->id => $n->router?->name.' / '.$n->name]);
        $topNetworks = collect($counts)->take(5)
            ->map(fn ($count, $id) => ['name' => $names[$id] ?? 'Removed network', 'count' => $count])->values()->all();

        $generated = now($tz);
        $data = [
            'stats' => $summary,
            'filters' => $r,
            'network' => isset($r['network']) ? HotspotNetwork::with('router:id,name')->find($r['network']) : null,
            'pie' => PieChart::dataUri([[$summary['unique'], '#0e670d'], [$summary['repeated'], '#7FB77E']], 170),
            'generated' => $generated,
            'by' => $request->user()?->name,
            'topNetworks' => $topNetworks,
            'otherNetworks' => max(0, count($counts) - count($topNetworks)),
            // Printed on every page, so a printout can be matched to when and how it was made
            'reference' => 'UR-'.$generated->format('Ymd-His').'-'.strtoupper(substr(md5(json_encode($r).$request->user()?->id), 0, 4)),
        ];

        // Image: the same page as HTML; the Users page turns it into a PNG in the browser
        if (($r['format'] ?? 'pdf') === 'png') {
            return response()->view('users.report', $data + ['image' => true])->header('Cache-Control', 'no-store');
        }

        $pdf = Pdf::loadView('users.report', $data)->setPaper('a4', 'landscape');

        // Page numbers on every page
        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $canvas->page_text($canvas->get_width() - 100, $canvas->get_height() - 30, 'Page {PAGE_NUM} of {PAGE_COUNT}', null, 7.5, [0.36, 0.42, 0.40]);

        $name = $r['range'] === 'custom' ? $r['from'].'-to-'.$r['to'] : $r['range'];

        return $pdf->download('users-report-'.$name.'-'.now($tz)->format('Y-m-d-Hi').'.pdf');
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
