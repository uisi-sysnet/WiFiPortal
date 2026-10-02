{{-- Fields of one system user. Needs $prefix (unique ids), $bag (errors), $val (fn field => value), $editing, $lockRole (reason or null). --}}
@php
  $inv = fn ($f) => $bag->has($f) ? 'aria-invalid=true aria-describedby='.$prefix.'-'.$f.'-err' : '';
  $err = fn ($f) => $bag->has($f) ? '<p class="error" id="'.$prefix.'-'.$f.'-err">'.e($bag->first($f)).'</p>' : '';
@endphp
<div class="ac-grid">
  <div class="field">
    <label for="{{ $prefix }}-name">Full name</label>
    <input id="{{ $prefix }}-name" name="name" type="text" maxlength="100" autocomplete="off" value="{{ $val('name') }}" placeholder="Juan Dela Cruz" required {!! $inv('name') !!}>
    {!! $err('name') !!}
    <p class="hint">Printed on the reports this person generates.</p>
  </div>
  <div class="field">
    <label for="{{ $prefix }}-position">Position</label>
    <input id="{{ $prefix }}-position" name="position" type="text" maxlength="100" value="{{ $val('position') }}" placeholder="Network Engineer" required {!! $inv('position') !!}>
    {!! $err('position') !!}
  </div>
  <div class="field">
    <label for="{{ $prefix }}-department">Department</label>
    <input id="{{ $prefix }}-department" name="department" type="text" maxlength="120" value="{{ $val('department') }}" placeholder="System & Network Department" required {!! $inv('department') !!}>
    {!! $err('department') !!}
  </div>
  <div class="field">
    <label for="{{ $prefix }}-contact">Contact</label>
    <input id="{{ $prefix }}-contact" name="contact" type="text" maxlength="100" value="{{ $val('contact') }}" placeholder="0917 123 4567" required {!! $inv('contact') !!}>
    {!! $err('contact') !!}
  </div>
  <div class="field">
    <label for="{{ $prefix }}-email">Email (to sign in)</label>
    <input id="{{ $prefix }}-email" name="email" type="email" maxlength="255" autocomplete="off" value="{{ $val('email') }}" required {!! $inv('email') !!}>
    {!! $err('email') !!}
  </div>
  <div class="field">
    <label for="{{ $prefix }}-role">Role</label>
    @if ($lockRole)
      <input type="hidden" name="role" value="{{ $val('role') }}">
      <select id="{{ $prefix }}-role" disabled><option>{{ \App\Models\User::ROLES[$val('role')]['label'] ?? $val('role') }}</option></select>
      <p class="hint">{{ $lockRole }}</p>
    @else
      <select id="{{ $prefix }}-role" name="role" {!! $inv('role') !!}>
        @foreach (\App\Models\User::ROLES as $key => $r)
          <option value="{{ $key }}" @selected($val('role') === $key)>{{ $r['label'] }}: {{ $r['access'] }}</option>
        @endforeach
      </select>
      {!! $err('role') !!}
    @endif
  </div>
  <div class="field">
    <label for="{{ $prefix }}-password">{{ $editing ? 'New password' : 'Password' }}</label>
    <input id="{{ $prefix }}-password" name="password" type="password" autocomplete="new-password" {!! $inv('password') !!}
           placeholder="{{ $editing ? 'Leave blank to keep it' : 'At least 12 characters' }}">
    {!! $err('password') !!}
  </div>
  <div class="field">
    <label for="{{ $prefix }}-password2">Repeat the password</label>
    <input id="{{ $prefix }}-password2" name="password_confirmation" type="password" autocomplete="new-password">
  </div>
</div>
