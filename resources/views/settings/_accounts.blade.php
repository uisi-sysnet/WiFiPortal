{{--
  Settings > System users: add, edit and delete the people who sign in. Needs $accounts.
  The full name, position and department are printed on the reports each person generates.
--}}
@php
  $nErr = $errors->getBag('newAccount');
  $nOld = fn ($f, $d = '') => $nErr->any() ? old($f, $d) : $d;
  $me = auth()->user();
  $tz = config('hotspot.history.timezone');
  $adminCount = $accounts->where('role', 'admin')->count();
@endphp
<style>
.ac-roles{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:0 0 18px}
.ac-role{border:1px solid #C9D6CE;border-radius:8px;padding:10px 14px;background:#fff}
.ac-role b{display:block;margin-bottom:2px}
.ac-role span{font-size:.84rem;color:#5c6b66}
.ac-table{width:100%;border-collapse:collapse;background:#fff;border:2px solid #0e670d;border-radius:8px;overflow:hidden;font-size:.88rem}
.ac-table th,.ac-table td{padding:10px 12px;border-bottom:1px solid #DDE7E0;text-align:left;vertical-align:top}
.ac-table th{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#5c6b66;background:#FCFDFC}
.ac-table td small{display:block;color:#5c6b66;font-size:.78rem;margin-top:2px}
.ac-table tr.ac-edit-row td{background:#F8FAF9;padding:16px}
.ac-badge{display:inline-block;padding:2px 9px;border-radius:999px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.ac-badge.admin{background:#0e670d;color:#fff}
.ac-badge.user{background:#E2EBF7;color:#1d4fa3}
.ac-badge.viewer{background:#EEF3EF;color:#5c6b66}
.ac-you{font-size:.7rem;font-weight:700;color:#0e670d;margin-left:6px}
.ac-actions{display:flex;gap:8px;justify-content:flex-end;white-space:nowrap}
.ac-actions form{margin:0}
.ac-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px 20px}
.ac-new{margin-top:22px;padding:18px;background:#fff;border:2px solid #0e670d;border-radius:8px}
.ac-new h3,.ac-edit h3{margin:0 0 12px;font-size:1.05rem}
.ac-wrap{overflow-x:auto}
@media (max-width:900px){.ac-roles{grid-template-columns:1fr}}
</style>

<section class="settings-section" id="accounts" aria-labelledby="ac-title">
  <h2 id="ac-title">System users</h2>
  <p class="hint" style="margin:0 0 14px">Who can sign in to this dashboard, and what they can do. The full name, position and department are printed on the reports a person generates.</p>

  <div class="ac-roles">
    @foreach (\App\Models\User::ROLES as $key => $r)
      <div class="ac-role"><b><span class="ac-badge {{ $key }}">{{ $r['label'] }}</span></b><span>{{ $r['access'] }}</span></div>
    @endforeach
  </div>

  <div class="ac-wrap">
    <table class="ac-table">
      <thead>
        <tr><th scope="col">Full name</th><th scope="col">Position and department</th><th scope="col">Contact</th><th scope="col">Role</th><th scope="col">Last sign-in</th><th scope="col"><span class="sr-only">Actions</span></th></tr>
      </thead>
      <tbody>
        @foreach ($accounts as $a)
          @php
            $bag = $errors->getBag('account'.$a->id);
            $eOld = fn ($f, $d) => $bag->any() ? old($f, $d) : $d;
            $isMe = $me && $a->is($me);
            $soleAdmin = $a->isAdmin() && $adminCount === 1;
          @endphp
          <tr>
            <td><b>{{ $a->name }}</b>@if ($isMe)<span class="ac-you">YOU</span>@endif<small>{{ $a->email }}</small></td>
            <td>{{ $a->position ?: '–' }}<small>{{ $a->department }}</small></td>
            <td>{{ $a->contact ?: '–' }}</td>
            <td><span class="ac-badge {{ $a->role }}">{{ $a->roleLabel() }}</span></td>
            <td>{{ $a->last_login_at ? $a->last_login_at->copy()->setTimezone($tz)->format('M j, Y H:i') : 'Never' }}</td>
            <td>
              <div class="ac-actions">
                <button class="btn quiet sm" type="button" data-edit="{{ $a->id }}" aria-expanded="{{ $bag->any() ? 'true' : 'false' }}" aria-controls="ac-edit-{{ $a->id }}">Edit</button>
                <form method="POST" action="{{ route('accounts.destroy', $a) }}" onsubmit="return confirm('Delete {{ addslashes($a->name) }}? They will no longer be able to sign in.')">
                  @csrf @method('DELETE')
                  <button class="btn danger sm" type="submit" @disabled($isMe || $soleAdmin)
                          @if($isMe) title="You can't delete your own account" @elseif($soleAdmin) title="The only administrator can't be deleted" @endif>Delete</button>
                </form>
              </div>
            </td>
          </tr>
          <tr class="ac-edit-row" id="ac-edit-{{ $a->id }}" @unless($bag->any()) hidden @endunless>
            <td colspan="6">
              <form class="ac-edit" method="POST" action="{{ route('accounts.update', $a) }}" novalidate>
                @csrf @method('PUT')
                <h3>Edit {{ $a->name }}</h3>
                @include('settings._account-fields', ['prefix' => 'e'.$a->id, 'bag' => $bag, 'val' => fn ($f) => $eOld($f, $a->{$f}), 'editing' => true,
                  'lockRole' => $isMe ? 'You can\'t change your own role.' : ($soleAdmin ? 'The only administrator stays an administrator.' : null)])
                <div class="actions" style="margin-top:6px">
                  <button class="btn" type="submit">Save</button>
                  <button class="btn quiet" type="button" data-edit="{{ $a->id }}">Cancel</button>
                </div>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  <form class="ac-new" method="POST" action="{{ route('accounts.store') }}" novalidate>
    @csrf
    <h3>Add a user</h3>
    @include('settings._account-fields', ['prefix' => 'new', 'bag' => $nErr, 'val' => fn ($f) => $nOld($f, $f === 'role' ? 'viewer' : ''), 'editing' => false, 'lockRole' => null])
    <div class="actions" style="margin-top:6px"><button class="btn" type="submit">Add user</button></div>
  </form>
</section>

<script>
  // Edit opens the form under the row; Cancel (or Edit again) closes it
  document.querySelectorAll('[data-edit]').forEach(function (b) {
    b.addEventListener('click', function () {
      var row = document.getElementById('ac-edit-' + b.dataset.edit);
      row.hidden = !row.hidden;
      document.querySelectorAll('[data-edit="' + b.dataset.edit + '"][aria-expanded]').forEach(function (x) { x.setAttribute('aria-expanded', String(!row.hidden)); });
      if (!row.hidden) row.querySelector('input').focus();
    });
  });
</script>
