@extends('layouts.app')

@section('title', 'Sign in | Public WiFi Control')
@section('body-class', 'auth')

@push('head')
<style>
body.auth{min-height:100vh;display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr)}
body.auth main{display:contents}
.auth-brand{background:var(--ink);color:#fff;padding:48px 56px;display:flex;flex-direction:column;justify-content:space-between;position:relative;overflow:hidden}
.auth-brand .mark{position:absolute;right:-90px;bottom:-110px;width:520px;opacity:.9}
.auth-brand h1{font-size:clamp(2rem,4vw,3.1rem);max-width:11ch;letter-spacing:-.03em}
.auth-brand p{color:#B9C9C2;max-width:36ch;margin:14px 0 0;position:relative}
.auth-brand .foot{color:#7F958C;font-size:.85rem;position:relative}
.auth-panel{display:flex;align-items:center;justify-content:center;padding:40px 28px;background:var(--paper)}
.auth-panel form{width:100%;max-width:360px}
.auth-panel h2{font-size:1.4rem;margin-bottom:4px}
.auth-panel .lede{margin-bottom:26px}
.auth-panel .btn{width:100%;justify-content:center;margin-top:6px}
@media (max-width:780px){
  body.auth{grid-template-columns:1fr}
  .auth-brand{padding:28px 24px;min-height:190px}
  .auth-brand .mark{width:300px;right:-70px;bottom:-90px}
  .auth-brand .foot{display:none}
}
</style>
@endpush

@section('content')
<section class="auth-brand">
  <div>
    <h1>Public WiFi Control</h1>
    <p>Add MikroTik gateways and they are set up as hotspots automatically, each with its own slice of the address plan.</p>
  </div>
  <p class="foot">Authorized network staff only. Sign-ins are logged.</p>
  <svg class="mark" viewBox="0 0 200 200" fill="none" aria-hidden="true">
    <circle cx="100" cy="100" r="96" stroke="#24404A" stroke-width="2"/>
    <circle cx="100" cy="100" r="72" stroke="#2C5058" stroke-width="2"/>
    <circle cx="100" cy="100" r="48" stroke="#2F6A62" stroke-width="2"/>
    <circle cx="100" cy="100" r="24" stroke="#1F8E74" stroke-width="2"/>
    <circle cx="100" cy="100" r="7" fill="#3FC1A0"/>
  </svg>
</section>

<section class="auth-panel">
  <form method="POST" action="{{ route('login.attempt') }}" novalidate>
    @csrf
    <h2>Sign in</h2>
    <p class="lede">Use your administrator account.</p>

    <div class="field">
      <label for="email">Email</label>
      <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus
             @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
      @error('email')<p class="error" id="email-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
      <label for="password">Password</label>
      <input id="password" name="password" type="password" autocomplete="current-password" required
             @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
      @error('password')<p class="error" id="password-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
      <label class="check"><input type="checkbox" name="remember" value="1" @checked(old('remember'))> Keep me signed in</label>
    </div>

    <button class="btn" type="submit">Sign in</button>
  </form>
</section>
@endsection
