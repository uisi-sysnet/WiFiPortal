@extends('layouts.app')

@section('title', 'Edit '.$device->name.' | Public WiFi Control')

@push('head')
<meta name="csrf-token" content="{{ csrf_token() }}">
<style>
/* ============================================================
   Edit-device modal — sits above the device table
   ============================================================ */
dialog.modal {
  width: min(960px, calc(100vw - 24px));
  max-height: calc(100vh - 32px);
  padding: 0;
  border: 0;
  border-radius: 10px;
  color: var(--ink, #0B1C14);
  background: #fff;
  box-shadow: 0 32px 96px rgba(10, 20, 26, .42), 0 2px 6px rgba(10, 20, 26, .08);
  overflow: hidden;
  opacity: 0;
  transform: translateY(8px) scale(.985);
  transition: opacity .18s ease, transform .18s ease;
}
dialog.modal[open] {
  display: flex;
  opacity: 1;
  transform: translateY(0) scale(1);
}
dialog.modal::backdrop {
  background: rgba(10, 20, 26, .55);
  backdrop-filter: blur(3px);
}

.modal-form {
  display: flex;
  flex-direction: column;
  width: 100%;
  max-height: calc(100vh - 32px);
  margin: 0;
}

/* --- Head --- */
.modal-head {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 16px 22px;
  background: linear-gradient(180deg, #fff 0%, #FBFDFC 100%);
  border-bottom: 1px solid var(--line, #C8D9D0);
}
.modal-head .head-icon {
  flex: none;
  display: grid;
  place-items: center;
  width: 34px;
  height: 34px;
  border-radius: 8px;
  background: color-mix(in srgb, #0e670d 10%, #fff);
  color: #0e670d;
}
.modal-head .head-text { min-width: 0; flex: 1; }
.modal-head h2 {
  margin: 0;
  font-size: 1rem;
  font-weight: 700;
  letter-spacing: -0.01em;
  color: var(--ink, #0B1C14);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.modal-head .head-sub {
  margin: 2px 0 0;
  font-size: .78rem;
  color: var(--ink-2, #5c6b66);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.modal-x {
  flex: none;
  display: grid;
  place-items: center;
  width: 34px;
  height: 34px;
  border: 0;
  border-radius: 8px;
  background: none;
  color: var(--ink-2, #5c6b66);
  cursor: pointer;
  transition: background .15s, color .15s, transform .1s;
}
.modal-x:hover { background: var(--hover, #F0F3F1); color: var(--ink, #0B1C14); }
.modal-x:active { transform: scale(.94); }

/* --- Body --- */
.modal-body {
  flex: 1;
  overflow: auto;
  padding: 20px 22px 8px;
  scrollbar-width: thin;
  scrollbar-color: #C8D9D0 transparent;
}
.modal-body::-webkit-scrollbar { width: 10px; }
.modal-body::-webkit-scrollbar-thumb {
  background: #C8D9D0;
  border-radius: 999px;
  border: 3px solid #fff;
}
.modal-body::-webkit-scrollbar-thumb:hover { background: #A7B4AD; }

/* --- Status banner --- */
.status-banner {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  margin-bottom: 16px;
  border-radius: 8px;
  font-size: .82rem;
  border: 1px solid var(--line, #C8D9D0);
  background: #F7FAF8;
  color: var(--ink-2, #5c6b66);
}
.status-banner .chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 700;
  font-size: .72rem;
  letter-spacing: .05em;
  text-transform: uppercase;
}
.status-banner .chip::before {
  content: '';
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #B8C2BD;
}
.status-banner.online { background: color-mix(in srgb, #0e670d 7%, #fff); border-color: color-mix(in srgb, #0e670d 25%, transparent); }
.status-banner.online .chip { color: #0e670d; }
.status-banner.online .chip::before { background: #0e670d; box-shadow: 0 0 0 3px color-mix(in srgb, #0e670d 18%, transparent); }
.status-banner.offline { background: color-mix(in srgb, #B3372E 6%, #fff); border-color: color-mix(in srgb, #B3372E 22%, transparent); }
.status-banner.offline .chip { color: #B3372E; }
.status-banner.offline .chip::before { background: #B3372E; box-shadow: 0 0 0 3px color-mix(in srgb, #B3372E 15%, transparent); }
.status-banner .meta { margin-left: auto; font-size: .76rem; color: var(--ink-2, #5c6b66); }
.status-banner .err {
  font-size: .76rem;
  color: #B3372E;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 340px;
}

/* --- Foot --- */
.modal-foot {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 10px;
  padding: 14px 22px;
  background: #FBFDFC;
  border-top: 1px solid var(--line, #C8D9D0);
}
.modal-foot .foot-hint {
  margin-right: auto;
  font-size: .76rem;
  color: var(--ink-2, #5c6b66);
}

@media (max-width: 640px) {
  .modal-head, .modal-body, .modal-foot { padding-left: 16px; padding-right: 16px; }
  .modal-head .head-sub { display: none; }
  .status-banner { flex-wrap: wrap; }
  .status-banner .meta { margin-left: 0; width: 100%; }
  .modal-foot .foot-hint { display: none; }
}

dialog.modal ::selection { background: color-mix(in srgb, #0e670d 20%, #fff); }
</style>
@endpush

@section('content')

<dialog class="modal" id="edit-device" aria-labelledby="edit-title">
  <form id="device-form" class="modal-form device-fields" method="POST" action="{{ route('devices.update', $device) }}" novalidate>
    @csrf @method('PUT')
    <input type="hidden" name="device_id" value="{{ $device->id }}">
    <input type="hidden" name="return_barangay" value="{{ request('barangay') }}">

    {{-- ---------- Header ---------- --}}
    <div class="modal-head">
      <div class="head-icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 20h9"/>
          <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/>
        </svg>
      </div>
      <div class="head-text">
        <h2 id="edit-title">Edit {{ $device->name }}</h2>
        <p class="head-sub">
          {{ $info['label'] }}
          @if ($device->barangay_name) &middot; {{ $device->barangay_name }} @endif
          @if ($device->host) &middot; {{ $device->host }} @endif
        </p>
      </div>
      <button type="button" class="modal-x" data-close-edit aria-label="Close">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
          <path d="M18 6 6 18M6 6l12 12"/>
        </svg>
      </button>
    </div>

    {{-- ---------- Body ---------- --}}
    <div class="modal-body">
      <div class="status-banner {{ $device->status }}">
        <span class="chip">{{ $device->statusLabel() }}</span>
        @if ($device->last_checked_at)
          <span>Last checked {{ $device->last_checked_at->diffForHumans() }}</span>
        @else
          <span>Not checked yet</span>
        @endif
        @if ($device->status === 'offline' && $device->last_error)
          <span class="err" title="{{ $device->last_error }}">{{ $device->last_error }}</span>
        @endif
        <span class="meta">
          Checked every minute over SNMP &middot; offline after {{ config('devices.offline_after') }} missed checks
        </span>
      </div>

      @if ($errors->any())
        <div class="alert" role="alert" style="background:#FDF0EE;border-left:4px solid var(--fail,#B3372E);padding:12px 16px;border-radius:6px;font-size:.86rem;color:#7A1A14;margin-bottom:16px">
          Fix the highlighted fields and try again.
        </div>
      @endif

      @include('devices._fields', ['formId' => 'device-form'])
    </div>

    {{-- ---------- Footer ---------- --}}
    <div class="modal-foot">
      <span class="foot-hint">
        Test SNMP before saving to fill in the model, MAC, serial, and firmware automatically.
      </span>
      <a class="btn quiet" href="{{ route($info['route'].'.index', array_filter(['barangay' => request('barangay')])) }}">Cancel</a>
      <button class="btn" type="submit">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M20 6 9 17l-5-5"/>
        </svg>
        Save changes
      </button>
    </div>
  </form>
</dialog>

<script>
(function () {
  const dialog = document.getElementById('edit-device');
  if (!dialog || typeof dialog.showModal !== 'function') return;

  const fallback = @json(route($info['route'].'.index', array_filter(['barangay' => request('barangay')])));

  function open() {
    requestAnimationFrame(() => {
      dialog.showModal();
      const first = dialog.querySelector('[aria-invalid="true"]') || document.getElementById('name');
      if (first) first.focus({ preventScroll: true });
    });
  }

  function close() {
    dialog.style.transition = 'opacity .14s ease, transform .14s ease';
    dialog.style.opacity = '0';
    dialog.style.transform = 'translateY(8px) scale(.985)';
    setTimeout(() => {
      dialog.close();
      if (fallback) window.location.href = fallback;
    }, 140);
  }

  dialog.querySelectorAll('[data-close-edit]').forEach((b) =>
    b.addEventListener('click', close)
  );
  dialog.addEventListener('click', (e) => { if (e.target === dialog) close(); });
  dialog.addEventListener('cancel', (e) => { e.preventDefault(); close(); });

  // Auto-open on page load so it appears "over" the previous view.
  open();
})();
</script>
@endsection