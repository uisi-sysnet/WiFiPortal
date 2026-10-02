@extends('layouts.app')

@section('title', 'Logs | Public WiFi Control')
@section('body-class', 'logs-full')

@push('head')
<style>
:root{--line:#0e670d;--ink:#0F1A1F;--ink-2:#5c6b66;--hover:#F0F3F1}
body.logs-full main{max-width:none}
.lg-title{margin:0 0 4px;font-size:1.35rem;font-weight:700;color:var(--ink)}
.lg-lede{margin:0 0 14px;color:var(--ink-2);font-size:.88rem}
.lg-tabs{display:flex;gap:4px;margin-bottom:14px;border-bottom:2px solid var(--line)}
.lg-tabs a{padding:8px 16px;font-size:.88rem;font-weight:600;color:var(--ink-2);text-decoration:none;border-radius:6px 6px 0 0}
.lg-tabs a:hover{background:var(--hover);color:var(--ink)}
.lg-tabs a[aria-current=page]{background:#0e670d;color:#fff}
.lg-panel{background:#fff;border:2px solid var(--line);border-radius:8px;overflow:hidden}
.lg-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:8px 14px;border-bottom:1px solid var(--line)}
.lg-control{height:31px;padding:0 9px;font:inherit;font-size:.82rem;border:1px solid var(--line);border-radius:6px;background:#fff;color:var(--ink)}
.lg-filters input[type=search]{min-width:260px}
.lg-filters label.lg-date{display:inline-flex;align-items:center;gap:5px;font-size:.78rem;color:var(--ink-2)}
.lg-btn{display:inline-flex;align-items:center;height:31px;padding:0 12px;font:inherit;font-size:.82rem;font-weight:600;border-radius:6px;border:1px solid #0e670d;background:#0e670d;color:#fff;cursor:pointer;text-decoration:none}
.lg-btn.quiet{background:#fff;color:#0e670d}
.lg-right{margin-left:auto;display:flex;gap:8px}
.lg-list{list-style:none;margin:0;padding:0}
.lg-list li{display:grid;grid-template-columns:150px 110px minmax(0,1fr);gap:12px;padding:9px 14px;border-bottom:1px solid color-mix(in srgb,#0e670d 18%,transparent);font-size:.85rem}
.lg-list li:hover{background:var(--hover)}
.lg-list time{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.78rem;color:var(--ink-2)}
.lg-list small{display:block;color:var(--ink-2);font-size:.78rem;margin-top:2px}
.lg-level{display:inline-flex;align-items:center;gap:6px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.lg-level::before{content:"";width:8px;height:8px;border-radius:50%;background:currentColor}
.lg-level.down{color:#B3372E}.lg-level.warn{color:#B07800}.lg-level.ok{color:#0e670d}.lg-level.info{color:#2C6AA8}
.lg-empty{padding:40px 20px;text-align:center;color:var(--ink-2)}
.lg-foot{padding:8px 14px}
.lg-wrap{overflow-x:auto}
table.lg-table{width:100%;min-width:1000px;border-collapse:collapse;font-size:.84rem}
.lg-table th,.lg-table td{padding:8px 12px;border-bottom:1px solid color-mix(in srgb,#0e670d 18%,transparent);text-align:left;vertical-align:top}
.lg-table th{font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-2);background:#FCFDFC}
.lg-table tbody tr:hover{background:var(--hover)}
.lg-table td small{display:block;color:var(--ink-2);font-size:.75rem;margin-top:1px}
.lg-id{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.8rem;color:var(--ink-2);white-space:nowrap}
.lg-when{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.78rem;white-space:nowrap}
.lg-act{display:inline-block;padding:2px 8px;border-radius:999px;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap;background:#EEF3EF;color:var(--ink-2)}
.lg-act.created{background:#E2F1E3;color:#0e670d}
.lg-act.updated,.lg-act.settings{background:#E2EBF7;color:#1d4fa3}
.lg-act.deleted,.lg-act.sign_in_failed{background:#FBECEA;color:#B3372E}
.lg-act.generated,.lg-act.sent{background:#FFF5DE;color:#8A5A00}
.lg-act.provisioned,.lg-act.checked{background:#EDE7F6;color:#5B3A9A}
.lg-changes summary{cursor:pointer;color:#0e670d;font-size:.78rem;margin-top:3px}
.lg-changes table{margin-top:6px;border-collapse:collapse;font-size:.78rem}
.lg-changes td{padding:2px 10px 2px 0;border:0;vertical-align:top}
.lg-changes .f{color:var(--ink-2);white-space:nowrap}
.lg-changes .old{color:#B3372E;text-decoration:line-through}
.lg-changes .new{color:#0e670d}
.lg-error{margin:0;padding:8px 14px;color:#B3372E;font-size:.84rem}
@media (max-width:760px){.lg-list li{grid-template-columns:1fr}.lg-right{margin-left:0}}
</style>
@endpush

@section('content')
@php
  $tz = config('hotspot.history.timezone');
  $show = fn ($v) => $v === null || $v === '' ? '(empty)' : (is_scalar($v) ? (string) $v : json_encode($v));
@endphp
<h1 class="lg-title">Logs</h1>
<p class="lg-lede">What people did in the system, and what happened on the network. Opening pages is not logged.</p>

<nav class="lg-tabs" aria-label="Logs">
  <a href="{{ route('logs') }}" @if($tab === 'activity') aria-current="page" @endif>User activity</a>
  <a href="{{ route('logs', ['tab' => 'events']) }}" @if($tab === 'events') aria-current="page" @endif>Network events</a>
</nav>

@if ($tab === 'activity')
  <div class="lg-panel">
    <form class="lg-filters" method="GET" action="{{ route('logs') }}">
      <label class="sr-only" for="lg-q">Search</label>
      <input id="lg-q" class="lg-control" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Log ID, name, device, IP...">
      <select class="lg-control" name="user" aria-label="User" onchange="this.form.submit()">
        <option value="">Everyone</option>
        @foreach ($people as $p)<option value="{{ $p->id }}" @selected((int) ($filters['user'] ?? 0) === $p->id)>{{ $p->name }}</option>@endforeach
      </select>
      <select class="lg-control" name="action" aria-label="Action" onchange="this.form.submit()">
        <option value="">All actions</option>
        @foreach (\App\Models\ActivityLog::ACTIONS as $k => $l)<option value="{{ $k }}" @selected(($filters['action'] ?? '') === $k)>{{ $l }}</option>@endforeach
      </select>
      <select class="lg-control" name="type" aria-label="Type" onchange="this.form.submit()">
        <option value="">All types</option>
        @foreach ($types as $t)<option value="{{ $t }}" @selected(($filters['type'] ?? '') === $t)>{{ ucfirst($t) }}</option>@endforeach
      </select>
      <label class="lg-date">From <input class="lg-control" type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label>
      <label class="lg-date">to <input class="lg-control" type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label>
      <button class="lg-btn" type="submit">Search</button>
      <span class="lg-right">
        @if (array_filter($filters))<a class="lg-btn quiet" href="{{ route('logs') }}">Clear</a>@endif
        <a class="lg-btn quiet" href="{{ route('logs.activity-csv', array_filter($filters)) }}">Download CSV</a>
      </span>
    </form>
    @foreach (['to', 'from'] as $f)
      @error($f)<p class="lg-error">{{ $message }}</p>@enderror
    @endforeach

    @if ($activity->isEmpty())
      <p class="lg-empty">No activity{{ array_filter($filters) ? ' matches these filters' : ' yet' }}.</p>
    @else
      <div class="lg-wrap">
        <table class="lg-table">
          <thead>
            <tr><th scope="col">Log ID</th><th scope="col">Date and time</th><th scope="col">User</th><th scope="col">Action</th><th scope="col">What</th><th scope="col">IP address</th></tr>
          </thead>
          <tbody>
            @foreach ($activity as $a)
              <tr>
                <td class="lg-id">#{{ $a->id }}</td>
                <td class="lg-when">{{ $a->created_at->copy()->setTimezone($tz)->format('M j, Y') }}<small>{{ $a->created_at->copy()->setTimezone($tz)->format('H:i:s') }}</small></td>
                <td>
                  <b>{{ $a->user_name ?? 'Unknown' }}</b>
                  <small>{{ \App\Models\User::ROLES[$a->user_role]['label'] ?? '' }}{{ $a->user_id === null && $a->user_name && $a->action !== 'sign_in_failed' ? ' (account deleted)' : '' }}</small>
                </td>
                <td><span class="lg-act {{ $a->action }}">{{ $a->actionLabel() }}</span></td>
                <td>
                  {{ $a->description }}
                  @if ($a->subject_type)<small>{{ ucfirst($a->subject_type) }}{{ $a->subject_id ? ' #'.$a->subject_id : '' }}</small>@endif
                  @if ($a->changes)
                    <details class="lg-changes">
                      <summary>{{ count($a->changes) }} {{ Str::plural('detail', count($a->changes)) }}</summary>
                      <table>
                        @foreach ($a->changes as $field => [$old, $new])
                          <tr>
                            <td class="f">{{ str_replace('_', ' ', $field) }}</td>
                            <td>
                              @if ($old !== null && $new !== null)<span class="old">{{ $show($old) }}</span> &rarr; <span class="new">{{ $show($new) }}</span>
                              @elseif ($new !== null)<span class="new">{{ $show($new) }}</span>
                              @else<span class="old">{{ $show($old) }}</span>@endif
                            </td>
                          </tr>
                        @endforeach
                      </table>
                    </details>
                  @endif
                </td>
                <td class="lg-id">{{ $a->ip ?? '–' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      @if ($activity->hasPages())<div class="lg-foot">{{ $activity->links() }}</div>@endif
    @endif
  </div>

@else
  <div class="lg-panel">
    <form class="lg-filters" method="GET" action="{{ route('logs') }}">
      <input type="hidden" name="tab" value="events">
      <label class="sr-only" for="lg-q2">Search</label>
      <input id="lg-q2" class="lg-control" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Device name, barangay, error...">
      <select class="lg-control" name="level" onchange="this.form.submit()" aria-label="Level">
        <option value="">All levels</option>
        @foreach (\App\Models\SystemEvent::LEVELS as $k => $l)<option value="{{ $k }}" @selected(($filters['level'] ?? '') === $k)>{{ $l }}</option>@endforeach
      </select>
      <select class="lg-control" name="kind" onchange="this.form.submit()" aria-label="What">
        <option value="">Everything</option>
        @foreach (\App\Models\SystemEvent::KINDS as $k => $l)<option value="{{ $k }}" @selected(($filters['kind'] ?? '') === $k)>{{ $l }}</option>@endforeach
      </select>
      <button class="lg-btn" type="submit">Search</button>
    </form>

    @if ($events->isEmpty())
      <p class="lg-empty">No events{{ array_filter($filters) ? ' match these filters' : ' yet' }}.</p>
    @else
      <ul class="lg-list">
        @foreach ($events as $e)
          <li>
            <time datetime="{{ $e->created_at->toIso8601String() }}">{{ $e->created_at->copy()->setTimezone($tz)->format('M j, Y H:i:s') }}</time>
            <span class="lg-level {{ $e->level }}">{{ \App\Models\SystemEvent::LEVELS[$e->level] ?? $e->level }}</span>
            <span><b>{{ $e->title }}</b>@if ($e->detail)<small>{{ $e->detail }}</small>@endif</span>
          </li>
        @endforeach
      </ul>
      @if ($events->hasPages())<div class="lg-foot">{{ $events->links() }}</div>@endif
    @endif
  </div>
@endif
@endsection
