@extends('layouts.app')

@section('title', 'Users | Public WiFi Control')
@section('body-class', 'wide')

@push('head')
<style>
/* Same look as the Devices list */
:root{--signal:#0e670d;--signal-soft:color-mix(in srgb,#0e670d 12%,#fff);--line:#0e670d;--ink:#0F1A1F;--ink-2:#5c6b66;--hover:#F0F3F1;--fail:#B3372E}
body.wide main{max-width:1600px}
.u-head{display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:8px 20px;padding:12px 16px;background:#fff;border:2px solid var(--line);border-bottom:0;border-radius:8px 8px 0 0}
.u-head h2{margin:0;font-size:1.05rem;font-weight:700;letter-spacing:-.01em;text-transform:uppercase;color:var(--ink)}
.u-head p{margin:0;color:var(--ink-2);font-size:.82rem}
.u-panel{background:#fff;border:2px solid var(--line);border-top:1px solid var(--line);border-radius:0 0 8px 8px;overflow:hidden}
.u-filters{display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:8px 14px;border-bottom:1px solid var(--line)}
.u-control{height:30px;padding:0 10px;font:inherit;font-size:.82rem;border:1px solid var(--line);border-radius:6px;background:#fff;color:var(--ink);box-sizing:border-box}
.u-control:focus{outline:none;box-shadow:0 0 0 3px var(--signal-soft)}
.u-filters input[type=search]{min-width:240px;flex:1;max-width:360px}
.u-btn{display:inline-flex;align-items:center;height:30px;padding:0 12px;font:inherit;font-size:.82rem;font-weight:500;border-radius:6px;border:1px solid #0e670d;background:#0e670d;color:#fff;cursor:pointer;text-decoration:none}
.u-btn.quiet{background:#fff;color:var(--ink);border-color:var(--line)}
.u-btn:hover{filter:brightness(.95)}
.u-chips{display:flex;flex-wrap:wrap;gap:6px;margin-left:auto}
.u-chip{display:inline-flex;align-items:center;gap:5px;height:24px;padding:0 9px;border-radius:999px;font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;text-decoration:none;border:1px solid var(--line);color:var(--ink-2);background:#fff}
.u-chip b{font-size:.78rem}
.u-chip.active{color:#0e670d;background:color-mix(in srgb,#0e670d 10%,#fff)}
.u-chip.expired{color:var(--fail);border-color:color-mix(in srgb,var(--fail) 35%,transparent)}
.u-chip[aria-current=true]{box-shadow:0 0 0 2px var(--signal-soft);border-width:2px}
.u-wrap{overflow:auto}
table.u-table{width:100%;min-width:1100px;border-collapse:collapse;font-size:.82rem}
.u-table th,.u-table td{padding:7px 10px;border-bottom:1px solid color-mix(in srgb,#0e670d 25%,transparent);text-align:left;vertical-align:top}
.u-table th{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-2);background:#FCFDFC;position:sticky;top:0}
.u-table tbody tr:hover{background:var(--hover)}
.u-table td small{display:block;color:var(--ink-2);font-size:.72rem;margin-top:1px}
.u-table .num{color:var(--ink-2);text-align:right;width:52px;font-variant-numeric:tabular-nums}
.u-table .who{font-weight:600;color:var(--ink)}
.u-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.78rem}
.u-tag{display:inline-block;margin-left:6px;padding:0 6px;border-radius:4px;font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;background:#F0F3F1;color:var(--ink-2);vertical-align:1px}
.u-tag.resident{background:color-mix(in srgb,#0e670d 12%,#fff);color:#0e670d}
.u-tag.student{background:color-mix(in srgb,#1d4fa3 12%,#fff);color:#1d4fa3}
.pill{display:inline-flex;align-items:center;gap:5px;padding:1px 8px;border-radius:999px;font-size:.68rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.pill::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}
.pill.active{color:#0e670d;background:color-mix(in srgb,#0e670d 12%,#fff)}
.pill.waiting{color:#8A5A00;background:#FFF5DE}
.pill.expired{color:#5c6b66;background:#F0F3F1}
.none{color:#A7B4AD}
.u-empty{text-align:center;padding:40px 24px;color:var(--ink-2)}
.u-empty h2{margin:0 0 6px;font-size:1rem;color:var(--ink)}
.u-foot{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;padding:8px 14px;border-top:1px solid var(--line);font-size:.82rem;color:var(--ink-2)}
.u-foot nav{margin-left:auto}
/* ---- Analytics ---- */
.u-title{margin:0 0 12px;font-size:1.35rem;font-weight:700;letter-spacing:-.01em;color:var(--ink)}
.u-stats{margin-bottom:22px}
.u-stats-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px 16px;margin-bottom:12px}
.u-stats-head h2{margin:0;font-size:1.05rem;font-weight:700;text-transform:uppercase;color:var(--ink)}
.u-stats-head h2 span{margin-left:8px;font-size:.82rem;font-weight:500;text-transform:none;color:var(--ink-2)}
.u-stats-tools{display:flex;flex-wrap:wrap;align-items:center;gap:10px}
.u-export{display:inline-flex;align-items:center;gap:6px;height:32px;padding:0 12px;border:1px solid #0e670d;border-radius:6px;background:#fff;color:#0e670d;font-size:.82rem;font-weight:600;text-decoration:none;cursor:pointer;list-style:none}
.u-export::-webkit-details-marker{display:none}
.u-export-wrap{position:relative}
.u-export-wrap[open] .u-export{background:#0e670d;color:#fff}
.u-export-form{position:absolute;right:0;top:calc(100% + 6px);z-index:30;width:300px;padding:14px;background:#fff;border:2px solid #0e670d;border-radius:8px;box-shadow:0 12px 30px rgba(10,20,26,.18)}
.u-export-form fieldset{border:0;margin:0 0 8px;padding:0;display:grid;gap:4px}
.u-export-form legend{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-2);margin-bottom:4px}
.u-radio{display:flex;align-items:center;gap:8px;font-size:.86rem;cursor:pointer}
.u-radio input{accent-color:#0e670d}
.u-dates{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:4px 0 8px}
.u-dates label{display:grid;gap:3px;font-size:.75rem;color:var(--ink-2)}
.u-dates[hidden]{display:none}
.u-error{margin:0 0 6px;color:var(--fail);font-size:.78rem}
.u-export-note{margin:0 0 10px;font-size:.75rem;color:var(--ink-2)}
.u-export-form .u-btn{width:100%;justify-content:center}
.u-export-form .u-btn:disabled{opacity:.6;cursor:wait}
.u-format{grid-template-columns:1fr 1fr}
.u-format legend{grid-column:1/-1}
.u-export-msg{margin:0 0 8px;font-size:.78rem;color:var(--ink-2)}
.u-export-msg.bad{color:var(--fail)}
.u-export:hover{background:color-mix(in srgb,#0e670d 8%,#fff)}
.u-seg{display:inline-flex;border:1px solid var(--line);border-radius:6px;overflow:hidden;background:#fff}
.u-seg a{padding:6px 14px;font-size:.82rem;font-weight:600;color:var(--ink-2);text-decoration:none}
.u-seg a+a{border-left:1px solid var(--line)}
.u-seg a:hover{background:var(--hover);color:var(--ink)}
.u-seg a[aria-current=page]{background:#0e670d;color:#fff}
.u-card{margin:0;background:#fff;border:2px solid var(--line);border-radius:8px;padding:12px 14px}
.u-card figcaption{display:flex;flex-wrap:wrap;justify-content:space-between;gap:6px 12px;margin-bottom:6px;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-2)}
.u-charts{display:grid;grid-template-columns:minmax(0,3fr) minmax(240px,1fr);gap:14px;margin-bottom:14px}
.u-bars svg{display:block;width:100%;height:auto}
.u-bars .grid{stroke:color-mix(in srgb,#0e670d 14%,transparent);stroke-width:1}
.u-bars .ax{fill:var(--ink-2);font-size:11px}
.u-bars .ax.empty{font-size:13px}
.u-bars .val{fill:var(--ink);font-size:10.5px;font-weight:600}
.u-bars .b-unique{fill:#0e670d}
.u-bars .b-repeat{fill:#7FB77E}
.u-bars .hit{fill:transparent}
.u-bars .hit:hover{fill:rgba(14,103,13,.07)}
.u-key{display:inline-flex;align-items:center;gap:6px;text-transform:none;letter-spacing:0;font-weight:500}
.u-key i,.u-legend i{display:inline-block;width:10px;height:10px;border-radius:2px;margin-left:6px}
.k-unique{background:#0e670d}
.k-repeat{background:#7FB77E}
.u-donut{display:flex;flex-direction:column}
.u-donut svg{display:block;width:100%;max-width:200px;margin:4px auto 8px}
.p-empty{fill:#EEF3EF}
.p-unique{fill:#0e670d;stroke:#fff;stroke-width:1.5}
.p-repeat{fill:#7FB77E;stroke:#fff;stroke-width:1.5}
.u-total-users{text-transform:none;letter-spacing:0;color:var(--ink);font-weight:700}
.u-legend{list-style:none;margin:auto 0 0;padding:0;display:grid;gap:6px;font-size:.85rem}
.u-legend li{display:flex;align-items:center;gap:8px}
.u-legend i{margin:0}
.u-legend b{margin-left:auto;font-variant-numeric:tabular-nums}
.u-legend span{min-width:52px;text-align:right;color:var(--ink-2);font-variant-numeric:tabular-nums}
.u-totals{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
.u-total .t-label{margin:0;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-2)}
.u-total .t-num{margin:4px 0 8px;font-size:1.9rem;font-weight:700;color:var(--ink);font-variant-numeric:tabular-nums;line-height:1.1}
.u-total .t-num small{font-size:.8rem;font-weight:500;color:var(--ink-2)}
.u-total .t-bar{height:6px;border-radius:3px;background:#EEF3EF;overflow:hidden}
.u-total .t-bar span{display:block;height:100%;background:#0e670d}
.u-total .t-bar.repeat span{background:#7FB77E}
.u-total .t-sub{margin:8px 0 0;font-size:.78rem;color:var(--ink-2)}
.u-total .t-sub b{color:#0e670d}
@media (max-width:560px){.u-export-form{position:fixed;left:12px;right:12px;top:90px;width:auto}}
@media (max-width:1100px){.u-charts{grid-template-columns:1fr}.u-totals{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:560px){.u-totals{grid-template-columns:1fr}}
@media (max-width:760px){.u-filters input[type=search]{min-width:0;max-width:none;width:100%}.u-chips{margin-left:0}}
</style>
@endpush

@section('content')
@php
  $filtered = collect($filters)->except(['period', 'range'])->filter()->isNotEmpty() || $filters['period'] !== 'all';
  $chipUrl = fn ($state) => route('users.index', array_filter([...$filters, 'status' => $state, 'page' => null]));
@endphp

<h1 class="u-title">Users</h1>
@include('users._stats')

<div class="u-head">
  <h2>Registrations</h2>
  <p>Everyone who registered on a captive portal. <b>{{ number_format($today) }}</b> registered today.</p>
</div>

<div class="u-panel">
  <form class="u-filters" method="GET" action="{{ route('users.index') }}" role="search">
    <label class="sr-only" for="u-q">Search users</label>
    <input id="u-q" class="u-control" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name, mobile, email, MAC, IP, resident or student ID, school">
    <label class="sr-only" for="u-type">Type</label>
    <select id="u-type" class="u-control" name="type" onchange="this.form.submit()">
      <option value="">All types of user</option>
      <option value="resident" @selected(($filters['type'] ?? '') === 'resident')>Residents</option>
      <option value="visitor" @selected(($filters['type'] ?? '') === 'visitor')>Visitors</option>
      <option value="student" @selected(($filters['type'] ?? '') === 'student')>Students</option>
    </select>
    <label class="sr-only" for="u-network">Hotspot network</label>
    <select id="u-network" class="u-control" name="network" onchange="this.form.submit()">
      <option value="">All hotspot networks</option>
      @foreach ($networks as $n)
        <option value="{{ $n->id }}" @selected((int) ($filters['network'] ?? 0) === $n->id)>{{ $n->router?->name }} / {{ $n->name }}</option>
      @endforeach
    </select>
    <label class="sr-only" for="u-period">Registered</label>
    <select id="u-period" class="u-control" name="period" onchange="this.form.submit()">
      @foreach ($periods as $value => $label)
        <option value="{{ $value }}" @selected($filters['period'] === $value)>{{ $label }}</option>
      @endforeach
    </select>
    @if (! empty($filters['status']))<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
    @if (! empty($filters['range']))<input type="hidden" name="range" value="{{ $filters['range'] }}">@endif
    <button class="u-btn" type="submit">Search</button>
    @if ($filtered)<a class="u-btn quiet" href="{{ route('users.index', array_filter(['range' => $filters['range'] ?? null])) }}">Clear</a>@endif

    <div class="u-chips" role="group" aria-label="Filter by status">
      <a class="u-chip" href="{{ $chipUrl(null) }}" @if(empty($filters['status'])) aria-current="true" @endif>All <b>{{ number_format(array_sum($counts)) }}</b></a>
      <a class="u-chip active" href="{{ $chipUrl('active') }}" @if(($filters['status'] ?? '') === 'active') aria-current="true" @endif>Connected <b>{{ number_format($counts['active']) }}</b></a>
      <a class="u-chip" href="{{ $chipUrl('waiting') }}" @if(($filters['status'] ?? '') === 'waiting') aria-current="true" @endif>Not connected <b>{{ number_format($counts['waiting']) }}</b></a>
      <a class="u-chip expired" href="{{ $chipUrl('expired') }}" @if(($filters['status'] ?? '') === 'expired') aria-current="true" @endif>Expired <b>{{ number_format($counts['expired']) }}</b></a>
    </div>
  </form>

  @if ($users->isEmpty())
    <div class="u-empty">
      @if ($filtered)
        <h2>No users match</h2>
        <p>Try another search, or <a href="{{ route('users.index') }}">clear the filters</a>.</p>
      @else
        <h2>No users yet</h2>
        <p>People appear here as soon as they register on a hotspot's captive portal.</p>
      @endif
    </div>
  @else
    <div class="u-wrap">
      <table class="u-table">
        <thead>
          <tr>
            <th scope="col" class="num">#</th>
            <th scope="col">User</th>
            <th scope="col">Contact</th>
            <th scope="col">Hotspot network</th>
            <th scope="col">Device</th>
            <th scope="col">Registered</th>
            <th scope="col">Connected</th>
            <th scope="col">Status</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($users as $u)
            <tr>
              <td class="num">{{ $users->firstItem() + $loop->index }}</td>
              <td>
                @if ($u->category === 'resident' || $u->resident)
                  <span class="who">Resident</span><span class="u-tag resident">Resident</span>
                  <small class="u-mono" title="Resident ID (hidden except the last 4)">{{ $u->maskedCitizenNumber() }}</small>
                @elseif ($u->category === 'student')
                  <span class="who">{{ $u->name }}</span><span class="u-tag student">Student</span>
                  <small>{{ $u->school }} &middot; <span class="u-mono">{{ $u->student_number }}</span></small>
                @else
                  <span class="who">{{ $u->name }}</span><span class="u-tag">Visitor</span>
                @endif
              </td>
              <td>
                @if ($u->contact)
                  <span class="u-mono">{{ $u->contact }}</span>
                  <small>{{ $u->contact_type === 'email' ? 'Email' : 'Mobile' }}</small>
                @else
                  <span class="none">–</span>
                @endif
              </td>
              <td>
                {{ $u->network?->name ?? 'Removed network' }}
                <small>{{ $u->router?->name ?? 'Removed router' }}</small>
              </td>
              <td>
                <span class="u-mono">{{ $u->mac ?? '–' }}</span>
                <small class="u-mono">{{ $u->ip }}</small>
              </td>
              <td>
                {{ $u->created_at->timezone('Asia/Manila')->format('M j, Y H:i') }}
                <small>{{ $u->created_at->diffForHumans() }}</small>
              </td>
              <td>
                @if ($u->connected_at)
                  {{ $u->connected_at->timezone('Asia/Manila')->format('M j, H:i') }}
                  <small>{{ $u->login_method === 'api' ? 'Logged in by the server' : 'Logged in by the phone' }}</small>
                  @if ($u->roams)
                    <small title="Went online on another router or network without the captive portal">Roamed {{ $u->roams }}&times;, last {{ $u->last_connected_at?->diffForHumans() }}</small>
                  @endif
                @else
                  <span class="none">Never</span>
                @endif
              </td>
              <td>
                <span class="pill {{ $u->state() }}">{{ $u->stateLabel() }}</span>
                @if ($u->state() !== 'expired' && $u->expires_at)
                  <small>Login ends {{ $u->expires_at->diffForHumans() }}</small>
                @elseif ($u->state() === 'expired')
                  <small>{{ ($u->revoked_at ?? $u->expires_at)?->timezone('Asia/Manila')->format('M j, H:i') }}</small>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    <div class="u-foot">
      <span>Showing {{ number_format($users->firstItem()) }}-{{ number_format($users->lastItem()) }} of {{ number_format($users->total()) }}</span>
      {{ $users->links() }}
    </div>
  @endif
</div>
<script>
  // Export: the date fields only matter for "Date range"; PNG is captured here in the browser.
  document.querySelectorAll('.u-export-form').forEach(function (form) {
    var dates = form.querySelector('[data-dates]');
    var btn = form.querySelector('[data-export-btn]'), msg = form.querySelector('[data-export-msg]');
    var format = function () { return form.querySelector('input[name=format]:checked').value; };
    function sync() {
      var custom = form.querySelector('input[name=range]:checked').value === 'custom';
      dates.hidden = !custom;
      dates.querySelectorAll('input').forEach(function (i) { i.disabled = !custom; });
      btn.textContent = format() === 'png' ? 'Download image' : 'Download PDF';
    }
    form.querySelectorAll('input[name=range], input[name=format]').forEach(function (r) { r.addEventListener('change', sync); });
    sync();

    function say(text, bad) { msg.hidden = !text; msg.textContent = text || ''; msg.className = 'u-export-msg' + (bad ? ' bad' : ''); }
    function loadLibrary() {
      if (window.htmlToImage) return Promise.resolve();
      return new Promise(function (resolve, reject) {
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/html-to-image@1.11.11/dist/html-to-image.js';
        s.onload = resolve;
        s.onerror = function () { reject(new Error('Could not load the image tool. Check the internet connection, or use PDF.')); };
        document.head.appendChild(s);
      });
    }

    form.addEventListener('submit', async function (e) {
      if (format() !== 'png') return; // PDF: normal download
      e.preventDefault();
      var dateFrom = form.querySelector('input[name=from]'), dateTo = form.querySelector('input[name=to]');
      if (!dates.hidden && dateFrom.value > dateTo.value) { say('The start date must be on or before the end date.', true); return; }
      btn.disabled = true;
      say('Making the image...');
      var frame = document.createElement('iframe');
      frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:1123px;height:800px;border:0';
      document.body.appendChild(frame);
      try {
        var url = form.action + '?' + new URLSearchParams(new FormData(form)).toString();
        var res = await fetch(url, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
        if (!res.ok) throw new Error(res.status === 422 || res.redirected ? 'Check the dates.' : 'The report could not be made (' + res.status + ').');
        var html = await res.text();
        await loadLibrary();
        await new Promise(function (resolve) { frame.onload = resolve; frame.srcdoc = html; });
        var body = frame.contentDocument.body;
        frame.style.height = body.scrollHeight + 'px';
        var png = await window.htmlToImage.toPng(body, { pixelRatio: 2, backgroundColor: '#ffffff', width: body.scrollWidth, height: body.scrollHeight });
        var a = document.createElement('a');
        a.href = png;
        a.download = 'users-report-' + (dates.hidden ? form.querySelector('input[name=range]:checked').value : dateFrom.value + '-to-' + dateTo.value)
          + '-' + new Date().toISOString().slice(0, 16).replace(/[-:T]/g, '') + '.png';
        document.body.appendChild(a);
        a.click();
        a.remove();
        say('');
      } catch (err) {
        say(err.message || 'The image could not be made.', true);
      } finally {
        frame.remove();
        btn.disabled = false;
      }
    });
  });
</script>
@endsection
