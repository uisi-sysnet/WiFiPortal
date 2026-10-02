@extends('layouts.app')

@section('title', $info['plural'].' | Public WiFi Control')
@section('body-class', 'wide')

@push('head')
<meta name="csrf-token" content="{{ csrf_token() }}">
<style>
/* ---- Primary green theme ---- */
:root {
  --signal: #0e670d;
  --signal-soft: color-mix(in srgb, #0e670d 12%, #fff);
  --signal-hover: color-mix(in srgb, #0e670d 85%, #000);
  --line: #0e670d;
  --ink: #0F1A1F;
  --ink-2: #5c6b66;
  --paper: #fff;
  --hover: #F0F3F1;
  --fail: #B3372E;
}

/* ---- Prevent page scroll ---- */
html, body { height: 100%; overflow: hidden; }
body.wide main { max-width: 1600px; height: 100%; overflow: hidden; }
.page-wrapper { height: calc(100vh - 95px); display: flex; flex-direction: column; overflow: hidden; }

/* ============================================================
   MODAL (shared by Add and Edit)
   ============================================================ */
dialog.modal { width: min(920px, calc(100vw - 24px)); max-height: calc(100vh - 32px); padding: 0; border: 0; border-radius: 8px; color: var(--ink); background: var(--paper); box-shadow: 0 28px 80px rgba(10, 20, 26, .38); overflow: hidden; }
dialog.modal::backdrop { background: rgba(10, 20, 26, .55); backdrop-filter: blur(2px); }
dialog.modal[open] { display: flex; }
.modal-form { display: flex; flex-direction: column; width: 100%; max-height: calc(100vh - 32px); margin: 0; }
.modal-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 20px; background: #fff; border-bottom: 1px solid var(--line); }
.modal-head h2 { margin: 0; font-size: 1rem; font-weight: 600; letter-spacing: -0.01em; }
.modal-x { display: grid; place-items: center; width: 32px; height: 32px; border: 0; border-radius: 6px; background: none; color: var(--ink-2); cursor: pointer; transition: background .15s, color .15s; }
.modal-x:hover { background: var(--hover); color: var(--ink); }
.modal-body { flex: 1; overflow: auto; padding: 20px 20px 12px; }
.modal-body .alert { margin-bottom: 16px; }
.modal-foot { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 14px 20px; background: #fff; border-top: 1px solid var(--line); }
.modal-foot .foot-left { display: flex; align-items: center; gap: 10px; font-size: .78rem; color: var(--ink-2); }
.modal-foot .foot-right { display: flex; align-items: center; gap: 8px; }
.modal-foot .status-chip { display: inline-flex; align-items: center; gap: 6px; font-weight: 600; }
.modal-foot .status-chip::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: #B8C2BD; }
.modal-foot .status-chip.online { color: #0e670d; }
.modal-foot .status-chip.online::before { background: #0e670d; }
.modal-foot .status-chip.offline { color: var(--fail); }
.modal-foot .status-chip.offline::before { background: var(--fail); }
@media (max-width: 640px) { .modal-head, .modal-body, .modal-foot { padding-left: 16px; padding-right: 16px; } }

/* ============================================================
   PAGE LAYOUT
   ============================================================ */
.page-head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 8px 20px; margin-bottom: 0; padding: 12px 16px; background: #fff; border: 2px solid var(--line); border-bottom: 0; border-radius: 8px 8px 0 0; flex: none; }
.page-head h1 { margin: 0; font-size: 1.05rem; font-weight: 700; letter-spacing: -0.01em; text-transform: uppercase; color: var(--ink); }
.page-head .lede { margin: 0; color: var(--ink-2); font-size: .82rem; }
.panel { flex: 1; min-height: 0; display: flex; flex-direction: column; background: #fff; border: 2px solid var(--line); border-top: 1px solid var(--line); border-radius: 0 0 8px 8px; box-shadow: 0 1px 3px rgba(10, 20, 26, .04); overflow: hidden; }
.panel-head { flex: none; display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; padding: 8px 14px; border-bottom: 1px solid var(--line); background: #fff; }
.panel-head h2 { margin: 0; font-size: .75rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--ink-2); }
.panel-head .head-right { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-left: auto; }

/* Status chips */
.stat-group { display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.stat-chip { display: inline-flex; align-items: center; gap: 5px; height: 22px; padding: 0 8px; border-radius: 999px; font-size: .68rem; font-weight: 600; line-height: 1; white-space: nowrap; border: 1px solid transparent; text-transform: uppercase; letter-spacing: .04em; }
.stat-chip::before { content: ''; width: 5px; height: 5px; border-radius: 50%; flex: none; }
.stat-chip.online { color: #0e670d; background: color-mix(in srgb, #0e670d 10%, #fff); border-color: color-mix(in srgb, #0e670d 30%, transparent); }
.stat-chip.online::before { background: #0e670d; }
.stat-chip.offline { color: #B3372E; background: color-mix(in srgb, var(--fail) 10%, #fff); border-color: color-mix(in srgb, var(--fail) 28%, transparent); }
.stat-chip.offline::before { background: var(--fail); }
.stat-chip.unknown { color: #5c6b66; background: #F0F3F1; border-color: var(--line); }
.stat-chip.unknown::before { background: #A7B4AD; }

/* Controls */
.control { display: inline-flex; align-items: center; height: 30px; padding: 0 10px; font: inherit; font-size: .82rem; font-weight: 500; line-height: 1; border: 1px solid var(--line); border-radius: 6px; background: #fff; color: var(--ink); box-sizing: border-box; transition: border-color .15s, box-shadow .15s, background .15s, color .15s; }
.control:hover { background: var(--hover); }
.control:focus { outline: none; border-color: #0e670d; box-shadow: 0 0 0 3px var(--signal-soft); }
.filter-form { display: inline-flex; align-items: center; gap: 6px; margin: 0; }
.filter-form select.control { min-width: 150px; padding-right: 28px; appearance: none; background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%235c6b66' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><path d='M6 9l6 6 6-6'/></svg>"); background-repeat: no-repeat; background-position: right 8px center; cursor: pointer; }
.btn.control { cursor: pointer; }
.btn.control.primary { border-color: #0e670d; background: #0e670d; color: #fff; }
.btn.control.primary:hover { background: var(--signal-hover); color: #fff; border-color: var(--signal-hover); }
.btn.control.quiet { color: var(--ink); border-color: var(--line); background: #fff; }
.btn.control.quiet:hover { background: var(--hover); }

/* Bulk bar */
.bulk { flex: none; display: flex; flex-wrap: wrap; align-items: center; gap: 6px 10px; margin: 0; padding: 8px 14px; background: color-mix(in srgb, #0e670d 6%, #fff); color: var(--ink); border-bottom: 1px solid var(--line); animation: bulk-in .2s ease; }
.bulk[hidden] { display: none; }
.bulk strong { margin-right: auto; font-weight: 600; font-size: .82rem; color: #0e670d; }
.bulk .btn { display: inline-flex; align-items: center; height: 28px; padding: 0 10px; font: inherit; font-size: .78rem; font-weight: 500; line-height: 1; border: 1px solid var(--line); border-radius: 6px; background: #fff; color: var(--ink); cursor: pointer; box-sizing: border-box; transition: background .15s, border-color .15s, color .15s; }
.bulk .btn.quiet { color: var(--ink); border-color: var(--line); background: #fff; }
.bulk .btn.quiet:hover { background: var(--hover); }
.bulk .btn.danger { color: var(--fail); border-color: color-mix(in srgb, var(--fail) 40%, transparent); background: #fff; }
.bulk .btn.danger:hover { background: color-mix(in srgb, var(--fail) 8%, #fff); }
.bulk .link { background: none; border: 0; color: var(--ink-2); font: inherit; font-size: .78rem; text-decoration: underline; text-underline-offset: 2px; cursor: pointer; padding: 4px 0; }
.bulk .link:hover { color: var(--ink); }
@keyframes bulk-in { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }

/* ============================================================
   TABLE
   ============================================================ */
.table-wrap { flex: 1; min-height: 0; overflow: auto; }
table.inventory { width: 100%; min-width: 1080px; border-collapse: collapse; font-size: .70rem; table-layout: fixed; }
table.inventory th, table.inventory td { padding: 6px 10px; vertical-align: middle; border-bottom: 1px solid var(--line); text-align: left; overflow: hidden; text-overflow: ellipsis; }
table.inventory th { font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--ink-2); background: #FCFDFC; position: sticky; top: 0; z-index: 1; white-space: normal; line-height: 1.2; }
table.inventory tbody tr { transition: background .12s; }
table.inventory tbody tr:last-child td { border-bottom: 0; }
table.inventory tbody tr:hover { background: var(--hover); }
table.inventory tbody tr.selected { background: var(--signal-soft); }
table.inventory tbody tr.selected:hover { background: color-mix(in srgb, var(--signal-soft) 85%, #fff); }
table.inventory .sel { width: 36px; text-align: center; padding-right: 2px; }
table.inventory .sel input { width: 14px; height: 14px; accent-color: #0e670d; cursor: pointer; vertical-align: middle; }
table.inventory .num { width: 44px; color: var(--ink-2); text-align: right; font-variant-numeric: tabular-nums; font-size: .78rem; }
table.inventory th:nth-child(1), table.inventory td:nth-child(1) { width: 36px; text-align: center; }
table.inventory th:nth-child(2), table.inventory td:nth-child(2) { width: 46px; text-align: right; }
table.inventory th:nth-child(3), table.inventory td:nth-child(3) { width: 150px; }
table.inventory th:nth-child(4), table.inventory td:nth-child(4) { width: 96px; }
table.inventory th:nth-child(5), table.inventory td:nth-child(5) { width: 110px; }
table.inventory th:nth-child(6), table.inventory td:nth-child(6) { width: 130px; }
table.inventory th:nth-child(7), table.inventory td:nth-child(7) { width: 130px; }
table.inventory th:nth-child(8), table.inventory td:nth-child(8) { width: 130px; }
table.inventory th:nth-child(9), table.inventory td:nth-child(9) { width: 130px; }
table.inventory th:nth-child(10), table.inventory td:nth-child(10) { width: 110px; }
table.inventory th:nth-child(11), table.inventory td:nth-child(11) { width: 110px; }
table.inventory th:nth-child(12), table.inventory td:nth-child(12) { width: 150px; white-space: normal; }
table.inventory th:nth-child(13), table.inventory td:nth-child(13) { width: 120px; }
table.inventory th:nth-child(14), table.inventory td:nth-child(14) { width: 100px; }
table.inventory th:nth-child(15), table.inventory td:nth-child(15) { width: 120px; text-align: center; }
table.inventory td { vertical-align: middle; }
table.inventory td .dev { display: flex; align-items: center; gap: 6px; font-weight: 600; letter-spacing: -0.01em; color: var(--ink); }
.dot { flex: none; width: 7px; height: 7px; border-radius: 50%; background: #B8C2BD; }
.dot.online { background: #0e670d; box-shadow: 0 0 0 2px color-mix(in srgb, #0e670d 20%, transparent); }
.dot.offline { background: var(--fail); box-shadow: 0 0 0 2px color-mix(in srgb, var(--fail) 16%, transparent); }
.pill { display: inline-flex; align-items: center; gap: 4px; padding: 1px 7px; border-radius: 999px; font-size: .68rem; font-weight: 600; line-height: 1.3; white-space: nowrap; text-transform: uppercase; letter-spacing: .04em; }
.pill.online { color: #0e670d; background: color-mix(in srgb, #0e670d 12%, #fff); border: 1px solid color-mix(in srgb, #0e670d 32%, transparent); }
.pill.offline { color: #B3372E; background: color-mix(in srgb, var(--fail) 10%, #fff); border: 1px solid color-mix(in srgb, var(--fail) 28%, transparent); }
.pill.unknown { color: #5c6b66; background: #F0F3F1; border: 1px solid var(--line); }
.pill .dot { width: 5px; height: 5px; box-shadow: none; }
.none { color: #A7B4AD; }
.mono { font-variant-numeric: tabular-nums; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: .78rem; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
table.inventory td small { display: block; color: var(--ink-2); font-size: .72rem; margin-top: 1px; white-space: normal; }
table.inventory a { color: #0e670d; text-decoration: none; }
table.inventory a:hover { text-decoration: underline; text-underline-offset: 2px; }
.acts { display: flex; gap: 4px; justify-content: flex-end; }
.acts form { margin: 0; }
.acts .btn { display: inline-flex; align-items: center; height: 26px; padding: 0 8px; font-size: .65rem; font-weight: 500; border-radius: 6px; border: 1px solid var(--line); background: #fff; color: var(--ink); text-decoration: none; cursor: pointer; transition: background .15s, border-color .15s, color .15s; }
.acts .btn:hover { background: var(--hover); }
.acts .btn.danger { color: #B3372E; border-color: color-mix(in srgb, var(--fail) 35%, transparent); }
.acts .btn.danger:hover { background: color-mix(in srgb, var(--fail) 8%, #fff); }
.plan { text-align: center; padding: 36px 24px; }
.plan h2 { margin: 0 0 6px; font-size: 1rem; font-weight: 650; color: var(--ink); }
.plan p { margin: 0; color: var(--ink-2); max-width: 36ch; margin-inline: auto; font-size: .85rem; }
.pagination-wrap {
  flex: none;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 12px;
  padding: 8px 14px;
  border-top: 1px solid var(--line);
  color-scheme: light;
  color: var(--ink);
  background: #fff;
}
.pagination-wrap .stat-group { margin-right: auto; }
.pagination-wrap nav a,
.pagination-wrap nav span {
  color: var(--ink) !important;
  background-color: #fff !important;
  border-color: var(--line) !important;
}
.pagination-wrap nav a:hover { background-color: var(--hover) !important; }

/* ============================================================
   RESPONSIVE — mobile cards (< 760px)
   ============================================================ */
@media (max-width: 760px) {
  .panel-head { flex-direction: column; align-items: stretch; gap: 8px; }
  .panel-head .head-right { margin-left: 0; width: 100%; }
  .filter-form { width: 100%; }
  .filter-form select.control { flex: 1; min-width: 0; }
  .btn.control { flex: 0 0 auto; }
  .table-wrap { overflow: auto; }
  table.inventory { min-width: 0; display: block; }
  table.inventory thead { display: none; }
  table.inventory tbody { display: block; }
  table.inventory tbody tr { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 10px; padding: 10px 12px; border-bottom: 1px solid var(--line); background: #fff; }
  table.inventory tbody tr:hover, table.inventory tbody tr.selected { background: var(--signal-soft); }
  table.inventory tbody tr:last-child { border-bottom: 0; }
  table.inventory td { display: flex; flex-direction: column; gap: 2px; width: auto !important; padding: 0; border: 0; overflow: visible; text-overflow: clip; white-space: normal; text-align: left !important; }
  table.inventory td::before { content: attr(data-label); font-size: .6rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--ink-2); }
  table.inventory td[data-label="Device name"], table.inventory td[data-label="Location"] { grid-column: 1 / -1; }
  table.inventory td[data-label=""], table.inventory td[data-label="No."] { flex-direction: row; align-items: center; gap: 8px; }
  table.inventory td[data-label=""]::before, table.inventory td[data-label="No."]::before { display: none; }
  table.inventory td[data-label="Actions"] { border-top: 1px solid var(--line); padding-top: 8px; margin-top: 4px; grid-column: 1 / -1; }
  .acts { justify-content: flex-start; }
  table.inventory td[data-label="Device name"] .dev { font-size: .95rem; }
}

@media (max-width: 420px) {
  .page-head { padding: 10px 12px; }
  .page-head h1 { font-size: .9rem; }
  .page-head .lede { font-size: .75rem; }
  .stat-chip { font-size: .62rem; height: 20px; padding: 0 7px; }
  .bulk { padding: 6px 10px; }
  .bulk strong { font-size: .75rem; }
  .bulk .btn { height: 26px; font-size: .72rem; padding: 0 8px; }
}
</style>
@endpush

@section('content')
<div class="page-wrapper">

  {{-- Page header --}}
  <div class="page-head">
    <h1>Manage {{ $info['plural'] }}</h1>
    <p class="lede">Checked over SNMP every minute. The dot beside each name shows whether it is online.</p>
  </div>

  @if (session('status'))
    <div class="notice" role="status">{{ session('status') }}</div>
  @endif
  @error('ids')
    <div class="alert" role="alert">{{ $message }}</div>
  @enderror

  @if ($devices->isEmpty() && ! $filter && $devices->currentPage() === 1)
    <div class="panel">
      <div class="plan">
        <h2>No {{ strtolower($info['plural']) }} yet</h2>
        <p>Add one with its barangay, map position, IP address and SNMP details. It is checked right away.</p>
        <p style="margin-top:18px">
          <a class="btn control primary" href="{{ route($info['route'].'.index', ['add' => 1]) }}" data-open-add>
            + Add {{ strtolower($info['label']) }}
          </a>
        </p>
      </div>
    </div>
  @else
    <div class="panel">
      <div class="panel-head">
        <div class="head-right">
          <form class="filter-form" method="GET" action="{{ route($info['route'].'.index') }}">
            <select id="barangay" name="barangay" class="control" onchange="this.form.submit()">
              <option value="">All barangays</option>
              @foreach ($barangays as $b)
                <option value="{{ $b->id }}" @selected($filter === $b->id)>{{ $b->name }}</option>
              @endforeach
            </select>
            <noscript><button class="btn control quiet" type="submit">Show</button></noscript>
          </form>

          {{-- NEW: Export to Excel --}}
          <button class="btn control quiet" type="button" onclick="document.getElementById('export-columns').showModal()"
            title="Choose columns to include in the Excel file">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
                style="margin-right:6px">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
              <polyline points="7 10 12 15 17 10"/>
              <line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            Export to Excel
          </button>

          <a class="btn control primary" href="{{ route($info['route'].'.index', ['add' => 1]) }}" data-open-add>
            + Add {{ strtolower($info['label']) }}
          </a>
        </div>
      </div>

      <dialog class="modal" id="export-columns" aria-labelledby="export-title">
        <form class="modal-form" method="GET" action="{{ route($info['route'].'.export') }}">
          <input type="hidden" name="barangay" value="{{ $filter }}">
          <div class="modal-head">
            <h2 id="export-title">Choose columns to export</h2>
            <button type="button" class="modal-x" onclick="document.getElementById('export-columns').close()" aria-label="Close">×</button>
          </div>
          <div class="modal-body">
            <p>Select the fields to include in your Excel file.</p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px 16px">
              @foreach (['No.', 'Device name', 'Type', 'Status', 'Brand', 'Model', 'Firmware', 'MAC address', 'Serial number', 'Barangay', 'Location', 'Latitude', 'Longitude', 'Map link', 'Deployed on', 'Warranty', 'Site router', 'Connected to', 'IP address', 'SNMP port', 'SNMP version', 'Uptime', 'Clients', 'Utilization %', 'Failures', 'Last seen', 'Last checked', 'Last error', 'Added', 'Updated'] as $columnLabel)
                <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="columns[]" value="{{ $loop->index }}" checked> {{ $columnLabel }}</label>
              @endforeach
            </div>
          </div>
          <div class="modal-foot">
            <span class="foot-left">All columns are selected by default.</span>
            <div class="foot-right">
              <button type="button" class="btn control quiet" onclick="document.getElementById('export-columns').close()">Cancel</button>
              <button type="submit" class="btn control primary">Export to Excel</button>
            </div>
          </div>
        </form>
      </dialog>

      {{-- Bulk actions bar --}}
      <form id="bulk-form" method="POST" action="{{ route('devices.bulk') }}">
        @csrf
        <div class="bulk" id="bulk-bar" role="region" aria-label="Selected devices" hidden>
          <strong id="bulk-count" aria-live="polite">0 selected</strong>
          <button class="btn quiet" type="submit" name="action" value="check">Check status now</button>
          <button class="btn danger" type="submit" name="action" value="delete" id="bulk-delete">Delete selected</button>
          <button class="link" type="button" id="bulk-clear">Clear selection</button>
        </div>
      </form>

      @if ($devices->isEmpty())
        <div class="plan">
          <p>No {{ strtolower($info['plural']) }} in this barangay yet.</p>
        </div>
      @else
        <div class="table-wrap [scrollbar-width:thin]">
          <table class="inventory">
            <thead>
              <tr>
                <th scope="col" class="sel">
                  <input type="checkbox" id="select-all" aria-label="Select all on this page">
                </th>
                <th scope="col" class="num">No.</th>
                <th scope="col">Device name</th>
                <th scope="col">Status</th>
                <th scope="col">Brand</th>
                <th scope="col">Device model</th>
                <th scope="col">IP address</th>
                <th scope="col">MAC address</th>
                <th scope="col">Serial number</th>
                <th scope="col">Firmware</th>
                <th scope="col">Warranty</th>
                <th scope="col">Location</th>
                <th scope="col">Lat, long</th>
                <th scope="col">Deployed on</th>
                <th scope="col">Actions</th>
              </tr>
            </thead>
            <tbody>
            @foreach ($devices as $d)
              @php $dash = '<span class="none">–<span class="sr-only">Not set</span></span>'; @endphp
              <tr>
                <td class="sel" data-label="">
                  <input type="checkbox" name="ids[]" value="{{ $d->id }}" form="bulk-form" class="row-check" aria-label="Select {{ $d->name }}">
                </td>
                <td class="num" data-label="No.">{{ $devices->firstItem() + $loop->index }}</td>
                <td data-label="Device name">
                  <span class="dev" title="{{ $d->statusLabel() }}{{ $d->last_checked_at ? ', checked '.$d->last_checked_at->diffForHumans() : '' }}{{ $d->status === 'offline' && $d->last_error ? '. '.$d->last_error : '' }}">
                    {{ $d->name }}
                  </span>
                </td>
                <td data-label="Status">
                  <span class="pill {{ $d->status }}">
                    <span class="dot {{ $d->status }}" aria-hidden="true"></span>
                    {{ $d->statusLabel() }}
                  </span>
                </td>
                <td data-label="Brand">{!! $d->brand ? e($d->brand) : $dash !!}</td>
                <td data-label="Device model">{!! $d->model ? e($d->model) : $dash !!}</td>
                <td data-label="IP address">{{ $d->host }}@if ($d->snmp_port !== 161):{{ $d->snmp_port }}@endif</td>
                <td data-label="MAC address">{!! $d->mac_address ? e($d->mac_address) : $dash !!}</td>
                <td data-label="Serial number">{!! $d->serial_number ? e($d->serial_number) : $dash !!}</td>
                <td data-label="Firmware">{!! $d->firmware_version ? e($d->firmware_version) : $dash !!}</td>
                <td data-label="Warranty">{!! $d->warranty ? e($d->warranty) : $dash !!}</td>
                <td data-label="Location" style="white-space:normal; line-height:1.3">
                  {{ $d->barangay_name ?? 'No barangay' }}
                  @if ($d->location)<small>{{ $d->location }}</small>@endif
                </td>
                <td data-label="Lat, long">
                  @if ($d->hasCoordinates())
                    <a href="{{ $d->mapUrl() }}" target="_blank" rel="noopener" title="Open in Google Maps">
                      {{ (float) $d->latitude }}, {{ (float) $d->longitude }}
                    </a>
                  @else
                    {!! $dash !!}
                  @endif
                </td>
                <td data-label="Deployed on">{{ $d->deployed_at?->toDateString() ?? '—' }}</td>
                <td data-label="Actions">
                  <div class="acts">
                    {{--
                      EDIT: button carries every field value via data-* attributes.
                      data-edit-action gives JS the exact named-route URL (handles
                      prefixes, groups, and route-model binding keys).
                    --}}
                    <button type="button"
                            class="btn quiet sm"
                            data-edit-device="{{ $d->id }}"
                            data-edit-action="{{ route('devices.update', $d) }}"
                            data-edit-name="{{ $d->name }}"
                            data-edit-model="{{ $d->model }}"
                            data-edit-firmware="{{ $d->firmware_version }}"
                            data-edit-mac="{{ $d->mac_address }}"
                            data-edit-serial="{{ $d->serial_number }}"
                            data-edit-barangay="{{ $d->barangay_id }}"
                            data-edit-location="{{ $d->location }}"
                            data-edit-router="{{ $d->mikrotik_router_id }}"
                            data-edit-lat="{{ $d->latitude }}"
                            data-edit-lng="{{ $d->longitude }}"
                            data-edit-host="{{ $d->host }}"
                            data-edit-port="{{ $d->snmp_port }}"
                            data-edit-version="{{ $d->snmp_version }}"
                            data-edit-v3-user="{{ $d->v3_username }}"
                            data-edit-v3-level="{{ $d->v3_security_level }}"
                            data-edit-v3-auth="{{ $d->v3_auth_protocol }}"
                            data-edit-v3-priv="{{ $d->v3_priv_protocol }}"
                            data-edit-status="{{ $d->status }}"
                            data-edit-status-label="{{ $d->statusLabel() }}"
                            data-edit-checked="{{ $d->last_checked_at ? $d->last_checked_at->diffForHumans() : '' }}">
                      Edit
                    </button>

                    <form method="POST" action="{{ route('devices.destroy', $d) }}" onsubmit="return confirm('Delete {{ $d->name }}? It will no longer be monitored.')">
                      @csrf @method('DELETE')
                      <button class="btn danger sm" type="submit">Delete</button>
                    </form>
                  </div>
                </td>
              </tr>
            @endforeach
            </tbody>
          </table>
        </div>

        <div class="pagination-wrap">
          <div class="stat-group" role="group" aria-label="Device status summary">
            <span class="stat-chip online">{{ number_format($counts['online'] ?? 0) }} online</span>
            <span class="stat-chip offline">{{ number_format($counts['offline'] ?? 0) }} offline</span>
            @if (($counts['unknown'] ?? 0) > 0)
              <span class="stat-chip unknown">{{ number_format($counts['unknown']) }} not checked</span>
            @endif
          </div>
          {{ $devices->links() }}
        </div>
      @endif
    </div>
  @endif

  {{-- ============================================================
       ADD pop-up
       ============================================================ --}}
  @php $addFailed = $errors->any() && old('_form') === 'add'; @endphp
  <dialog class="modal" id="add-device" aria-labelledby="add-title">
    <form id="add-form" class="modal-form device-fields" method="POST" action="{{ route($info['route'].'.store') }}" novalidate>
      @csrf
      <input type="hidden" name="_form" value="add">
      <div class="modal-head">
        <h2 id="add-title">Add {{ strtolower($info['label']) }}</h2>
        <button type="button" class="modal-x" data-close-add aria-label="Close">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
            <path d="M18 6 6 18M6 6l12 12"/>
          </svg>
        </button>
      </div>
      <div class="modal-body">
        @if ($addFailed)
          <div class="alert" role="alert">Fix the highlighted fields and try again.</div>
        @endif
        @include('devices._fields', ['device' => $blank, 'editing' => false, 'formId' => 'add-form'])
      </div>
      <div class="modal-foot">
        <button type="button" class="btn quiet" data-close-add>Cancel</button>
        <button type="submit" class="btn">Add {{ strtolower($info['label']) }}</button>
      </div>
    </form>
  </dialog>

  {{-- ============================================================
       EDIT pop-up (single dialog, fields populated by JS)
       ============================================================ --}}
  @php $editFailed = $errors->any() && old('_form') === 'edit'; @endphp
  <dialog class="modal" id="edit-device" aria-labelledby="edit-title">
    <form id="edit-form" class="modal-form device-fields" method="POST" action="" novalidate>
      @csrf @method('PUT')
      <input type="hidden" name="_form" value="edit">
      <input type="hidden" name="device_id" value="">
      <input type="hidden" name="return_barangay" value="{{ $filter }}">

      <div class="modal-head">
        <h2 id="edit-title">Edit device</h2>
        <button type="button" class="modal-x" data-close-edit aria-label="Close">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
            <path d="M18 6 6 18M6 6l12 12"/>
          </svg>
        </button>
      </div>

      <div class="modal-body">
        @if ($editFailed)
          <div class="alert" role="alert">Fix the highlighted fields and try again.</div>
        @endif

        @php
          $editDevice = $editFailed && old('device_id')
            ? ($devices->firstWhere('id', (int) old('device_id')) ?? $blank)
            : $blank;
        @endphp
        @include('devices._fields', ['device' => $editDevice, 'editing' => true, 'formId' => 'edit-form'])
      </div>

      <div class="modal-foot">
        <div class="foot-left">
          <span class="status-chip" id="edit-status-chip">—</span>
          <span id="edit-status-meta"></span>
        </div>
        <div class="foot-right">
          <button type="button" class="btn quiet" data-close-edit>Cancel</button>
          <button type="submit" class="btn">Save changes</button>
        </div>
      </div>
    </form>
  </dialog>

  {{-- ============================================================
       Scripts
       ============================================================ --}}
  <script>
  (function () {
    /* ---------- ADD modal ---------- */
    const addDialog = document.getElementById('add-device');
    const addForm   = document.getElementById('add-form');

    function openAdd() {
      if (!addDialog || typeof addDialog.showModal !== 'function') return;
      addDialog.showModal();
      const first = addDialog.querySelector('[aria-invalid="true"]') || addForm.elements['name'];
      if (first) first.focus();
    }
    function closeAdd() { if (addDialog) addDialog.close(); }

    document.querySelectorAll('[data-open-add]').forEach((a) =>
      a.addEventListener('click', (e) => { e.preventDefault(); openAdd(); })
    );
    if (addDialog) {
      addDialog.querySelectorAll('[data-close-add]').forEach((b) =>
        b.addEventListener('click', closeAdd)
      );
      addDialog.addEventListener('click', (e) => { if (e.target === addDialog) closeAdd(); });
    }

    const url = new URL(location.href);
    if (url.searchParams.has('add') || @json($addFailed)) {
      openAdd();
      url.searchParams.delete('add');
      history.replaceState(null, '', url);
    }

    /* ---------- EDIT modal ---------- */
    const editDialog = document.getElementById('edit-device');
    const editForm   = document.getElementById('edit-form');
    const editTitle  = document.getElementById('edit-title');
    const chip       = document.getElementById('edit-status-chip');
    const meta       = document.getElementById('edit-status-meta');
    const idField    = editForm ? editForm.querySelector('input[name="device_id"]') : null;

    // Set a field by name (text/select/radio group)
    function setField(name, value) {
      if (!editForm) return;
      const els = editForm.querySelectorAll('[name="' + name + '"]');
      if (!els.length) return;

      if (els[0].type === 'radio') {
        els.forEach((r) => { r.checked = (String(r.value) === String(value ?? '')); });
        return;
      }
      els[0].value = (value ?? '');
    }

    // Dispatch an event so _fields' JS reacts (map, SNMP panels)
    function fire(el, type) {
      if (!el) return;
      el.dispatchEvent(new Event(type, { bubbles: true }));
    }

    function openEdit(btn) {
      if (!editDialog || typeof editDialog.showModal !== 'function') return;

      const d      = btn.dataset;
      const id     = d.editDevice;
      const name   = d.editName || 'device';
      const status = d.editStatus || 'unknown';

      // IMPORTANT: use the pre-built named-route URL from Blade.
      // Do NOT string-concat here — that's what caused the 404.
      if (d.editAction) {
        editForm.action = d.editAction;
      } else {
        // Safety net: only used if data-edit-action is missing.
        editForm.action = '{{ route("devices.update", ":id") }}'.replace(':id', encodeURIComponent(id));
      }
      if (idField) idField.value = id;

      // Header + footer
      editTitle.textContent = 'Edit ' + name;
      chip.className = 'status-chip ' + status;
      chip.textContent = d.editStatusLabel || status;
      meta.textContent = d.editChecked ? 'Checked ' + d.editChecked : '';

      // ---- Populate every field ----
      setField('name',               d.editName);
      setField('model',              d.editModel);
      setField('firmware_version',   d.editFirmware);
      setField('mac_address',        d.editMac);
      setField('serial_number',      d.editSerial);
      setField('barangay_id',        d.editBarangay);
      setField('location',           d.editLocation);
      setField('mikrotik_router_id', d.editRouter);
      setField('latitude',           d.editLat);
      setField('longitude',          d.editLng);
      setField('host',               d.editHost);
      setField('snmp_port',          d.editPort);
      setField('snmp_version',       d.editVersion);
      setField('v3_username',        d.editV3User);
      setField('v3_security_level',  d.editV3Level);
      setField('v3_auth_protocol',   d.editV3Auth);
      setField('v3_priv_protocol',   d.editV3Priv);

      // Passwords intentionally blank — server keeps existing when blank
      setField('community',          '');
      setField('v3_auth_password',   '');
      setField('v3_priv_password',   '');

      // Re-sync SNMP version panels
      const verRadio = editForm.querySelector('input[name="snmp_version"]:checked');
      fire(verRadio, 'change');

      // Re-sync the map from lat/lng
      fire(editForm.elements['latitude'],  'input');
      fire(editForm.elements['longitude'], 'input');

      // Open
      editDialog.showModal();

      const first = editDialog.querySelector('[aria-invalid="true"]') || editForm.elements['name'];
      if (first) first.focus({ preventScroll: true });

      // Nudge Leaflet to recalc size after the dialog becomes visible
      setTimeout(() => {
        const mapEl = editForm.querySelector('.map-picker');
        if (!mapEl) return;
        if (mapEl._leaflet_map && typeof mapEl._leaflet_map.invalidateSize === 'function') {
          mapEl._leaflet_map.invalidateSize();
        } else if (window.L) {
          for (const k in mapEl) {
            if (k.indexOf('_leaflet_') === 0 && mapEl[k] && typeof mapEl[k].invalidateSize === 'function') {
              mapEl[k].invalidateSize();
            }
          }
        }
      }, 120);
    }

    function closeEdit() { if (editDialog) editDialog.close(); }

    document.querySelectorAll('[data-edit-device]').forEach((btn) =>
      btn.addEventListener('click', () => openEdit(btn))
    );
    if (editDialog) {
      editDialog.querySelectorAll('[data-close-edit]').forEach((b) =>
        b.addEventListener('click', closeEdit)
      );
      editDialog.addEventListener('click', (e) => { if (e.target === editDialog) closeEdit(); });
    }

    // Validation failed on an edit → reopen that device in the modal
    if (@json($editFailed) && editDialog) {
      const failedId = @json(old('device_id'));
      const btn = document.querySelector('[data-edit-device="' + failedId + '"]');
      if (btn) {
        openEdit(btn);
      } else {
        editDialog.showModal();
      }
    }
  })();
  </script>

  <script>
  (function () {
    const all = document.getElementById('select-all');
    if (!all) return;
    const boxes = Array.from(document.querySelectorAll('.row-check'));
    const bar = document.getElementById('bulk-bar');
    const count = document.getElementById('bulk-count');
    let last = null;

    function update() {
      const n = boxes.filter((b) => b.checked).length;
      boxes.forEach((b) => b.closest('tr').classList.toggle('selected', b.checked));
      all.checked = n > 0 && n === boxes.length;
      all.indeterminate = n > 0 && n < boxes.length;
      bar.hidden = n === 0;
      count.textContent = n + ' selected';
    }

    all.addEventListener('change', () => {
      boxes.forEach((b) => { b.checked = all.checked; });
      update();
    });

    boxes.forEach((box) => box.addEventListener('click', (e) => {
      if (e.shiftKey && last && last !== box) {
        const [a, b] = [boxes.indexOf(last), boxes.indexOf(box)].sort((x, y) => x - y);
        boxes.slice(a, b + 1).forEach((x) => { x.checked = box.checked; });
      }
      last = box;
      update();
    }));

    document.getElementById('bulk-clear').addEventListener('click', () => {
      boxes.forEach((b) => { b.checked = false; });
      update();
    });

    document.getElementById('bulk-delete').addEventListener('click', (e) => {
      const n = boxes.filter((b) => b.checked).length;
      if (!confirm('Delete ' + n + ' selected ' + (n === 1 ? 'device' : 'devices') + '? They will no longer be monitored.')) {
        e.preventDefault();
      }
    });

    update();
  })();
  </script>

</div>
@endsection
