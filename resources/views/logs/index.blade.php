@extends('layouts.app')

@section('title', 'Logs | Public WiFi Control')
@section('body-class', 'wide')

@push('head')
<style>
:root{--line:#0e670d;--ink:#0F1A1F;--ink-2:#5c6b66;--hover:#F0F3F1}
body.wide main{max-width:1400px}
.lg-title{margin:0 0 4px;font-size:1.35rem;font-weight:700;color:var(--ink)}
.lg-lede{margin:0 0 14px;color:var(--ink-2);font-size:.88rem}
.lg-panel{background:#fff;border:2px solid var(--line);border-radius:8px;overflow:hidden}
.lg-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:8px 14px;border-bottom:1px solid var(--line)}
.lg-control{height:31px;padding:0 9px;font:inherit;font-size:.82rem;border:1px solid var(--line);border-radius:6px;background:#fff;color:var(--ink)}
.lg-filters input[type=search]{min-width:260px}
.lg-btn{height:31px;padding:0 12px;font:inherit;font-size:.82rem;font-weight:600;border-radius:6px;border:1px solid #0e670d;background:#0e670d;color:#fff;cursor:pointer}
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
@media (max-width:760px){.lg-list li{grid-template-columns:1fr}}
</style>
@endpush

@section('content')
@php $tz = config('hotspot.history.timezone'); @endphp
<h1 class="lg-title">Logs</h1>
<p class="lg-lede">Everything that happened on the network: routers, access points and switches going down and coming back, busy or full routers and networks, and reports sent. The same events go to Telegram when it is switched on in <a href="{{ route('settings') }}#telegram">Settings</a>.</p>

<div class="lg-panel">
  <form class="lg-filters" method="GET" action="{{ route('logs') }}">
    <label class="sr-only" for="lg-q">Search</label>
    <input id="lg-q" class="lg-control" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Device name, barangay, error...">
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
@endsection
