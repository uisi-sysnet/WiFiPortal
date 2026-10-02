{{--
  Open capacity alerts (busy/full routers and address pools), worst first.
  Rendered with the dashboard and again by dashboard.live. Needs: $alerts.
--}}
@if ($alerts->isNotEmpty())
  <section class="panel alerts" aria-labelledby="alerts-title">
    <div class="panel-head">
      <h2 id="alerts-title">Capacity alerts</h2>
      <p>{{ $alerts->where('level', 'full')->count() }} full, {{ $alerts->where('level', 'busy')->count() }} busy</p>
    </div>
    <ul class="alert-list">
      @foreach ($alerts->take(6) as $a)
        <li class="{{ $a->level }}">
          <span class="lvl">{{ $a->level === 'full' ? 'Full' : 'Busy' }}</span>
          <div>
            <a href="{{ route('routers.show', $a->mikrotik_router_id) }}#capacity">{{ $a->title() }}</a>
            <p>{{ $a->message }}</p>
          </div>
          <time datetime="{{ $a->opened_at->toIso8601String() }}">{{ $a->opened_at->diffForHumans(short: true) }}</time>
        </li>
      @endforeach
    </ul>
    @if ($alerts->count() > 6)
      <p class="alert-more">and {{ $alerts->count() - 6 }} more. See each router's page.</p>
    @endif
  </section>
@endif
