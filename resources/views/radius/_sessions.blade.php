{{-- Sessions table. Needs $rows (RadiusLog::sessions items), $t, $who, $tab, $bytes, $dur from radius/index. --}}
@if (! count($rows))
  <p class="r-empty">No sessions here.</p>
@else
  <div class="r-wrap">
    <table class="r-table">
      <thead><tr><th scope="col">Started</th><th scope="col">Login</th><th scope="col">Phone</th><th scope="col">Person</th><th scope="col">Router</th><th scope="col" class="n">Time</th><th scope="col" class="n">Download</th><th scope="col" class="n">Upload</th><th scope="col">Ended</th></tr></thead>
      <tbody>
        @foreach ($rows as $s)
          <tr>
            <td class="mono" style="white-space:nowrap">{{ $t($s['start']) }}</td>
            <td><span class="mono">{{ $s['username'] }}</span></td>
            <td>@if ($s['phone'])<a class="mono" href="{{ $tab('lookup', ['q' => $s['phone']]) }}">{{ $s['phone'] }}</a>@else –@endif @if ($s['ip'])<small class="mono">{{ $s['ip'] }}</small>@endif</td>
            <td>{{ $who($s) ?? '–' }}</td>
            <td>{{ $s['router'] ?? '–' }}@if ($s['server'])<small>{{ $s['server'] }}</small>@endif</td>
            <td class="n">{{ $dur($s['seconds']) }}</td>
            <td class="n">{{ $bytes($s['download']) }}</td>
            <td class="n">{{ $bytes($s['upload']) }}</td>
            <td>
              @if ($s['online'])<span class="pill ok">Online</span>
              @else {{ $t($s['stop']) }}<small>{{ $s['cause'] ? ucfirst(strtolower(str_replace('-', ' ', $s['cause']))) : '' }}</small>@endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
@endif
