@include("layouts.header")

@auth
<header class="fixed top-0 left-0 right-0 z-50 flex items-center gap-7 px-7 py-3 bg-ink text-white max-[860px]:flex-wrap max-[860px]:gap-x-5 max-[860px]:gap-y-3 max-[860px]:px-[18px]">

  {{-- Left: brand --}}
  <a class="flex items-center gap-2.5 text-white no-underline font-bold tracking-tight shrink-0" href="{{ route('dashboard') }}">
    <svg class="shrink-0" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#3FC1A0" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
      <path d="M2 8.5a15 15 0 0 1 20 0"/>
      <path d="M5.5 12.5a10 10 0 0 1 13 0"/>
      <path d="M9 16.3a5 5 0 0 1 6 0"/>
      <circle cx="12" cy="20" r="1.2" fill="#3FC1A0" stroke="none"/>
    </svg>
    <span class="flex flex-col leading-tight">
    <span class="inline-flex items-center gap-2">
      Public WiFi Control
      <span class="inline-flex items-center gap-1.5 rounded-full border border-signal/30 bg-signal-soft px-1 text-[10px] font-medium uppercase tracking-widest text-signal">
        Beta v0.1
      </span>
    </span>
      <span class="text-[10px] font-medium uppercase tracking-widest text-signal/80">Uplink Integrated Solutions Inc.</span>
    </span>
  </a>

  {{-- Center: nav --}}
  <nav class="flex-1 flex gap-5" aria-label="Main">
    <a href="{{ route('dashboard') }}"
      class="text-[#B9C9C2] no-underline py-1 border-b-2 border-transparent hover:text-white aria-[current=page]:text-white aria-[current=page]:border-signal"
      @if(request()->routeIs('dashboard')) aria-current="page" @endif>Dashboard</a>

    @include('partials.devices-menu')

    <a href=""
      class="text-[#B9C9C2] no-underline py-1 border-b-2 border-transparent hover:text-white aria-[current=page]:text-white aria-[current=page]:border-signal"
      @if(request()->routeIs('guest.*')) aria-current="page" @endif>Guest</a>

    <a href="{{ route('splash.edit') }}"
      class="text-[#B9C9C2] no-underline py-1 border-b-2 border-transparent hover:text-white aria-[current=page]:text-white aria-[current=page]:border-signal"
      @if(request()->routeIs('splash.*')) aria-current="page" @endif>Captive portal</a>
  </nav>

  {{-- Right: account --}}
  <div class="shrink-0">
    @include('partials.account-menu')
  </div>

</header>
@endauth

<main class="max-w-[1100px] mx-auto px-6 pt-20 pb-[72px]">
  @yield('content')
</main>

@include('layouts.footer')
