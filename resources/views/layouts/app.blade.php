@include('layouts.header')
@auth
@include('partials.sidebar')
<header class="admin-topbar">
  <div class="admin-context">
    <span class="admin-eyebrow">Public WiFi Control</span>
    <p>{{ trim(explode('|', View::yieldContent('title', 'Administration'))[0]) }}</p>
  </div>
  @include('partials.account-menu')
</header>
@endauth
<main id="main-content">
  @yield('content')
</main>
@include('layouts.footer')
