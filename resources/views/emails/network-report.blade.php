{{-- Scheduled network report email. Tables and inline styles: email clients ignore most CSS. Needs $s (NetworkSummary::build), $frequency. --}}
@php
  $green = '#0e670d'; $ink = '#0F1A1F'; $muted = '#5c6b66'; $red = '#B3372E'; $amber = '#8A5A00';
  $num = fn ($v) => $v === null ? '–' : ($v >= 10 || floor($v) == $v ? number_format($v) : number_format($v, 1));
  $downCount = count($s['routers']['down']) + count($s['aps']['down']) + count($s['switches']['down']);
  $cell = "padding:10px 12px;border:1px solid #D5E2D9;vertical-align:top;font-size:13px;color:{$ink}";
  $label = "font-size:11px;font-weight:bold;text-transform:uppercase;letter-spacing:.04em;color:{$muted}";
  $big = "font-size:22px;font-weight:bold;color:{$ink};margin:4px 0 2px";
  $h2 = "font-size:13px;text-transform:uppercase;letter-spacing:.05em;color:{$green};margin:22px 0 8px";
  $list = function (array $items, string $color) use ($muted) {
      return collect($items)->take(15)->map(fn ($d) => '<li style="margin:2px 0"><b style="color:'.$color.'">'.e($d['name']).'</b>'
          .($d['where'] ? ' <span style="color:'.$muted.'">'.e($d['where']).'</span>' : '')
          .($d['since'] ? ' <span style="color:'.$muted.'">· last seen '.e($d['since']->format('M j H:i')).'</span>' : '').'</li>')->join('')
          .(count($items) > 15 ? '<li style="color:'.$muted.'">…and '.(count($items) - 15).' more</li>' : '');
  };
@endphp
<!doctype html>
<html>
<body style="margin:0;padding:0;background:#F3F6F4;font-family:Arial,Helvetica,sans-serif;color:{{ $ink }}">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F3F6F4;padding:20px 0">
<tr><td align="center">
<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#fff;border:1px solid #D5E2D9">
  <tr><td style="background:{{ $green }};padding:16px 20px;color:#fff">
    <div style="font-size:18px;font-weight:bold">{{ $frequency }} network report</div>
    <div style="font-size:13px;opacity:.9">{{ $s['title'] }}: {{ $s['from']->format('M j, Y H:i') }} to {{ $s['to']->format('M j, Y H:i') }} ({{ $s['to']->timezone }})</div>
  </td></tr>
  <tr><td style="padding:18px 20px">

    <p style="margin:0 0 14px;padding:10px 12px;font-size:14px;background:{{ $downCount ? '#FBECEA' : '#EEF6EF' }};border-left:4px solid {{ $downCount ? $red : $green }}">
      @if ($downCount)
        <b style="color:{{ $red }}">{{ $downCount }} {{ $downCount === 1 ? 'device is' : 'devices are' }} down right now</b>:
        {{ count($s['routers']['down']) }} {{ Str::plural('router', count($s['routers']['down'])) }},
        {{ count($s['aps']['down']) }} {{ Str::plural('access point', count($s['aps']['down'])) }},
        {{ count($s['switches']['down']) }} {{ Str::plural('switch', count($s['switches']['down'])) }}. Details below.
      @else
        <b style="color:{{ $green }}">Everything is online.</b>
      @endif
      {{ $s['outages'] }} {{ $s['outages'] === 1 ? 'outage' : 'outages' }} and {{ $s['recoveries'] }} {{ $s['recoveries'] === 1 ? 'recovery' : 'recoveries' }} in this period.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse">
      <tr>
        <td style="{{ $cell }}" width="25%"><div style="{{ $label }}">Routers</div><div style="{{ $big }}">{{ $s['routers']['online'] }}/{{ $s['routers']['total'] }}</div><div style="color:{{ $muted }};font-size:12px">online</div></td>
        <td style="{{ $cell }}" width="25%"><div style="{{ $label }}">Access points</div><div style="{{ $big }}">{{ $s['aps']['online'] }}/{{ $s['aps']['total'] }}</div><div style="color:{{ $muted }};font-size:12px">online</div></td>
        <td style="{{ $cell }}" width="25%"><div style="{{ $label }}">Switches</div><div style="{{ $big }}">{{ $s['switches']['online'] }}/{{ $s['switches']['total'] }}</div><div style="color:{{ $muted }};font-size:12px">online</div></td>
        <td style="{{ $cell }}" width="25%"><div style="{{ $label }}">Users online</div><div style="{{ $big }}">{{ number_format($s['users']['now']) }}</div>
          <div style="color:{{ $muted }};font-size:12px">{{ $s['users']['peak'] ? 'peak '.number_format($s['users']['peak']['value']).', '.$s['users']['peak']['at']->format('M j H:i') : 'now' }}</div></td>
      </tr>
    </table>

    @if ($downCount)
      <div style="{{ $h2 }}">Down right now</div>
      <ul style="margin:0;padding-left:18px;font-size:13px">
        {!! $list($s['routers']['down'], $red) !!}{!! $list($s['aps']['down'], $red) !!}{!! $list($s['switches']['down'], $red) !!}
      </ul>
    @endif

    @if ($s['capacity'])
      <div style="{{ $h2 }}">Capacity alerts open</div>
      <ul style="margin:0;padding-left:18px;font-size:13px">
        @foreach ($s['capacity'] as $a)
          <li style="margin:3px 0"><b style="color:{{ $a['level'] === 'full' ? $red : $amber }}">{{ $a['level'] === 'full' ? 'Full' : 'Busy' }}: {{ $a['title'] }}</b><br><span style="color:{{ $muted }}">{{ $a['message'] }}</span></li>
        @endforeach
      </ul>
    @endif

    @if ($s['recent'])
      <div style="{{ $h2 }}">Latest problems in this period</div>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px">
        @foreach ($s['recent'] as $e)
          <tr><td style="padding:4px 8px 4px 0;color:{{ $muted }};white-space:nowrap" width="90">{{ $e['at']->format('M j H:i') }}</td>
            <td style="padding:4px 0;color:{{ $e['level'] === 'down' ? $red : $amber }}">{{ $e['title'] }}</td></tr>
        @endforeach
      </table>
    @endif

    <div style="{{ $h2 }}">Registrations on the captive portal</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse">
      <tr>
        <td style="{{ $cell }}"><div style="{{ $label }}">Total</div><div style="{{ $big }}">{{ number_format($s['registrations']['total']) }}</div></td>
        <td style="{{ $cell }}"><div style="{{ $label }}">Unique</div><div style="{{ $big }}">{{ number_format($s['registrations']['unique']) }}</div></td>
        <td style="{{ $cell }}"><div style="{{ $label }}">Repeated</div><div style="{{ $big }}">{{ number_format($s['registrations']['repeated']) }}</div></td>
        <td style="{{ $cell }}"><div style="{{ $label }}">Residents / visitors / students</div>
          <div style="font-size:15px;font-weight:bold;margin-top:6px">{{ number_format($s['registrations']['residents']) }} / {{ number_format($s['registrations']['visitors']) }} / {{ number_format($s['registrations']['students']) }}</div></td>
      </tr>
    </table>

    <div style="{{ $h2 }}">Clients per access point</div>
    <p style="margin:0 0 8px;font-size:13px">
      On average <b>{{ $num($s['clients']['average']) }}</b> clients connected at a time{{ $s['clients']['busiest'] ? '; busiest hour '.$s['clients']['busiest']['at']->format('M j H:00').' with '.number_format($s['clients']['busiest']['clients']).' clients' : '' }}.
    </p>
    @if ($s['clients']['top'])
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px">
        <tr><th align="left" style="{{ $label }};padding:4px 0;border-bottom:1px solid {{ $green }}">Busiest access points</th><th align="left" style="{{ $label }};padding:4px 0;border-bottom:1px solid {{ $green }}">Barangay</th><th align="right" style="{{ $label }};padding:4px 0;border-bottom:1px solid {{ $green }}">Average clients</th></tr>
        @foreach ($s['clients']['top'] as $ap)
          <tr><td style="padding:4px 0;border-bottom:1px solid #E6EEE8">{{ $ap['name'] }}</td><td style="padding:4px 0;border-bottom:1px solid #E6EEE8;color:{{ $muted }}">{{ $ap['barangay'] ?? '–' }}</td><td align="right" style="padding:4px 0;border-bottom:1px solid #E6EEE8"><b>{{ $num($ap['average']) }}</b></td></tr>
        @endforeach
      </table>
    @endif

    @if ($s['radius'])
      <div style="{{ $h2 }}">RADIUS logins</div>
      <p style="margin:0;font-size:13px">
        <b>{{ number_format($s['radius']['accepted']) }}</b> accepted, <b style="color:{{ $s['radius']['user_rejected'] ? $red : $ink }}">{{ number_format($s['radius']['user_rejected']) }}</b> login rejects,
        and {{ number_format($s['radius']['mac_rejected']) }} {{ $s['radius']['mac_rejected'] === 1 ? 'check of a phone' : 'checks of phones' }} not yet registered (normal: they were shown the captive portal).
      </p>
    @endif

    <p style="margin:22px 0 0;font-size:12px;color:{{ $muted }}">The Users report for this period is attached as a PDF. Open the dashboard: <a href="{{ rtrim(config('app.url'), '/') }}/dashboard" style="color:{{ $green }}">{{ rtrim(config('app.url'), '/') }}</a></p>
  </td></tr>
  <tr><td style="padding:12px 20px;border-top:1px solid #D5E2D9;font-size:11px;color:{{ $muted }}">
    This is a system-generated report from Public WiFi Control, sent {{ $s['to']->format('F j, Y \a\t g:i A') }}. Change the schedule or recipients in Settings.<br>
    <b style="color:{{ $green }}">System developed by Uplink Integrated Solutions Inc. &ndash; System &amp; Network Department</b>
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
