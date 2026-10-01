{{--
  "Busiest barangays now": every barangay with the clients on its access points.
  Rendered with the page and again by dashboard.live. Needs: $rows (DashboardController::barangayClients()).
--}}
@php
  $max = max(1, (int) collect($rows)->max('clients'));
  $collected = collect($rows)->contains(fn ($r) => $r['clients'] !== null);
@endphp
@if (! $rows)
  <p class="bars-note">No barangays yet. Add them in Settings, then assign access points to them.</p>
@else
  <ol class="bars">
    @foreach ($rows as $r)
      <li>
        <div class="row">
          <span>{{ $r['name'] }}
            <small>
              @if (! $r['aps'])
                No access points
              @else
                {{ $r['online'] }} of {{ $r['aps'] }} {{ $r['aps'] === 1 ? 'AP' : 'APs' }} online{{ $r['reporting'] && $r['reporting'] < $r['online'] ? ', '.$r['reporting'].' reporting' : '' }}
              @endif
            </small>
          </span>
          @if ($r['clients'] === null)
            <b class="na" title="No access point here reports its clients yet">–</b>
          @else
            <b>{{ number_format($r['clients']) }}</b>
          @endif
        </div>
        <div class="track"><span style="width:{{ $r['clients'] ? round($r['clients'] / $max * 100) : 0 }}%"></span></div>
      </li>
    @endforeach
  </ol>
  @unless ($collected)
    <p class="bars-note">Client counts are not collected from the access points yet, so every barangay shows a dash.</p>
  @endunless
@endif
