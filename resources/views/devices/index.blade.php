@extends('layouts.app')

@section('title', $info['plural'].' | Public WiFi Control')
@section('body-class', 'wide')

@push('head')
<meta name="csrf-token" content="{{ csrf_token() }}">
<style>
/* ---- Primary green theme (matches topbar) ---- */
:root {
  --signal: #0e670d;
  --signal-soft: color-mix(in srgb, #0e670d 12%, #fff);
  --signal-hover: color-mix(in srgb, #0e670d 85%, #000);
  --line: #D0D8D4;
  --ink: #0F1A1F;
  --ink-2: #5c6b66;
  --paper: #fff;
  --hover: #F0F3F1;
  --fail: #B3372E;
}

/* Page wrapper — full screen height minus topbar, flex column */
.page-wrapper {
  min-height: calc(100vh - 95px);
  display: flex;
  flex-direction: column;
}

dialog.modal { width: min(920px, calc(100vw - 24px)); max-height: calc(100vh - 32px); padding: 0; border: 0; border-radius: 8px; color: var(--ink); background: var(--paper); box-shadow: 0 28px 80px rgba(10, 20, 26, .38); }
dialog.modal::backdrop { background: rgba(10, 20, 26, .55); backdrop-filter: blur(2px); }
dialog.modal[open] { display: flex; }
.modal-form { display: flex; flex-direction: column; width: 100%; max-height: calc(100vh - 32px); margin: 0; }
.modal-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 20px; background: #fff; border-bottom: 1px solid var(--line); }
.modal-head h2 { margin: 0; font-size: 1rem; font-weight: 600; letter-spacing: -0.01em; }
.modal-x { display: grid; place-items: center; width: 32px; height: 32px; border: 0; border-radius: 6px; background: none; color: var(--ink-2); cursor: pointer; transition: background .15s, color .15s; }
.modal-x:hover { background: var(--hover); color: var(--ink); }
.modal-body { flex: 1; overflow: auto; padding: 20px 20px 12px; }
.modal-body .alert { margin-bottom: 16px; }
.modal-foot { display: flex; align-items: center; justify-content: flex-end; gap: 8px; padding: 14px 20px; background: #fff; border-top: 1px solid var(--line); }
@media (max-width: 640px) { .modal-head, .modal-body, .modal-foot { padding-left: 16px; padding-right: 16px; } }

body.wide main { max-width: 1600px; }

/* Page header */
.page-head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 8px 20px; margin-bottom: 0; padding: 16px 20px; background: #fff; border: 1px solid var(--line); border-bottom: 0; border-radius: 8px 8px 0 0; flex: none; }
.page-head h1 { margin: 0; font-size: 1.15rem; font-weight: 700; letter-spacing: -0.01em; text-transform: uppercase; color: var(--ink); }
.page-head .lede { margin: 0; color: var(--ink-2); font-size: .9rem; }

/* Panel — flex column, stretches to fill remaining screen height */
.panel {
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
  background: #fff;
  border: 1px solid var(--line);
  border-top: 1px solid var(--line);
  border-radius: 0 0 8px 8px;
  box-shadow: 0 1px 3px rgba(10, 20, 26, .04);
  overflow: hidden;
}
.panel-head { flex: none; display: flex; flex-wrap: wrap; align-items: center; gap: 10px 16px; padding: 12px 16px; border-bottom: 1px solid var(--line); background: #fff; }
.panel-head h2 { margin: 0; font-size: .82rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--ink-2); }
.panel-head .head-right { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-left: auto; }

/* Status chips */
.stat-group { display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.stat-chip { display: inline-flex; align-items: center; gap: 6px; height: 24px; padding: 0 9px; border-radius: 999px; font-size: .72rem; font-weight: 600; line-height: 1; white-space: nowrap; border: 1px solid transparent; text-transform: uppercase; letter-spacing: .04em; }
.stat-chip::before { content: ''; width: 6px; height: 6px; border-radius: 50%; flex: none; }
.stat-chip.online { color: #0e670d; background: color-mix(in srgb, #0e670d 10%, #fff); border-color: color-mix(in srgb, #0e670d 30%, transparent); }
.stat-chip.online::before { background: #0e670d; }
.stat-chip.offline { color: #B3372E; background: color-mix(in srgb, var(--fail) 10%, #fff); border-color: color-mix(in srgb, var(--fail) 28%, transparent); }
.stat-chip.offline::before { background: var(--fail); }
.stat-chip.unknown { color: #5c6b66; background: #F0F3F1; border-color: var(--line); }
.stat-chip.unknown::before { background: #A7B4AD; }

/* Controls */
.control { display: inline-flex; align-items: center; height: 34px; padding: 0 12px; font: inherit; font-size: .88rem; font-weight: 500; line-height: 1; border: 1px solid var(--line); border-radius: 6px; background: #fff; color: var(--ink); box-sizing: border-box; transition: border-color .15s, box-shadow .15s, background .15s, color .15s; }
.control:hover { background: var(--hover); }
.control:focus { outline: none; border-color: #0e670d; box-shadow: 0 0 0 3px var(--signal-soft); }

.filter-form { display: inline-flex; align-items: center; gap: 8px; margin: 0; }
.filter-form .hint { font-size: .82rem; font-weight: 600; color: var(--ink-2); text-transform: uppercase; letter-spacing: .04em; }
.filter-form select.control { min-width: 160px; padding-right: 30px; appearance: none; background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%235c6b66' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><path d='M6 9l6 6 6-6'/></svg>"); background-repeat: no-repeat; background-position: right 10px center; cursor: pointer; }

.btn.control { cursor: pointer; }
.btn.control.primary { border-color: #0e670d; background: #0e670d; color: #fff; }
.btn.control.primary:hover { background: var(--signal-hover); color: #fff; border-color: var(--signal-hover); }
.btn.control.quiet { color: var(--ink); border-color: var(--line); background: #fff; }
.btn.control.quiet:hover { background: var(--hover); }

/* Bulk bar */
.bulk { flex: none; display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; margin: 0; padding: 10px 16px; background: color-mix(in srgb, #0e670d 6%, #fff); color: var(--ink); border-bottom: 1px solid var(--line); animation: bulk-in .2s ease; }
.bulk[hidden] { display: none; }
.bulk strong { margin-right: auto; font-weight: 600; font-size: .88rem; color: #0e670d; }

/* Buttons inside the bulk bar — match the .control sizing */
.bulk .btn { display: inline-flex; align-items: center; height: 30px; padding: 0 12px; font: inherit; font-size: .82rem; font-weight: 500; line-height: 1; border: 1px solid var(--line); border-radius: 6px; background: #fff; color: var(--ink); cursor: pointer; box-sizing: border-box; transition: background .15s, border-color .15s, color .15s; }
.bulk .btn.quiet { color: var(--ink); border-color: var(--line); background: #fff; }
.bulk .btn.quiet:hover { background: var(--hover); }
.bulk .btn.danger { color: var(--fail); border-color: color-mix(in srgb, var(--fail) 40%, transparent); background: #fff; }
.bulk .btn.danger:hover { background: color-mix(in srgb, var(--fail) 8%, #fff); }

.bulk .link { background: none; border: 0; color: var(--ink-2); font: inherit; font-size: .82rem; text-decoration: underline; text-underline-offset: 2px; cursor: pointer; padding: 4px 0; }
.bulk .link:hover { color: var(--ink); }

@keyframes bulk-in { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }

/* Table wrapper */
.table-wrap { flex: 1; min-height: 0; overflow: auto; }

table.inventory { width: 100%; border-collapse: collapse; font-size: .88rem; }
table.inventory th, table.inventory td { padding: 10px 14px; vertical-align: middle; border-bottom: 1px solid var(--line); white-space: nowrap; }
table.inventory th { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--ink-2); background: #FCFDFC; position: sticky; top: 0; z-index: 1; }
table.inventory tbody tr { transition: background .12s; }
table.inventory tbody tr:last-child td { border-bottom: 0; }
table.inventory tbody tr:hover { background: var(--hover); }
table.inventory tbody tr.selected { background: var(--signal-soft); }
table.inventory tbody tr.selected:hover { background: color-mix(in srgb, var(--signal-soft) 85%, #fff); }
table.inventory .sel { width: 40px; text-align: center; padding-right: 4px; }
table.inventory .sel input { width: 16px; height: 16px; accent-color: #0e670d; cursor: pointer; vertical-align: middle; }
table.inventory .num { width: 44px; color: var(--ink-2); text-align: right; font-variant-numeric: tabular-nums; font-size: .82rem; }

.dev { display: inline-flex; align-items: center; gap: 8px; font-weight: 600; letter-spacing: -0.01em; color: var(--ink); }
.dot { flex: none; width: 8px; height: 8px; border-radius: 50%; background: #B8C2BD; }
.dot.online { background: #0e670d; box-shadow: 0 0 0 3px color-mix(in srgb, #0e670d 20%, transparent); }
.dot.offline { background: var(--fail); box-shadow: 0 0 0 3px color-mix(in srgb, var(--fail) 16%, transparent); }

.pill { display: inline-flex; align-items: center; gap: 5px; padding: 2px 9px; border-radius: 999px; font-size: .72rem; font-weight: 600; line-height: 1.3; white-space: nowrap; text-transform: uppercase; letter-spacing: .04em; }
.pill.online { color: #0e670d; background: color-mix(in srgb, #0e670d 12%, #fff); border: 1px solid color-mix(in srgb, #0e670d 32%, transparent); }
.pill.offline { color: #B3372E; background: color-mix(in srgb, var(--fail) 10%, #fff); border: 1px solid color-mix(in srgb, var(--fail) 28%, transparent); }
.pill.unknown { color: #5c6b66; background: #F0F3F1; border: 1px solid var(--line); }
.pill .dot { width: 6px; height: 6px; box-shadow: none; }

.none { color: #A7B4AD; }
.mono { font-variant-numeric: tabular-nums; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: .82rem; color: var(--ink); }
table.inventory td small { display: block; color: var(--ink-2); font-size: .78rem; margin-top: 2px; white-space: normal; }
table.inventory a { color: #0e670d; text-decoration: none; }
table.inventory a:hover { text-decoration: underline; text-underline-offset: 2px; }

/* Row actions */
.acts { display: flex; gap: 6px; justify-content: flex-end; }
.acts form { margin: 0; }
.acts .btn { display: inline-flex; align-items: center; height: 28px; padding: 0 10px; font-size: .78rem; font-weight: 500; border-radius: 6px; border: 1px solid var(--line); background: #fff; color: var(--ink); text-decoration: none; cursor: pointer; transition: background .15s, border-color .15s, color .15s; }
.acts .btn:hover { background: var(--hover); }
.acts .btn.danger { color: #B3372E; border-color: color-mix(in srgb, var(--fail) 35%, transparent); }
.acts .btn.danger:hover { background: color-mix(in srgb, var(--fail) 8%, #fff); }

.plan { text-align: center; padding: 44px 24px; }
.plan h2 { margin: 0 0 8px; font-size: 1.05rem; font-weight: 650; color: var(--ink); }
.plan p { margin: 0; color: var(--ink-2); max-width: 36ch; margin-inline: auto; font-size: .9rem; }

/* Pagination + stats footer — chips on the left, pager on the right */
.pagination-wrap {
  flex: none;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px 16px;
  padding: 12px 16px;
  border-top: 1px solid var(--line);
}
.pagination-wrap .stat-group { margin-right: auto; }

@media (max-width: 900px) { table.inventory th, table.inventory td { padding: 9px 12px; } }
@media (max-width: 760px) {
  .panel-head .head-right { margin-left: 0; width: 100%; }
  .filter-form { width: 100%; }
  .filter-form select.control { flex: 1; }
  .btn.control { flex: 0 0 auto; }
}
@media (max-width: 640px) { .page-head h1 { font-size: 1rem; } .panel-head { align-items: flex-start; } }
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
      {{-- Panel header: title on the left, filter + add on the right --}}
      <div class="panel-head">
        <div class="stat-group" role="group" aria-label="Device status summary">
          <span class="stat-chip online">{{ number_format($counts['online'] ?? 0) }} online</span>
          <span class="stat-chip offline">{{ number_format($counts['offline'] ?? 0) }} offline</span>
          @if (($counts['unknown'] ?? 0) > 0)
            <span class="stat-chip unknown">{{ number_format($counts['unknown']) }} not checked</span>
          @endif
        </div>

        <div class="head-right">
          <form class="filter-form" method="GET" action="{{ route($info['route'].'.index') }}">
            <label for="barangay" class="hint">Location</label>
            <select id="barangay" name="barangay" class="control" onchange="this.form.submit()">
              <option value="">All barangays</option>
              @foreach ($barangays as $b)
                <option value="{{ $b->id }}" @selected($filter === $b->id)>{{ $b->name }}</option>
              @endforeach
            </select>
            <noscript><button class="btn control quiet" type="submit">Show</button></noscript>
          </form>

          <a class="btn control primary" href="{{ route($info['route'].'.index', ['add' => 1]) }}" data-open-add>
            + Add {{ strtolower($info['label']) }}
          </a>
        </div>
      </div>

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
        <div class="table-wrap">
          <table class="inventory">
            <thead>
              <tr>
                <th scope="col" class="sel">
                  <input type="checkbox" id="select-all" aria-label="Select all on this page">
                </th>
                <th scope="col" class="num">No.</th>
                <th scope="col">Device name</th>
                <th scope="col">Status</th>
                <th scope="col">Device model</th>
                <th scope="col">IP address</th>
                <th scope="col">MAC address</th>
                <th scope="col">Serial number</th>
                <th scope="col">Firmware</th>
                <th scope="col">Location</th>
                <th scope="col">Lat, long</th>
                <th scope="col"><span class="sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody>
            @foreach ($devices as $d)
              @php $dash = '<span class="none">–<span class="sr-only">Not set</span></span>'; @endphp
              <tr>
                <td class="sel">
                  <input type="checkbox" name="ids[]" value="{{ $d->id }}" form="bulk-form" class="row-check" aria-label="Select {{ $d->name }}">
                </td>
                <td class="num">{{ $devices->firstItem() + $loop->index }}</td>
                <td>
                  <span class="dev" title="{{ $d->statusLabel() }}{{ $d->last_checked_at ? ', checked '.$d->last_checked_at->diffForHumans() : '' }}{{ $d->status === 'offline' && $d->last_error ? '. '.$d->last_error : '' }}">
                    {{ $d->name }}
                  </span>
                </td>
                <td>
                  <span class="pill {{ $d->status }}">
                    <span class="dot {{ $d->status }}" aria-hidden="true"></span>
                    {{ $d->statusLabel() }}
                  </span>
                </td>
                <td>{!! $d->model ? e($d->model) : $dash !!}</td>
                <td class="mono">{{ $d->host }}@if ($d->snmp_port !== 161):{{ $d->snmp_port }}@endif</td>
                <td class="mono">{!! $d->mac_address ? e($d->mac_address) : $dash !!}</td>
                <td class="mono">{!! $d->serial_number ? e($d->serial_number) : $dash !!}</td>
                <td class="mono">{!! $d->firmware_version ? e($d->firmware_version) : $dash !!}</td>
                <td style="white-space:normal; min-width:140px">
                  {{ $d->barangay_name ?? 'No barangay' }}
                  @if ($d->location)<small>{{ $d->location }}</small>@endif
                </td>
                <td class="mono">
                  @if ($d->hasCoordinates())
                    <a href="{{ $d->mapUrl() }}" target="_blank" rel="noopener" title="Open in Google Maps">
                      {{ (float) $d->latitude }}, {{ (float) $d->longitude }}
                    </a>
                  @else
                    {!! $dash !!}
                  @endif
                </td>
                <td>
                  <div class="acts">
                    <a class="btn quiet sm" href="{{ route('devices.edit', [$d, 'barangay' => $filter]) }}">Edit</a>
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

        {{-- Footer: status chips on the left, pagination on the right --}}
        <div class="pagination-wrap">
          {{ $devices->links() }}
        </div>
      @endif
    </div>
  @endif

  {{-- ---------- Add pop-up ---------- --}}
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

  <script>
  (function () {
    const dialog = document.getElementById('add-device');
    if (!dialog || typeof dialog.showModal !== 'function') return;

    function open() {
      dialog.showModal();
      const first = dialog.querySelector('[aria-invalid="true"]') || document.getElementById('name');
      if (first) first.focus();
    }
    function close() { dialog.close(); }

    document.querySelectorAll('[data-open-add]').forEach((a) =>
      a.addEventListener('click', (e) => { e.preventDefault(); open(); })
    );
    dialog.querySelectorAll('[data-close-add]').forEach((b) =>
      b.addEventListener('click', close)
    );
    dialog.addEventListener('click', (e) => { if (e.target === dialog) close(); });

    const url = new URL(location.href);
    if (url.searchParams.has('add') || @json($addFailed)) {
      open();
      url.searchParams.delete('add');
      history.replaceState(null, '', url);
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