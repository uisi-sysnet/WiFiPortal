@extends('layouts.app')

@section('title', 'Edit '.$device->name.' | Public WiFi Control')

@push('head')
<meta name="csrf-token" content="{{ csrf_token() }}">
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1>Edit {{ $device->name }}</h1>
    <p class="lede">Changes to the IP or SNMP settings are checked as soon as you save.
      @if ($device->last_checked_at) Last checked {{ $device->last_checked_at->diffForHumans() }}: {{ $device->statusLabel() }}. @endif
    </p>
  </div>
</div>

@if ($errors->any())<div class="alert" role="alert">Fix the highlighted fields and try again.</div>@endif

<form id="device-form" method="POST" action="{{ route('devices.update', $device) }}" class="device-fields" novalidate style="max-width:860px">
  @csrf @method('PUT')
  <input type="hidden" name="device_id" value="{{ $device->id }}">
  <input type="hidden" name="return_barangay" value="{{ request('barangay') }}">

  @include('devices._fields', ['formId' => 'device-form'])

  <div class="actions" style="margin-top:20px">
    <button class="btn" type="submit">Save changes</button>
    <a class="btn quiet" href="{{ route($info['route'].'.index', array_filter(['barangay' => request('barangay')])) }}">Cancel</a>
  </div>
</form>
@endsection
