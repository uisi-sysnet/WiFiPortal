@include("layouts.header")
@auth
<header class="fixed top-0 left-0 right-0 z-50 flex items-center gap-8 px-8 py-3.5
               bg-[linear-gradient(180deg,#0a4d0a_0%,#063506_100%)]
               text-white
               border-b border-[#063506]
               shadow-[0_1px_0_rgba(0,0,0,.25),0_8px_24px_-8px_rgba(0,0,0,.35)]
               max-[860px]:flex-wrap max-[860px]:gap-x-5 max-[860px]:gap-y-3 max-[860px]:px-5">

  {{-- ── Futuristic overlay: clean lines + subtle glow ── --}}
  <div class="pointer-events-none absolute inset-0 z-0 overflow-hidden" aria-hidden="true">

    {{-- Soft yellow ambient glow (center-weighted, toned down) --}}
    <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[60%] h-40 rounded-full bg-[#F2B84B]/[0.07] blur-3xl"></div>
    <div class="absolute -left-20 top-1/2 -translate-y-1/2 w-72 h-32 rounded-full bg-[#F2B84B]/[0.05] blur-3xl"></div>
    <div class="absolute -right-20 top-1/2 -translate-y-1/2 w-72 h-32 rounded-full bg-[#F2B84B]/[0.05] blur-3xl"></div>

    {{-- Diagonal scan lines (thin, subtle) --}}
    <div class="absolute inset-0 opacity-[0.08]"
         style="background-image: repeating-linear-gradient(115deg, transparent 0 18px, #F2B84B 18px 19px, transparent 19px 60px);"></div>

    {{-- Horizontal futuristic scanline --}}
    <div class="absolute top-1/2 left-0 right-0 h-px -translate-y-1/2 bg-gradient-to-r from-transparent via-[#F2B84B]/35 to-transparent"></div>
    <div class="absolute top-1/2 left-0 right-0 h-6 -translate-y-1/2 bg-[#F2B84B]/[0.03] blur-md"></div>

    {{-- WiFi arc motif (center, minimal) --}}
    <svg class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 opacity-35"
         width="120" height="60" viewBox="0 0 120 60" fill="none" xmlns="http://www.w3.org/2000/svg">
      <path d="M10 45 A50 50 0 0 1 110 45" stroke="#F2B84B" stroke-width="1.4" stroke-linecap="round" stroke-opacity="0.85"/>
      <path d="M28 45 A32 32 0 0 1 92 45" stroke="#F2B84B" stroke-width="1.2" stroke-linecap="round" stroke-opacity="0.65"/>
      <path d="M46 45 A14 14 0 0 1 74 45" stroke="#F2B84B" stroke-width="1" stroke-linecap="round" stroke-opacity="0.5"/>
      <circle cx="60" cy="50" r="2" fill="#F2B84B"/>
    </svg>

    {{-- Fine grid --}}
    <div class="absolute inset-0 opacity-[0.04]"
         style="background-image: linear-gradient(#F2B84B 1px, transparent 1px), linear-gradient(90deg, #F2B84B 1px, transparent 1px); background-size: 48px 48px;"></div>

    {{-- Bottom glow accent --}}
    <div class="absolute bottom-0 left-0 right-0 h-px bg-gradient-to-r from-transparent via-[#F2B84B]/70 to-transparent"></div>
    <div class="absolute bottom-0 left-0 right-0 h-4 bg-gradient-to-t from-[#F2B84B]/15 to-transparent"></div>
  </div>

  {{-- Left: brand --}}
  <a class="relative z-10 flex items-center gap-3.5 text-white no-underline font-bold tracking-tight shrink-0 group" href="{{ route('dashboard') }}">

    {{-- Icon badge with glow halo --}}
    <span class="relative grid place-items-center w-10 h-10 rounded-full bg-[#F2B84B]/20 border-2 border-[#F2B84B] shadow-[0_0_12px_rgba(242,184,75,0.45),inset_0_0_8px_rgba(242,184,75,0.2)] transition-all group-hover:bg-[#F2B84B]/30 group-hover:shadow-[0_0_18px_rgba(242,184,75,0.7),inset_0_0_12px_rgba(242,184,75,0.3)]">
      {{-- Ambient halo --}}
      <span class="absolute inset-0 rounded-full bg-[#F2B84B]/15 blur-md -z-10"></span>
      <svg class="shrink-0 drop-shadow-[0_0_4px_rgba(242,184,75,0.8)]" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#F2B84B" stroke-width="2.4" stroke-linecap="round" aria-hidden="true">
        <path d="M2 8.5a15 15 0 0 1 20 0"/>
        <path d="M5.5 12.5a10 10 0 0 1 13 0"/>
        <path d="M9 16.3a5 5 0 0 1 6 0"/>
        <circle cx="12" cy="20" r="1.3" fill="#F2B84B" stroke="none"/>
      </svg>
    </span>

    {{-- Vertical divider --}}
    <span class="w-px h-9 bg-gradient-to-b from-transparent via-[#F2B84B]/40 to-transparent"></span>

    {{-- Text lockup --}}
    <span class="flex flex-col leading-[1.15]">
      <span class="inline-flex items-center gap-1">
        <span class="text-[15px] font-bold tracking-[-0.01em] text-white drop-shadow-[0_0_6px_rgba(0,0,0,0.35)]">Public WiFi Control</span>

        {{-- Beta badge: solid yellow for high visibility --}}
        <span class="inline-flex items-center rounded-full bg-[#F2B84B] px-2 py-0.5 text-[9px] font-bold uppercase tracking-[0.12em] text-[#063506] shadow-[0_0_10px_rgba(242,184,75,0.5)]">
          Beta&nbsp;v0.1
        </span>
      </span>

      <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-[#F2B84B]/85 drop-shadow-[0_0_4px_rgba(242,184,75,0.35)]">
        Uplink Integrated Solutions Inc.
      </span>
    </span>
  </a>

  {{-- Center: nav --}}
  <nav class="relative z-10 flex-1 flex gap-1 max-[860px]:order-last max-[860px]:w-full max-[860px]:flex-wrap" aria-label="Main">
    <a href="{{ route('dashboard') }}"
       class="relative inline-flex items-center px-3 py-1.5 rounded-md text-sm font-medium
              text-white/90 no-underline transition-all duration-200
              hover:text-[#F2B84B]
              after:content-[''] after:absolute after:left-3 after:right-3 after:bottom-0.5 after:h-px
              after:bg-[#F2B84B] after:opacity-0 after:shadow-[0_0_8px_rgba(242,184,75,0.9)]
              hover:after:opacity-100
              aria-[current=page]:text-[#F2B84B] aria-[current=page]:font-semibold
              aria-[current=page]:after:opacity-100"
       @if(request()->routeIs('dashboard')) aria-current="page" @endif>Dashboard</a>
    @include('partials.devices-menu')
    <a href="{{ route('users.index') }}"
       class="relative inline-flex items-center px-3 py-1.5 rounded-md text-sm font-medium
              text-white/90 no-underline transition-all duration-200
              hover:text-[#F2B84B]
              after:content-[''] after:absolute after:left-3 after:right-3 after:bottom-0.5 after:h-px
              after:bg-[#F2B84B] after:opacity-0 after:shadow-[0_0_8px_rgba(242,184,75,0.9)]
              hover:after:opacity-100
              aria-[current=page]:text-[#F2B84B] aria-[current=page]:font-semibold
              aria-[current=page]:after:opacity-100"
       @if(request()->routeIs('users.*')) aria-current="page" @endif>Users</a>
    <a href="{{ route('splash.edit') }}"
       class="relative inline-flex items-center px-3 py-1.5 rounded-md text-sm font-medium
              text-white/90 no-underline transition-all duration-200
              hover:text-[#F2B84B]
              after:content-[''] after:absolute after:left-3 after:right-3 after:bottom-0.5 after:h-px
              after:bg-[#F2B84B] after:opacity-0 after:shadow-[0_0_8px_rgba(242,184,75,0.9)]
              hover:after:opacity-100
              aria-[current=page]:text-[#F2B84B] aria-[current=page]:font-semibold
              aria-[current=page]:after:opacity-100"
       @if(request()->routeIs('splash.*')) aria-current="page" @endif>Captive portal</a>
  </nav>

  {{-- Right: account --}}
  <div class="relative z-10 shrink-0 max-[860px]:ml-auto">
    @include('partials.account-menu')
  </div>
</header>
@endauth
<main class="w-full pt-20 pb-0">
  @yield('content')
</main>
@include('layouts.footer')