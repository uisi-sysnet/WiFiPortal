{{-- Login attempts table. Needs $rows (RadiusLog::attempts items), $t, $who, $tab from radius/index. --}}
@if (! count($rows))
  <p class="r-empty">No login attempts here.</p>
@else
  <div class="r-wrap">
    <table class="r-table">
      <thead><tr><th scope="col">Time</th><th scope="col">Result</th><th scope="col">Login</th><th scope="col">Phone</th><th scope="col">Person</th><th scope="col">Why refused (likely)</th></tr></thead>
      <tbody>
        @foreach ($rows as $a)
          <tr>
            <td class="mono" style="white-space:nowrap">{{ $t($a['at']) }}</td>
            <td>
              @if ($a['accepted'])<span class="pill ok">Accepted</span>
              @elseif ($a['by_mac'] && ! $a['guest'])<span class="pill mac">Not registered</span>
              @else<span class="pill bad">Refused</span>@endif
            </td>
            <td><span class="mono">{{ $a['username'] }}</span>@if ($a['by_mac'])<small>MAC login (no page)</small>@endif @if ($a['server'])<small>{{ $a['server'] }}</small>@endif</td>
            <td>@if ($a['phone'])<a class="mono" href="{{ $tab('lookup', ['q' => $a['phone']]) }}">{{ $a['phone'] }}</a>@else<span style="color:#A7B4AD">–</span>@endif</td>
            <td>{{ $who($a) ?? '–' }}</td>
            <td>{{ $a['reason'] ?? '' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
@endif
