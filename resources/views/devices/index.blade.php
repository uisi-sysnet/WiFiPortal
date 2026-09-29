@extends('layouts.app')

@section('title', $info['plural'].' | Public WiFi Control')
@section('body-class', 'wide')

@push('head')
<meta name="csrf-token" content="{{ csrf_token() }}">
<style>
/* Add pop-up */
dialog.modal{width:min(900px,calc(100vw - 24px));max-height:calc(100vh - 32px);padding:0;border:0;border-radius:10px;color:var(--ink);background:var(--paper);box-shadow:0 24px 64px rgba(10,20,26,.35)}
dialog.modal::backdrop{background:rgba(10,20,26,.55)}
dialog.modal[open]{display:flex}
.modal-form{display:flex;flex-direction:column;width:100%;max-height:calc(100vh - 32px);margin:0}
.modal-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 22px;background:#fff;border-bottom:1px solid var(--line)}
.modal-head h2{margin:0;font-size:1.25rem}
.modal-x{display:grid;place-items:center;width:34px;height:34px;border:0;border-radius:6px;background:none;color:var(--ink-2);cursor:pointer}
.modal-x:hover{background:var(--paper);color:var(--ink)}
.modal-body{flex:1;overflow:auto;padding:18px 22px 8px}
.modal-body .alert{margin-bottom:16px}
.modal-foot{display:flex;align-items:center;justify-content:flex-end;gap:10px;padding:14px 22px;background:#fff;border-top:1px solid var(--line)}
@media (max-width:640px){.modal-head,.modal-body,.modal-foot{padding-left:14px;padding-right:14px}}

body.wide main{max-width:1600px}
.toolbar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px 20px;margin:0 0 12px}
.toolbar form{display:flex;align-items:center;gap:8px;margin:0}
.toolbar select{font:inherit;padding:7px 10px;border:1px solid #A7B4AD;border-radius:4px;background:#fff;color:var(--ink)}

/* Bulk bar: appears when rows are ticked */
.bulk{display:flex;flex-wrap:wrap;align-items:center;gap:10px 14px;margin:0 0 12px;padding:10px 14px;background:var(--ink);color:#fff;border-radius:var(--radius)}
.bulk[hidden]{display:none}
.bulk strong{margin-right:auto;font-weight:600}
.bulk .btn{padding:6px 12px;font-size:.88rem}
.bulk .btn.quiet{color:#fff;border-color:#4A5E6A}
.bulk .btn.danger{color:#FFB4AC;border-color:#FFB4AC}
.bulk .link{background:none;border:0;color:#B9C9C2;font:inherit;font-size:.88rem;text-decoration:underline;cursor:pointer}

table.inventory{font-size:.9rem}
table.inventory th,table.inventory td{padding:10px 12px;vertical-align:middle}
table.inventory th{white-space:nowrap}
table.inventory td small{display:block;color:var(--ink-2);font-size:.8rem}
table.inventory .sel{width:40px;text-align:center;padding-right:0}
table.inventory .sel input{width:17px;height:17px;accent-color:var(--signal);cursor:pointer;vertical-align:middle}
table.inventory .num{width:48px;color:var(--ink-2);text-align:right;font-variant-numeric:tabular-nums}
table.inventory tbody tr:hover{background:#F7F9F8}
table.inventory tbody tr.selected{background:var(--signal-soft)}
.dev{display:flex;align-items:center;gap:9px;font-weight:600}
.dot{flex:none;width:9px;height:9px;border-radius:50%;background:#B8C2BD}
.dot.online{background:var(--signal)}
.dot.offline{background:var(--fail)}
.none{color:#A7B4AD}
.acts{display:flex;gap:6px;justify-content:flex-end}
.acts form{margin:0}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1>{{ $info['plural'] }}</h1>
    <p class="lede">Checked over SNMP every minute. The dot beside each name shows whether it is online.</p>
  </div>
  <a class="btn" href="{{ route($info['route'].'.index', ['add' => 1]) }}" data-open-add>Add {{ strtolower($info['label']) }}</a>
</div>

@if (session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
@error('ids')<div class="alert" role="alert">{{ $message }}</div>@enderror

@if ($devices->isEmpty() && ! $filter && $devices->currentPage() === 1)
  <div class="plan">
    <h2>No {{ strtolower($info['plural']) }} yet</h2>
    <p>Add one with its barangay, map position, IP address and SNMP details. It is checked right away.</p>
    <p style="margin-top:14px"><a class="btn" href="{{ route($info['route'].'.index', ['add' => 1]) }}" data-open-add>Add {{ strtolower($info['label']) }}</a></p>
  </div>
@else
  <div class="toolbar">
    <p class="counts" style="margin:0">
      <span class="status online">{{ number_format($counts['online'] ?? 0) }} online</span>
      <span class="status offline">{{ number_format($counts['offline'] ?? 0) }} offline</span>
      @if (($counts['unknown'] ?? 0) > 0)<span class="status unknown">{{ number_format($counts['unknown']) }} not checked yet</span>@endif
    </p>
    <form method="GET" action="{{ route($info['route'].'.index') }}">
      <label for="barangay" class="hint">Location</label>
      <select id="barangay" name="barangay" onchange="this.form.submit()">
        <option value="">All barangays</option>
        @foreach ($barangays as $b)
          <option value="{{ $b->id }}" @selected($filter === $b->id)>{{ $b->name }}</option>
        @endforeach
      </select>
      <noscript><button class="btn quiet sm" type="submit">Show</button></noscript>
    </form>
  </div>

  {{-- Row checkboxes belong to this form through form="bulk-form" --}}
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
    <div class="plan"><p>No {{ strtolower($info['plural']) }} in this barangay yet.</p></div>
  @else
    <div class="table-wrap">
      <table class="inventory">
        <thead>
          <tr>
            <th scope="col" class="sel"><input type="checkbox" id="select-all" aria-label="Select all on this page"></th>
            <th scope="col" class="num">No.</th>
            <th scope="col">Device name</th>
            <th scope="col">Device model</th>
            <th scope="col">IP address</th>
            <th scope="col">MAC address</th>
            <th scope="col">Serial number</th>
            <th scope="col">Firmware version</th>
            <th scope="col">Location</th>
            <th scope="col">Lat, long</th>
            <th scope="col"><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
        @foreach ($devices as $d)
          @php $dash = '<span class="none">–<span class="sr-only">Not set</span></span>'; @endphp
          <tr>
            <td class="sel"><input type="checkbox" name="ids[]" value="{{ $d->id }}" form="bulk-form" class="row-check" aria-label="Select {{ $d->name }}"></td>
            <td class="num">{{ $devices->firstItem() + $loop->index }}</td>
            <td>
              <span class="dev" title="{{ $d->statusLabel() }}{{ $d->last_checked_at ? ', checked '.$d->last_checked_at->diffForHumans() : '' }}{{ $d->status === 'offline' && $d->last_error ? '. '.$d->last_error : '' }}">
                <span class="dot {{ $d->status }}" aria-hidden="true"></span>{{ $d->name }}<span class="sr-only">, {{ $d->statusLabel() }}</span>
              </span>
            </td>
            <td>{!! $d->model ? e($d->model) : $dash !!}</td>
            <td class="mono">{{ $d->host }}@if ($d->snmp_port !== 161):{{ $d->snmp_port }}@endif</td>
            <td class="mono">{!! $d->mac_address ? e($d->mac_address) : $dash !!}</td>
            <td class="mono">{!! $d->serial_number ? e($d->serial_number) : $dash !!}</td>
            <td class="mono">{!! $d->firmware_version ? e($d->firmware_version) : $dash !!}</td>
            <td>{{ $d->barangay_name ?? 'No barangay' }}@if ($d->location)<small>{{ $d->location }}</small>@endif</td>
            <td class="mono">
              @if ($d->hasCoordinates())
                <a href="{{ $d->mapUrl() }}" target="_blank" rel="noopener" title="Open in Google Maps">{{ (float) $d->latitude }}, {{ (float) $d->longitude }}</a>
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
    <div style="margin-top:16px">{{ $devices->links() }}</div>
  @endif
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
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </div>
    <div class="modal-body">
      @if ($addFailed)<div class="alert" role="alert">Fix the highlighted fields and try again.</div>@endif
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
  if (!dialog || typeof dialog.showModal !== 'function') return; // very old browser: links fall back to ?add=1 below

  function open() {
    dialog.showModal();
    const first = dialog.querySelector('[aria-invalid="true"]') || document.getElementById('name');
    if (first) first.focus();
  }
  function close() { dialog.close(); }

  document.querySelectorAll('[data-open-add]').forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); open(); }));
  dialog.querySelectorAll('[data-close-add]').forEach((b) => b.addEventListener('click', close));
  // Clicking the dimmed area outside the box closes it; typed values stay for next time.
  dialog.addEventListener('click', (e) => { if (e.target === dialog) close(); });

  // Opened from the Devices menu (?add=1) or reopened after a failed save
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
  const bar = document.getElementById('bulk-bar'), count = document.getElementById('bulk-count');
  let last = null;

  function update() {
    const n = boxes.filter((b) => b.checked).length;
    boxes.forEach((b) => b.closest('tr').classList.toggle('selected', b.checked));
    all.checked = n > 0 && n === boxes.length;
    all.indeterminate = n > 0 && n < boxes.length;
    bar.hidden = n === 0;
    count.textContent = n + ' selected';
  }

  all.addEventListener('change', () => { boxes.forEach((b) => { b.checked = all.checked; }); update(); });

  // Shift-click ticks every row between the last click and this one.
  boxes.forEach((box) => box.addEventListener('click', (e) => {
    if (e.shiftKey && last && last !== box) {
      const [a, b] = [boxes.indexOf(last), boxes.indexOf(box)].sort((x, y) => x - y);
      boxes.slice(a, b + 1).forEach((x) => { x.checked = box.checked; });
    }
    last = box;
    update();
  }));

  document.getElementById('bulk-clear').addEventListener('click', () => { boxes.forEach((b) => { b.checked = false; }); update(); });
  document.getElementById('bulk-delete').addEventListener('click', (e) => {
    const n = boxes.filter((b) => b.checked).length;
    if (!confirm('Delete ' + n + ' selected ' + (n === 1 ? 'device' : 'devices') + '? They will no longer be monitored.')) e.preventDefault();
  });

  update();
})();
</script>
@endsection