<?php

namespace App\Services\Reports;

use App\Models\HotspotNetwork;
use App\Services\Dashboard\ApClientReport;
use App\Services\Portal\RegistrationStats;
use App\Support\PieChart;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * The Users report (numbers only, no personal details): built for the Users page
 * export and for the scheduled email. One A4 landscape page, plus an optional
 * page of clients per access point.
 */
class UsersReport
{
    public function __construct(private RegistrationStats $stats, private ApClientReport $apReport)
    {
    }

    /**
     * @param  array{range:string, from?:string, to?:string, type?:?string, network?:?int}  $r
     */
    public function data(array $r, bool $withAps, ?string $by, ?int $byId = null): array
    {
        $tz = (string) config('hotspot.history.timezone');
        $dates = $r['range'] === 'custom' ? [$r['from'], $r['to']] : null;

        $summary = $this->stats->summary($r['range'], ['network' => $r['network'] ?? null, 'type' => $r['type'] ?? null], $dates);

        // Busiest hotspot networks, by name ("Router / Network")
        $counts = $summary['breakdown']['networks'];
        $names = HotspotNetwork::with('router:id,name')->whereIn('id', array_keys($counts))->get()
            ->mapWithKeys(fn ($n) => [$n->id => $n->router?->name.' / '.$n->name]);
        $topNetworks = collect($counts)->take(5)
            ->map(fn ($count, $id) => ['name' => $names[$id] ?? 'Removed network', 'count' => $count])->values()->all();

        // Optional second page: clients per access point
        $apData = [];
        if ($withAps) {
            $window = $this->apReport->window($r['range'], $dates);
            $rows = $this->apReport->perAp($window);
            $apData = ['ap' => $rows, 'apTotals' => $this->apReport->totals($window, $rows), 'apWindow' => $window];
        }

        $generated = now($tz);

        return $apData + [
            'stats' => $summary,
            'filters' => $r,
            'network' => isset($r['network']) ? HotspotNetwork::with('router:id,name')->find($r['network']) : null,
            'pie' => PieChart::dataUri([[$summary['unique'], '#0e670d'], [$summary['repeated'], '#7FB77E']], 170),
            'generated' => $generated,
            'by' => $by,
            'topNetworks' => $topNetworks,
            'otherNetworks' => max(0, count($counts) - count($topNetworks)),
            // Printed on every page, so a printout can be matched to when and how it was made
            'reference' => 'UR-'.$generated->format('Ymd-His').'-'.strtoupper(substr(md5(json_encode($r).$byId.$by), 0, 4)),
        ];
    }

    /** The PDF, with page numbers on every page. */
    public function pdf(array $data): \Barryvdh\DomPDF\PDF
    {
        $pdf = Pdf::loadView('users.report', $data)->setPaper('a4', 'landscape');
        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $canvas->page_text($canvas->get_width() - 100, $canvas->get_height() - 30, 'Page {PAGE_NUM} of {PAGE_COUNT}', null, 7.5, [0.36, 0.42, 0.40]);

        return $pdf;
    }

    public function filename(array $r): string
    {
        $name = $r['range'] === 'custom' ? $r['from'].'-to-'.$r['to'] : $r['range'];

        return 'users-report-'.$name.'-'.now((string) config('hotspot.history.timezone'))->format('Y-m-d-Hi').'.pdf';
    }
}
