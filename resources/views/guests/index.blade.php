@extends('layouts.app')

@section('title', 'Guests | Public WiFi Control')
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
   PAGE LAYOUT
   ============================================================ */
.page-head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 8px 20px; margin-bottom: 0; padding: 12px 16px; background: color-mix(in srgb, #0e670d 12%, #fff); border: 2px solid var(--line); border-bottom: 0; border-radius: 8px 8px 0 0; flex: none; }
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
.filter-form input.control { min-width: 220px; }
.btn.control { cursor: pointer; }
.btn.control.primary { border-color: #0e670d; background: #0e670d; color: #fff; }
.btn.control.primary:hover { background: var(--signal-hover); color: #fff; border-color: var(--signal-hover); }
.btn.control.quiet { color: var(--ink); border-color: var(--line); background: #fff; }
.btn.control.quiet:hover { background: var(--hover); }

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
table.inventory .num { width: 44px; color: var(--ink-2); text-align: right; font-variant-numeric: tabular-nums; font-size: .78rem; }
table.inventory th:nth-child(1), table.inventory td:nth-child(1) { width: 40px; text-align: right; }
table.inventory th:nth-child(2), table.inventory td:nth-child(2) { width: 160px; }
table.inventory th:nth-child(3), table.inventory td:nth-child(3) { width: 150px; }
table.inventory th:nth-child(4), table.inventory td:nth-child(4) { width: 160px; }
table.inventory th:nth-child(5), table.inventory td:nth-child(5) { width: 130px; }
table.inventory th:nth-child(6), table.inventory td:nth-child(6) { width: 140px; }
table.inventory th:nth-child(7), table.inventory td:nth-child(7) { width: 140px; }
table.inventory th:nth-child(8), table.inventory td:nth-child(8) { width: 140px; }
table.inventory th:nth-child(9), table.inventory td:nth-child(9) { width: 130px; }
table.inventory td { vertical-align: middle; }
table.inventory td .dev { display: flex; align-items: center; gap: 6px; font-weight: 600; letter-spacing: -0.01em; color: var(--ink); }
.pill { display: inline-flex; align-items: center; gap: 4px; padding: 1px 7px; border-radius: 999px; font-size: .68rem; font-weight: 600; line-height: 1.3; white-space: nowrap; text-transform: uppercase; letter-spacing: .04em; }
.pill.connected { color: #0e670d; background: color-mix(in srgb, #0e670d 12%, #fff); border: 1px solid color-mix(in srgb, #0e670d 32%, transparent); }
.pill.expired, .pill.revoked { color: #B3372E; background: color-mix(in srgb, var(--fail) 10%, #fff); border: 1px solid color-mix(in srgb, var(--fail) 28%, transparent); }
.pill.pending { color: #5c6b66; background: #F0F3F1; border: 1px solid var(--line); }
.pill .dot { width: 5px; height: 5px; border-radius: 50%; box-shadow: none; flex: none; }
.dot.connected { background: #0e670d; }
.dot.expired, .dot.revoked { background: var(--fail); }
.dot.pending { background: #A7B4AD; }
.none { color: #A7B4AD; }
.mono { font-variant-numeric: tabular-nums; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: .78rem; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
table.inventory td small { display: block; color: var(--ink-2); font-size: .72rem; margin-top: 1px; white-space: normal; }
table.inventory a { color: #0e670d; text-decoration: none; }
table.inventory a:hover { text-decoration: underline; text-underline-offset: 2px; }
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
dialog.modal { width:min(760px,calc(100vw - 24px)); max-height:calc(100vh - 32px); padding:0; border:0; border-radius:8px; color:var(--ink); background:var(--paper); box-shadow:0 28px 80px rgba(10,20,26,.38); overflow:hidden; }
dialog.modal::backdrop { background:rgba(10,20,26,.55); backdrop-filter:blur(2px); }
dialog.modal[open] { display:flex; }
.modal-form { display:flex; flex-direction:column; width:100%; max-height:calc(100vh - 32px); margin:0; }
.modal-head,.modal-foot { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 18px; background:#fff; }
.modal-head { border-bottom:1px solid var(--line); }.modal-head h2 { margin:0; font-size:1rem; }
.modal-body { flex:1; overflow:auto; padding:18px; }.modal-body p { margin:0 0 14px; color:var(--ink-2); }
.modal-foot { border-top:1px solid var(--line); }.modal-foot .foot-right { display:flex; gap:8px; }
.export-columns { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:10px 16px; }
.export-columns label { display:flex; align-items:center; gap:8px; font-size:.85rem; }

/* ============================================================
   RESPONSIVE — mobile cards (< 760px)
   ============================================================ */
@media (max-width: 760px) {
  .panel-head { flex-direction: column; align-items: stretch; gap: 8px; }
  .panel-head .head-right { margin-left: 0; width: 100%; }
  .filter-form { width: 100%; flex-wrap: wrap; }
  .filter-form input.control { flex: 1; min-width: 0; }
  .filter-form select.control { flex: 1; min-width: 0; }
  .btn.control { flex: 0 0 auto; }
  .table-wrap { overflow: auto; }
  table.inventory { min-width: 0; display: block; }
  table.inventory thead { display: none; }
  table.inventory tbody { display: block; }
  table.inventory tbody tr { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 10px; padding: 10px 12px; border-bottom: 1px solid var(--line); background: #fff; }
  table.inventory tbody tr:hover { background: var(--signal-soft); }
  table.inventory tbody tr:last-child { border-bottom: 0; }
  table.inventory td { display: flex; flex-direction: column; gap: 2px; width: auto !important; padding: 0; border: 0; overflow: visible; text-overflow: clip; white-space: normal; text-align: left !important; }
  table.inventory td::before { content: attr(data-label); font-size: .6rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--ink-2); }
  table.inventory td[data-label="Guest"], table.inventory td[data-label="Network / router"] { grid-column: 1 / -1; }
  table.inventory td[data-label="No."] { flex-direction: row; align-items: center; gap: 8px; }
  table.inventory td[data-label="No."]::before { display: none; }
  table.inventory td[data-label="Guest"] .dev { font-size: .95rem; }
}

@media (max-width: 420px) {
  .page-head { padding: 10px 12px; }
  .page-head h1 { font-size: .9rem; }
  .page-head .lede { font-size: .75rem; }
  .stat-chip { font-size: .62rem; height: 20px; padding: 0 7px; }
}
</style>
@endpush

@section('content')
<div class="page-wrapper">

  <dialog class="modal" id="export-columns" aria-labelledby="export-title">
    <form class="modal-form" method="GET" action="{{ route('guests.export') }}">
      <input type="hidden" name="q" value="{{ $search }}">
      <input type="hidden" name="status" value="{{ $status }}">
      <div class="modal-head">
        <h2 id="export-title">Choose guest columns to export</h2>
        <button type="button" class="btn control quiet" onclick="document.getElementById('export-columns').close()" aria-label="Close">Close</button>
      </div>
      <div class="modal-body">
        <p>The export will include all guests matching the current search and status filter.</p>
        <div class="export-columns">
          @foreach ($exportColumns as $columnLabel)
            <label><input type="checkbox" name="columns[]" value="{{ $loop->index }}" checked> {{ $columnLabel }}</label>
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

  {{-- Page header --}}
  <div class="page-head">
    <h1>Guests</h1>
    {{-- <p class="lede">Captive portal registrations and hotspot access status</p> --}}
    <div class="head-right">
      <form class="filter-form" method="GET" action="{{ route('guests.index') }}">
        <input type="search" name="q" value="{{ $search }}" placeholder="Search name, contact, username…" aria-label="Search guests" class="control">
        <select name="status" class="control" aria-label="Filter by status">
          <option value="">All guests</option>
          <option value="connected" @selected($status === 'connected')>Connected</option>
          <option value="expired" @selected($status === 'expired')>Expired</option>
          <option value="revoked" @selected($status === 'revoked')>Revoked</option>
        </select>
        <button type="submit" class="btn control primary">Filter</button>
      </form>
      <button class="btn control quiet" type="button" onclick="document.getElementById('export-columns').showModal()" title="Choose columns to include in the Excel file">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="margin-right:6px">
          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
        </svg>
        Export to Excel
      </button>
    </div>
  </div>

  @if ($guests->isEmpty() && ! $search && ! $status)
    <div class="panel">
      <div class="plan">
        <h2>No guests yet</h2>
        <p>Guest registrations from the captive portal will appear here once visitors connect.</p>
      </div>
    </div>
  @else
    <div class="panel">
      <div class="table-wrap">
        <table class="inventory">
          <thead>
            <tr>
              <th scope="col" class="num">No.</th>
              <th scope="col">Guest</th>
              <th scope="col">Contact</th>
              <th scope="col">Network / router</th>
              <th scope="col">Username</th>
              <th scope="col">Device</th>
              <th scope="col">Registered</th>
              <th scope="col">Connected</th>
              <th scope="col">Access</th>
            </tr>
          </thead>
          <tbody>
          @forelse ($guests as $guest)
            @php
              $accessStatus = $guest->revoked_at ? 'revoked' : (($guest->expires_at && $guest->expires_at->isPast()) ? 'expired' : ($guest->connected_at ? 'connected' : 'pending'));
            @endphp
            <tr>
              <td class="num" data-label="No.">{{ $guests->firstItem() + $loop->index }}</td>
              <td data-label="Guest">
                <span class="dev">{{ $guest->name ?: 'Guest' }}</span>
                <small>{{ $guest->resident ? 'Resident' : 'Visitor' }}@if($guest->citizen_number) · ID {{ $guest->citizen_number }}@endif</small>
              </td>
              <td data-label="Contact">
                {{ $guest->contact ?: '—' }}
                <small>{{ $guest->contact_type ?: 'No contact provided' }}</small>
              </td>
              <td data-label="Network / router">
                {{ $guest->network?->name ?: '—' }}
                <small>{{ $guest->router?->name ?: 'Router unavailable' }}</small>
              </td>
              <td class="mono" data-label="Username">{{ $guest->username }}</td>
              <td class="mono" data-label="Device">
                {{ $guest->mac ?: '—' }}
                <small>{{ $guest->ip ?: 'No IP recorded' }}</small>
              </td>
              <td data-label="Registered">{{ $guest->created_at?->format('M j, Y g:i A') ?: '—' }}</td>
              <td data-label="Connected">{{ $guest->connected_at?->format('M j, Y g:i A') ?: '—' }}</td>
              <td data-label="Access">
                <span class="pill {{ $accessStatus }}">
                  <span class="dot {{ $accessStatus }}" aria-hidden="true"></span>
                  {{ $accessStatus }}
                </span>
                @if($guest->expires_at)<small>Until {{ $guest->expires_at->format('M j, g:i A') }}</small>@endif
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="9" class="plan">
                <strong>No guests found</strong>
                <p>{{ $search ? 'Try a different search.' : 'Guest registrations will appear here.' }}</p>
              </td>
            </tr>
          @endforelse
          </tbody>
        </table>
      </div>

      <div class="pagination-wrap">
        <div class="stat-group" role="group" aria-label="Guest summary">
          <span class="stat-chip online">{{ number_format($counts['connected'] ?? 0) }} connected</span>
          <span class="stat-chip offline">{{ number_format($counts['revoked'] ?? 0) }} revoked</span>
          @if (($counts['all'] ?? 0) > 0)
            <span class="stat-chip unknown">{{ number_format($counts['all']) }} total</span>
          @endif
        </div>
        {{ $guests->links() }}
      </div>
    </div>
  @endif

</div>
@endsection
