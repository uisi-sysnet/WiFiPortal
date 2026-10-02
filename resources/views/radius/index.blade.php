@extends('layouts.app')

@section('title', 'RADIUS | Public WiFi Control')
@section('body-class', 'wide')

@push('head')
<style>
:root{--line:#0e670d;--ink:#0F1A1F;--ink-2:#5c6b66;--hover:#F0F3F1;--fail:#B3372E;--warn:#8A5A00}
body.wide main{max-width:1600px}
.r-title{margin:0 0 4px;font-size:1.35rem;font-weight:700;color:var(--ink)}
.r-lede{margin:0 0 14px;color:var(--ink-2);font-size:.88rem}
.r-tabs{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:14px;border-bottom:2px solid var(--line)}
.r-tabs a{padding:8px 14px;font-size:.86rem;font-weight:600;color:var(--ink-2);text-decoration:none;border-radius:6px 6px 0 0}
.r-tabs a:hover{background:var(--hover);color:var(--ink)}
.r-tabs a[aria-current=page]{background:#0e670d;color:#fff}
.r-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:14px}
.r-card{background:#fff;border:2px solid var(--line);border-radius:8px;padding:12px 14px}
.r-card .lbl{margin:0;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-2)}
.r-card .num{margin:4px 0 6px;font-size:1.7rem;font-weight:700;color:var(--ink);font-variant-numeric:tabular-nums}
.r-card .num small{font-size:.8rem;font-weight:500;color:var(--ink-2)}
.r-card .sub{margin:0;font-size:.78rem;color:var(--ink-2)}
.r-card .sub b.bad{color:var(--fail)}
.r-alert{margin:0 0 14px;padding:10px 14px;border-left:4px solid var(--fail);background:#FBECEA;border-radius:6px;font-size:.88rem}
.r-alert.ok{border-color:#0e670d;background:#EEF6EF}
.r-alert.warn{border-color:#C98A00;background:#FFF5DE}
.r-panel{background:#fff;border:2px solid var(--line);border-radius:8px;overflow:hidden;margin-bottom:16px}
.r-panel h2{margin:0;padding:10px 14px;font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--ink);border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:10px}
.r-panel h2 a{font-size:.78rem;text-transform:none;letter-spacing:0;color:#0e670d}
.r-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:8px 14px;border-bottom:1px solid var(--line)}
.r-control{height:31px;padding:0 9px;font:inherit;font-size:.82rem;border:1px solid var(--line);border-radius:6px;background:#fff;color:var(--ink)}
.r-filters input[type=search]{min-width:260px}
.r-btn{display:inline-flex;align-items:center;height:31px;padding:0 12px;font:inherit;font-size:.82rem;font-weight:600;border-radius:6px;border:1px solid #0e670d;background:#0e670d;color:#fff;cursor:pointer;text-decoration:none}
.r-wrap{overflow:auto}
table.r-table{width:100%;min-width:900px;border-collapse:collapse;font-size:.82rem}
.r-table th,.r-table td{padding:7px 10px;border-bottom:1px solid color-mix(in srgb,#0e670d 22%,transparent);text-align:left;vertical-align:top}
.r-table th{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-2);background:#FCFDFC}
.r-table td small{display:block;color:var(--ink-2);font-size:.72rem;margin-top:1px}
.r-table .n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.r-table tbody tr:hover{background:var(--hover)}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.78rem}
.pill{display:inline-flex;align-items:center;gap:5px;padding:1px 8px;border-radius:999px;font-size:.68rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.pill.ok{color:#0e670d;background:#E7F2E8}
.pill.bad{color:var(--fail);background:#FBECEA}
.pill.mac{color:var(--ink-2);background:#F0F3F1}
.r-empty{padding:30px 20px;text-align:center;color:var(--ink-2)}
.r-foot{padding:8px 14px;font-size:.8rem;color:var(--ink-2)}
.r-kv{display:grid;grid-template-columns:180px minmax(0,1fr);gap:6px 14px;padding:12px 14px;margin:0;font-size:.86rem}
.r-kv dt{color:var(--ink-2)}
.r-kv dd{margin:0}
.r-setup{background:#fff;border:2px solid var(--line);border-radius:8px;padding:18px 20px;max-width:860px}
.r-setup pre{background:#0F1A1F;color:#E8F2EC;padding:10px 12px;border-radius:6px;overflow:auto;font-size:.8rem}
@media (max-width:1000px){.r-cards{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:560px){.r-cards{grid-template-columns:1fr}.r-kv{grid-template-columns:1fr}}
</style>
@endpush

@section('content')
@php
  $tz = config('hotspot.history.timezone');
  $t = fn ($at) => $at?->copy()->setTimezone($tz)->format('M j, H:i:s');
  $bytes = function ($b) {
      $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0; $b = (float) $b;
      while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
      return ($i ? number_format($b, $b >= 100 ? 0 : 1) : (int) $b).' '.$u[$i];
  };
  $dur = function ($s) {
      $s = (int) $s;
      return $s >= 86400 ? intdiv($s, 86400).'d '.intdiv($s % 86400, 3600).'h' : ($s >= 3600 ? intdiv($s, 3600).'h '.intdiv($s % 3600, 60).'m' : intdiv($s, 60).'m '.($s % 60).'s');
  };
  $tab = fn ($v, $extra = []) => route('radius', array_filter(['view' => $v === 'overview' ? null : $v, ...$extra]));
  $who = function ($row) {
      $g = $row['guest'];
      if (! $g) return null;
      return match ($g->category) {
          'resident' => 'Resident '.$g->maskedCitizenNumber(),
          'student' => $g->name.' (student)',
          default => $g->name,
      };
  };
@endphp

<h1 class="r-title">RADIUS</h1>
<p class="r-lede">Logins checked by FreeRADIUS for every router: who got in, who was refused and why, and who is online. For troubleshooting "I registered but I can't connect".</p>

<nav class="r-tabs" aria-label="RADIUS views">
  @foreach ($views as $key => $label)
    <a href="{{ $tab($key) }}" @if($view === $key) aria-current="page" @endif>{{ $label }}</a>
  @endforeach
</nav>

@if (! $available)
  <div class="r-setup">
    <h2 style="margin-top:0">RADIUS is not set up on this server yet</h2>
    <p>FreeRADIUS's tables (<span class="mono">radpostauth</span>, <span class="mono">radacct</span>) are not in the database. Until then each router keeps its own logins, and this page has nothing to show.</p>
    <p>On the Ubuntu server, run:</p>
    <pre>sudo bash /var/www/wifiportal/deploy/setup-radius.sh</pre>
    <p>Then use <b>Re-apply configuration</b> on each router. See the README, section "RADIUS".</p>
  </div>
@elseif ($view === 'overview')
  @php $h = $health; @endphp
  @if (! $configured)
    <p class="r-alert warn"><b>RADIUS_HOST is not set in .env.</b> The tables exist, but new registrations are created on the routers, not in RADIUS. Set RADIUS_HOST and RADIUS_SECRET, then re-apply each router.</p>
  @elseif ($h['alarm'])
    <p class="r-alert"><b>Many logins are being refused:</b> {{ number_format($h['hour']['user_rejected']) }} in the last hour, against {{ number_format($h['hour']['accepted']) }} accepted. Check the reasons under <a href="{{ $tab('attempts', ['result' => 'rejected']) }}">Login attempts</a>. A wrong shared secret on a router, or its clock, are common causes.</p>
  @elseif (! $h['last_any'] || $h['last_any']->lt(now()->subHour()))
    <p class="r-alert warn"><b>No login has reached RADIUS {{ $h['last_any'] ? 'since '.$h['last_any']->diffForHumans() : 'yet' }}.</b> If people are using the WiFi, the routers are not asking RADIUS: re-apply their configuration, and check that UDP 1812/1813 reach this server.</p>
  @else
    <p class="r-alert ok"><b>RADIUS is answering.</b> Last accepted login {{ $h['last_accept']?->diffForHumans() ?? 'not yet' }}.</p>
  @endif
  @if ($h['missing'] > 0)
    <p class="r-alert"><b>{{ number_format($h['missing']) }} valid {{ Str::plural('registration', $h['missing']) }} {{ $h['missing'] === 1 ? 'has' : 'have' }} no login in RADIUS</b>, so {{ $h['missing'] === 1 ? 'that person' : 'those people' }} can't get online. This happens to registrations made before RADIUS was switched on; they register again on the portal.</p>
  @endif

  <div class="r-cards">
    <div class="r-card">
      <p class="lbl">Accepted, last hour</p>
      <p class="num">{{ number_format($h['hour']['accepted']) }}</p>
      <p class="sub">{{ number_format($h['day']['accepted']) }} in the last 24 hours</p>
    </div>
    <div class="r-card">
      <p class="lbl">Login rejects, last hour</p>
      <p class="num">{{ number_format($h['hour']['user_rejected']) }}</p>
      <p class="sub"><b class="{{ $h['day']['user_rejected'] ? 'bad' : '' }}">{{ number_format($h['day']['user_rejected']) }}</b> in 24 hours: expired, wrong phone or unknown</p>
    </div>
    <div class="r-card">
      <p class="lbl">Unregistered phones, last hour</p>
      <p class="num">{{ number_format($h['hour']['mac_rejected']) }}</p>
      <p class="sub">MAC checks refused: normal, these phones are shown the captive portal</p>
    </div>
    <div class="r-card">
      <p class="lbl">Online now</p>
      <p class="num">{{ number_format($h['online']) }} <small>sessions</small></p>
      <p class="sub">{{ number_format($h['logins']) }} logins in RADIUS for {{ number_format($h['valid']) }} valid registrations</p>
    </div>
  </div>

  <div class="r-panel">
    <h2>Latest refused logins <a href="{{ $tab('attempts', ['result' => 'rejected']) }}">All attempts</a></h2>
    @include('radius._attempts', ['rows' => $recentRejects])
  </div>
  <div class="r-panel">
    <h2>Online now <a href="{{ $tab('sessions') }}">All sessions</a></h2>
    @include('radius._sessions', ['rows' => $online->items()])
  </div>

@elseif ($view === 'attempts')
  <div class="r-panel">
    <form class="r-filters" method="GET" action="{{ route('radius') }}">
      <input type="hidden" name="view" value="attempts">
      <label class="sr-only" for="r-q">Search</label>
      <input id="r-q" class="r-control" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="MAC, login (wifi-...) or part of it">
      <select class="r-control" name="result" onchange="this.form.submit()" aria-label="Result">
        <option value="">Accepted and refused</option>
        <option value="accepted" @selected(($filters['result'] ?? '') === 'accepted')>Accepted only</option>
        <option value="rejected" @selected(($filters['result'] ?? '') === 'rejected')>Refused only</option>
      </select>
      <button class="r-btn" type="submit">Search</button>
    </form>
    @include('radius._attempts', ['rows' => $attempts->items()])
    @if ($attempts->hasPages())<div class="r-foot">{{ $attempts->links() }}</div>@endif
    <p class="r-foot">Newest first. Kept {{ config('hotspot.radius.auth_log_days') }} days. The reason is worked out from the registration; RADIUS itself only records accepted or refused. Passwords are never shown.</p>
  </div>

@elseif ($view === 'sessions')
  <div class="r-panel">
    <form class="r-filters" method="GET" action="{{ route('radius') }}">
      <input type="hidden" name="view" value="sessions">
      <label class="sr-only" for="r-q2">Search</label>
      <input id="r-q2" class="r-control" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="MAC or login">
      <select class="r-control" name="status" onchange="this.form.submit()" aria-label="Sessions">
        <option value="online" @selected(($filters['status'] ?? 'online') === 'online')>Online now</option>
        <option value="all" @selected(($filters['status'] ?? '') === 'all')>All sessions</option>
      </select>
      <select class="r-control" name="router" onchange="this.form.submit()" aria-label="Router">
        <option value="">All routers</option>
        @foreach ($routers as $r)<option value="{{ $r->id }}" @selected((int) ($filters['router'] ?? 0) === $r->id)>{{ $r->name }}</option>@endforeach
      </select>
      <button class="r-btn" type="submit">Search</button>
    </form>
    @include('radius._sessions', ['rows' => $sessions->items()])
    @if ($sessions->hasPages())<div class="r-foot">{{ $sessions->links() }}</div>@endif
    <p class="r-foot">Upload is from the phone, download to it. Online means the router sent an update in the last {{ config('hotspot.radius.online_minutes') }} minutes. Finished sessions are kept {{ config('hotspot.radius.acct_days') }} days.</p>
  </div>

@else
  <div class="r-panel">
    <form class="r-filters" method="GET" action="{{ route('radius') }}">
      <input type="hidden" name="view" value="lookup">
      <label class="sr-only" for="r-look">Phone MAC or login</label>
      <input id="r-look" class="r-control" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="AA:BB:CC:DD:EE:FF or wifi-k7m2p9qx" autofocus>
      <button class="r-btn" type="submit">Look up</button>
    </form>
    @if (! $lookup)
      <p class="r-empty">Enter the phone's MAC address (the person can find it in WiFi settings &gt; this network &gt; details) or a login name, to see its registration, its login in RADIUS, and its recent attempts and sessions.</p>
    @else
      @php $L = $lookup; @endphp
      <dl class="r-kv">
        <dt>Registration</dt>
        <dd>
          @forelse ($L['guests'] as $g)
            <div><b>{{ $g->categoryLabel() }}</b> {{ $g->category === 'resident' ? $g->maskedCitizenNumber() : $g->name }}, login <span class="mono">{{ $g->username }}</span>,
              registered {{ $g->created_at->copy()->setTimezone($tz)->format('M j, H:i') }} on {{ $g->network?->name ?? 'a removed network' }} ({{ $g->router?->name ?? 'removed router' }}).
              <span class="pill {{ $g->state() === 'expired' ? 'bad' : 'ok' }}">{{ $g->state() === 'expired' ? 'Access ended' : 'Valid until '.$g->expires_at?->copy()->setTimezone($tz)->format('M j, H:i') }}</span></div>
          @empty
            Not registered on the captive portal{{ $L['mac'] ? ' with this phone' : '' }}.
          @endforelse
        </dd>
        <dt>Login in RADIUS</dt>
        <dd>
          @forelse ($L['check'] as $user => $c)
            <div><span class="mono">{{ $user }}</span>: {{ $c['has_password'] ? 'password set' : 'no password' }}{{ $c['mac'] ? ', only for phone '.$c['mac'] : '' }}{{ $c['expiration'] ? ', ends '.$c['expiration'].' (RADIUS clock)' : '' }}</div>
          @empty
            None. {{ $L['guests']->contains(fn ($g) => $g->state() !== 'expired') ? 'The registration is valid but has no login in RADIUS: it was made before RADIUS was switched on. Ask them to register again.' : 'Nothing to log in with: the phone will see the captive portal.' }}
          @endforelse
        </dd>
      </dl>
      <h2 style="margin:0;padding:10px 14px;font-size:.82rem;text-transform:uppercase;border-top:1px solid var(--line);border-bottom:1px solid var(--line)">Recent login attempts</h2>
      @include('radius._attempts', ['rows' => $L['attempts']])
      <h2 style="margin:0;padding:10px 14px;font-size:.82rem;text-transform:uppercase;border-top:1px solid var(--line);border-bottom:1px solid var(--line)">Recent sessions</h2>
      @include('radius._sessions', ['rows' => $L['sessions']])
    @endif
  </div>
@endif
@endsection
