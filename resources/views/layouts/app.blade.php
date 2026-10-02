@include("layouts.header")
@auth
@include('partials.sidebar')
<header class="fixed top-0 left-[var(--sb-w,0px)] right-0 z-50 flex items-center gap-8 px-8 py-3.5 min-h-[68px] transition-[left] duration-200
               max-[900px]:pl-[68px]
               bg-[linear-gradient(180deg,#0a4d0a_0%,#063506_100%)]
               text-white
               border-b border-[#063506]
               shadow-[0_1px_0_rgba(0,0,0,.25),0_8px_24px_-8px_rgba(0,0,0,.35)]
               max-[860px]:gap-x-5 max-[860px]:pr-5">

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

  {{-- Left: the page name (the menu and brand are in the sidebar) --}}
  <p class="relative z-10 flex-1 m-0 text-[17px] font-semibold tracking-[-0.01em] text-white truncate">
    {{ trim(explode('|', View::yieldContent('title', 'Public WiFi Control'))[0]) }}
  </p>

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
