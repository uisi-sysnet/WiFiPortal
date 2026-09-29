@extends('layouts.app')

@section('title', 'Logs | Public WiFi Control')

@section('content')
<div class="page-head">
  <div>
    <h1>Logs</h1>
    <p class="lede">One place for sign-ins, configuration changes, router events and hotspot registrations.</p>
  </div>
</div>

<div class="plan">
  <h2>No log entries yet</h2>
  <p>The system-wide log is not collected yet. Each router's configuration steps are on its own page.</p>
  <p style="margin-top:14px"><a class="btn quiet" href="{{ route('routers.index') }}">Open routers</a></p>
</div>
@endsection
