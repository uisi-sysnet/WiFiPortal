{{--
  Users page: clients connected per access point over the period chosen above.
  Needs: $ap (perAp rows), $apTotals, $apWindow, $filters.
--}}
@php
  $tz = config('hotspot.history.timezone');
  $num = fn ($v) => $v === null ? null : ($v >= 10 || floor($v) == $v ? number_format($v) : number_format($v, 1));
  $maxAvg = max(1, collect($ap)->max('average') ?? 1);
  $apQuery = array_filter(['range' => $filters['range'] ?? 'month']);
@endphp
<style>
.ap-sec{margin-bottom:22px}
.ap-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:14px}
.ap-cards .t-num{font-size:1.6rem}
.ap-tools{display:flex;flex-wrap:wrap;gap:8px}
.ap-wrap{max-height:520px;overflow:auto}
table.ap-table{width:100%;min-width:760px;border-collapse:collapse;font-size:.82rem}
.ap-table th,.ap-table td{padding:7px 10px;border-bottom:1px solid color-mix(in srgb,#0e670d 25%,transparent);text-align:left;vertical-align:top}
.ap-table th{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-2);background:#FCFDFC;position:sticky;top:0;z-index:1}
.ap-table td.n,.ap-table th.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.ap-table td small{display:block;color:var(--ink-2);font-size:.72rem;margin-top:1px}
.ap-table tbody tr:hover{background:var(--hover)}
.ap-share{display:flex;align-items:center;gap:8px;min-width:160px}
.ap-share .bar{flex:1;height:7px;border-radius:4px;background:#EEF3EF;overflow:hidden}
.ap-share .bar span{display:block;height:100%;background:#0e670d}
.ap-share b{min-width:46px;text-align:right;font-weight:600;font-variant-numeric:tabular-nums}
.ap-dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:6px;vertical-align:1px;background:#A7B4AD}
.ap-dot.online{background:#0e670d}.ap-dot.offline{background:var(--fail)}
@media (max-width:1100px){.ap-cards{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:560px){.ap-cards{grid-template-columns:1fr}}
</style>

<section class="ap-sec" id="ap-clients" aria-labelledby="ap-title">
  <div class="u-stats-head">
    <h2 id="ap-title">Clients per access point <span>{{ $apWindow['title'] }}</span></h2>
    <div class="ap-tools">
      <a class="u-export" href="{{ route('users.heatmap', $apQuery) }}">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>
        Heat map
      </a>
      <a class="u-export" href="{{ route('users.ap-clients', $apQuery) }}">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg>
        CSV
      </a>
    </div>
  </div>

  <div class="ap-cards">
    <div class="u-card u-total">
      <p class="t-label">Access points reporting</p>
      <p class="t-num">{{ number_format($apTotals['reporting']) }} <small>of {{ number_format($apTotals['aps']) }}</small></p>
      <p class="t-sub">With a client count in this period</p>
    </div>
    <div class="u-card u-total">
      <p class="t-label">Average clients</p>
      <p class="t-num">{{ $num($apTotals['average']) }} <small>at a time</small></p>
      <p class="t-sub"><b>{{ $num($apTotals['per_ap']) }}</b> per access point on average</p>
    </div>
    <div class="u-card u-total">
      <p class="t-label">Busiest hour</p>
      <p class="t-num">{{ $apTotals['busiest'] ? number_format($apTotals['busiest']['clients']) : '–' }} <small>clients</small></p>
      <p class="t-sub">{{ $apTotals['busiest'] ? $apTotals['busiest']['at']->copy()->setTimezone($tz)->format('D, M j, H:00') : 'No client counts yet' }}</p>
    </div>
    <div class="u-card u-total">
      <p class="t-label">Busiest access point</p>
      <p class="t-num" style="font-size:1.15rem;margin-top:10px">{{ $apTotals['top']['name'] ?? '–' }}</p>
      <p class="t-sub">
        @if ($apTotals['top'])
          <b>{{ $num($apTotals['top']['average']) }}</b> clients on average{{ $apTotals['top']['barangay'] ? ', '.$apTotals['top']['barangay'] : '' }}
        @else
          No client counts yet
        @endif
      </p>
    </div>
  </div>

  <div class="u-panel" style="border-top:2px solid var(--line);border-radius:8px">
    @if (! count($ap))
      <div class="u-empty">
        <h2>No access points yet</h2>
        <p>Add access points and their client counts are logged here every minute.</p>
      </div>
    @else
      <div class="ap-wrap">
        <table class="ap-table">
          <thead>
            <tr>
              <th scope="col" class="n">#</th>
              <th scope="col">Access point</th>
              <th scope="col">Barangay</th>
              <th scope="col" class="n">Now</th>
              <th scope="col" class="n">Average</th>
              <th scope="col" class="n">Peak</th>
              <th scope="col">Share of clients</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($ap as $i => $row)
              <tr>
                <td class="n" style="color:var(--ink-2)">{{ $i + 1 }}</td>
                <td>
                  <span class="ap-dot {{ $row['status'] }}" title="{{ ucfirst($row['status']) }}"></span><b>{{ $row['name'] }}</b>
                  @if ($row['landmark'])<small>{{ $row['landmark'] }}</small>@endif
                </td>
                <td>{{ $row['barangay'] ?? 'No barangay' }}</td>
                <td class="n">{{ $row['now'] ?? '–' }}</td>
                <td class="n">{{ $num($row['average']) ?? '–' }}</td>
                <td class="n">
                  {{ $row['peak'] ?? '–' }}
                  @if ($row['peak_at'])<small>{{ $row['peak_at']->copy()->setTimezone($tz)->format('M j, H:00') }}</small>@endif
                </td>
                <td>
                  @if ($row['average'] !== null)
                    <div class="ap-share">
                      <div class="bar"><span style="width:{{ round($row['average'] / $maxAvg * 100, 1) }}%"></span></div>
                      <b>{{ rtrim(rtrim(number_format($row['share'], 1), '0'), '.') }}%</b>
                    </div>
                  @else
                    <span class="none">No count in this period</span>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <div class="u-foot">
        <span>Counted over SNMP every minute and logged per hour. Average: clients at a time while the access point answered. Peak: the most at one check. Times in {{ $tz }}.</span>
      </div>
    @endif
  </div>
</section>
