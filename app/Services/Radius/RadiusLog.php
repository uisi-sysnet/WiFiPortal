<?php

namespace App\Services\Radius;

use App\Models\HotspotGuest;
use App\Models\MikrotikRouter;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads what FreeRADIUS records in the shared database, for the RADIUS page:
 *   radpostauth  every login attempt (Access-Accept / Access-Reject)
 *   radacct      every session (router, start/stop, time, data, why it ended)
 *   radcheck     the logins this app created (password, MAC, Expiration)
 *
 * The tables belong to FreeRADIUS (deploy/setup-radius.sh); without them the page
 * says RADIUS is not set up. Passwords are never read.
 *
 * Usernames are either the app's logins ("wifi-k7m2p9qx") or a phone's MAC: routers
 * first try the MAC (login-by=mac), so a phone that has not registered is rejected
 * once by MAC before it sees the portal. Those rejects are normal and counted apart.
 */
class RadiusLog
{
    private const MAC = '/^([0-9A-F]{2}[:\-]){5}[0-9A-F]{2}$/i';

    private ?bool $available = null;

    private array $columns = [];

    public function configured(): bool
    {
        return ! empty(config('hotspot.radius.host')) && ! empty(config('hotspot.radius.secret'));
    }

    /** FreeRADIUS's tables exist in this database. */
    public function available(): bool
    {
        return $this->available ??= Schema::hasTable('radpostauth') && Schema::hasTable('radacct') && Schema::hasTable('radcheck');
    }

    public static function isMac(?string $username): bool
    {
        return (bool) preg_match(self::MAC, (string) $username);
    }

    /* ---------- Overview ---------- */

    /** @return array{accepted:int, rejected:int, mac_rejected:int, user_rejected:int} */
    public function counts(Carbon $from): array
    {
        $rows = DB::table('radpostauth')->where('authdate', '>=', $from->copy()->utc())
            ->select('username', 'reply')->get();
        $rejected = $rows->filter(fn ($r) => $this->rejected($r->reply));
        $mac = $rejected->filter(fn ($r) => self::isMac($r->username))->count();

        return [
            'accepted' => $rows->count() - $rejected->count(),
            'rejected' => $rejected->count(),
            'mac_rejected' => $mac,
            'user_rejected' => $rejected->count() - $mac,
        ];
    }

    /** Health of the RADIUS link, for the top of the page. */
    public function health(): array
    {
        $lastAccept = DB::table('radpostauth')->where('reply', 'Access-Accept')->max('authdate');
        $lastAny = DB::table('radpostauth')->max('authdate');
        $hour = $this->counts(now()->subHour());

        // Registrations still valid whose login is missing from radcheck: they can't get online
        $valid = HotspotGuest::query()->whereNull('revoked_at')->where('expires_at', '>', now())->pluck('username');
        $inRadius = $valid->isEmpty() ? collect() : DB::table('radcheck')->whereIn('username', $valid)->distinct()->pluck('username');

        return [
            'configured' => $this->configured(),
            'last_accept' => $lastAccept ? Carbon::parse($lastAccept, 'UTC') : null,
            'last_any' => $lastAny ? Carbon::parse($lastAny, 'UTC') : null,
            'hour' => $hour,
            'day' => $this->counts(now()->subDay()),
            'online' => $this->onlineQuery()->count(),
            'logins' => DB::table('radcheck')->where('attribute', 'Cleartext-Password')->count(),
            'valid' => $valid->count(),
            'missing' => $valid->count() - $inRadius->count(),
            // Many login rejects in the last hour (not the normal MAC checks): something is wrong
            'alarm' => $hour['user_rejected'] >= 20 && $hour['user_rejected'] > $hour['accepted'],
        ];
    }

    /* ---------- Login attempts ---------- */

    /** @param  array{result?:?string, q?:?string, kind?:?string}  $f */
    public function attempts(array $f, int $perPage = 50): LengthAwarePaginator
    {
        $q = DB::table('radpostauth')
            ->when(($f['result'] ?? null) === 'accepted', fn ($q) => $q->where('reply', 'Access-Accept'))
            ->when(($f['result'] ?? null) === 'rejected', fn ($q) => $q->where('reply', '!=', 'Access-Accept'))
            ->when($f['q'] ?? null, fn ($q, $term) => $this->search($q, $term, 'radpostauth'))
            ->when($f['ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->orderByDesc('authdate')->orderByDesc('id');

        $page = $q->paginate($perPage, $this->pick('radpostauth', ['id', 'username', 'reply', 'authdate', 'callingstationid', 'calledstationid']))
            ->withQueryString();

        // Who each attempt belongs to, and why a reject probably happened
        $rows = collect($page->items());
        $guests = $this->guestsFor($rows->pluck('username')->all());
        $macLogins = $this->macLogins($rows->pluck('username')->filter(fn ($u) => self::isMac($u))->all());

        $page->setCollection($rows->map(function ($r) use ($guests, $macLogins) {
            $mac = self::isMac($r->username);
            $guest = $guests[strtoupper($r->username)] ?? $guests[$r->username] ?? null;
            $ok = ! $this->rejected($r->reply);

            return [
                'id' => $r->id,
                'at' => Carbon::parse($r->authdate, 'UTC'),
                'username' => $r->username,
                'by_mac' => $mac,
                'accepted' => $ok,
                'phone' => $r->callingstationid ?? ($mac ? strtoupper($r->username) : $guest?->mac),
                'server' => $r->calledstationid ?? null,
                'guest' => $guest,
                'reason' => $ok ? null : $this->reason($r->username, $mac, $guest, $macLogins),
            ];
        }));

        return $page;
    }

    /* ---------- Sessions ---------- */

    /** @param  array{status?:?string, q?:?string, router?:?int}  $f */
    public function sessions(array $f, int $perPage = 50): LengthAwarePaginator
    {
        $routers = MikrotikRouter::query()->get(['id', 'name', 'host']);
        $q = (($f['status'] ?? 'online') === 'online' ? $this->onlineQuery() : DB::table('radacct'))
            ->when($f['q'] ?? null, fn ($q, $term) => $this->search($q, $term, 'radacct'))
            ->when($f['router'] ?? null, fn ($q, $id) => $q->where('nasipaddress', (string) $routers->firstWhere('id', $id)?->host))
            ->when($f['ids'] ?? null, fn ($q, $ids) => $q->whereIn('radacctid', $ids))
            ->orderByDesc('acctstarttime')->orderByDesc('radacctid');

        $page = $q->paginate($perPage, $this->pick('radacct', [
            'radacctid', 'username', 'nasipaddress', 'calledstationid', 'callingstationid', 'framedipaddress',
            'acctstarttime', 'acctupdatetime', 'acctstoptime', 'acctsessiontime', 'acctinputoctets', 'acctoutputoctets', 'acctterminatecause',
        ]))->withQueryString();

        $rows = collect($page->items());
        $guests = $this->guestsFor($rows->pluck('username')->all());
        $byHost = $routers->keyBy(fn ($r) => (string) $r->host);

        $since = now()->subMinutes((int) config('hotspot.radius.online_minutes'));
        $page->setCollection($rows->map(function ($r) use ($guests, $byHost, $since) {
            $start = $r->acctstarttime ? Carbon::parse($r->acctstarttime, 'UTC') : null;
            $last = ($r->acctupdatetime ?? null) ? Carbon::parse($r->acctupdatetime, 'UTC') : $start;
            $online = $r->acctstoptime === null && $last && $last->gte($since);

            return [
                'id' => $r->radacctid,
                'username' => $r->username,
                'guest' => $guests[strtoupper((string) $r->username)] ?? $guests[$r->username] ?? null,
                'phone' => $r->callingstationid ?? null,
                'ip' => $r->framedipaddress ?? null,
                'router' => $byHost[(string) $r->nasipaddress]->name ?? $r->nasipaddress,
                'server' => $r->calledstationid ?? null,
                'start' => $start,
                // An open session with no recent update: the router stopped reporting (rebooted or down)
                'stop' => $r->acctstoptime ? Carbon::parse($r->acctstoptime, 'UTC') : ($online ? null : $last),
                'seconds' => $online && $start ? now()->getTimestamp() - $start->getTimestamp() : (int) $r->acctsessiontime,
                'upload' => (int) $r->acctinputoctets,     // from the phone
                'download' => (int) $r->acctoutputoctets,  // to the phone
                'online' => $online,
                'cause' => $r->acctterminatecause ?: ($online || $r->acctstoptime ? null : 'no updates from the router'),
            ];
        }));

        return $page;
    }

    /* ---------- Look up a phone or login ---------- */

    public function lookup(string $term): array
    {
        $term = trim($term);
        $mac = self::isMac($term) ? strtoupper(str_replace('-', ':', $term)) : null;

        $guests = HotspotGuest::query()
            ->where(fn ($q) => $mac ? $q->where('mac', $mac) : $q->where('username', $term))
            ->with(['router:id,name', 'network:id,name'])
            ->latest()->limit(10)->get();
        $usernames = array_values(array_unique(array_filter([$mac, $term, ...$guests->pluck('username')->all()])));

        $check = DB::table('radcheck')->whereIn('username', $usernames)->whereIn('attribute', ['Expiration', 'Calling-Station-Id', 'Cleartext-Password'])
            ->get(['username', 'attribute', 'value'])
            ->groupBy('username')
            ->map(fn ($rows) => [
                'expiration' => $rows->firstWhere('attribute', 'Expiration')?->value,
                'mac' => $rows->firstWhere('attribute', 'Calling-Station-Id')?->value,
                'has_password' => $rows->contains('attribute', 'Cleartext-Password'),
            ]);

        $search = fn ($table) => DB::table($table)->where(fn ($q) => $q->whereIn('username', $usernames)
            ->when($mac && $this->has($table, 'callingstationid'), fn ($q) => $q->orWhere('callingstationid', $mac)));

        return [
            'term' => $term,
            'mac' => $mac,
            'guests' => $guests,
            'check' => $check,
            'attempts' => $this->rowsFor('attempts', $search('radpostauth')->orderByDesc('authdate')->limit(20)),
            'sessions' => $this->rowsFor('sessions', $search('radacct')->orderByDesc('acctstarttime')->limit(20)),
        ];
    }

    /** Deletes old attempts and finished sessions (RADIUS_AUTH_LOG_DAYS, RADIUS_ACCT_DAYS). */
    public function prune(): array
    {
        if (! $this->available()) {
            return [0, 0];
        }

        return [
            DB::table('radpostauth')->where('authdate', '<', now()->subDays((int) config('hotspot.radius.auth_log_days')))->delete(),
            DB::table('radacct')->where(fn ($q) => $q
                ->where('acctstoptime', '<', now()->subDays((int) config('hotspot.radius.acct_days')))
                ->orWhere(fn ($q) => $q->whereNull('acctstoptime')->where('acctstarttime', '<', now()->subDays((int) config('hotspot.radius.acct_days')))))
                ->delete(),
        ];
    }

    /* ---------- Helpers ---------- */

    /** Open sessions still sending interim updates (a router that died leaves its sessions open). */
    private function onlineQuery()
    {
        $since = now()->subMinutes((int) config('hotspot.radius.online_minutes'));

        return DB::table('radacct')->whereNull('acctstoptime')
            ->where(fn ($q) => $q->where('acctupdatetime', '>=', $since)
                ->orWhere(fn ($q) => $q->whereNull('acctupdatetime')->where('acctstarttime', '>=', $since)));
    }

    private function rowsFor(string $kind, $query): array
    {
        $ids = $query->pluck($kind === 'attempts' ? 'id' : 'radacctid')->all();
        if (! $ids) {
            return [];
        }
        $page = $kind === 'attempts'
            ? $this->attempts(['ids' => $ids], 20)
            : $this->sessions(['status' => 'all', 'ids' => $ids], 20);

        return array_values(array_filter($page->items(), fn ($r) => in_array($r['id'], $ids)));
    }

    private function search($q, string $term, string $table)
    {
        $term = trim($term);
        $like = '%'.mb_strtolower($term).'%';
        $mac = self::isMac($term) ? strtoupper(str_replace('-', ':', $term)) : null;
        $usernames = $mac ? [$mac] : HotspotGuest::query()->whereRaw('lower(mac) like ?', [$like])->limit(50)->pluck('username')->all();

        return $q->where(fn ($w) => $w->whereRaw('lower(username) like ?', [$like])
            ->when($usernames, fn ($w) => $w->orWhereIn('username', $usernames))
            ->when($this->has($table, 'callingstationid'), fn ($w) => $w->orWhereRaw('lower(callingstationid) like ?', [$like])));
    }

    private function rejected(?string $reply): bool
    {
        return $reply !== 'Access-Accept';
    }

    /** The most likely reason for a reject, from what this app knows. */
    private function reason(string $username, bool $mac, ?HotspotGuest $guest, array $macLogins): string
    {
        if ($mac) {
            return isset($macLogins[strtoupper($username)])
                ? 'MAC login past its access time'
                : 'Phone not registered: normal, it is shown the captive portal';
        }
        if (! $guest) {
            return 'Unknown login (not created by this system, or deleted)';
        }
        if ($guest->revoked_at || $guest->expires_at?->isPast()) {
            return 'Access time ended '.($guest->revoked_at ?? $guest->expires_at)->diffForHumans();
        }

        return 'Wrong password, or a different phone (MAC) than the one that registered';
    }

    /** @return array<string, HotspotGuest> keyed by username, and by MAC for MAC logins */
    private function guestsFor(array $usernames): array
    {
        $usernames = array_values(array_unique(array_filter($usernames)));
        if (! $usernames) {
            return [];
        }
        $macs = array_map(fn ($u) => strtoupper(str_replace('-', ':', $u)), array_filter($usernames, fn ($u) => self::isMac($u)));

        $out = [];
        HotspotGuest::query()->whereIn('username', $usernames)->get()->each(function ($g) use (&$out) { $out[$g->username] = $g; });
        if ($macs) {
            HotspotGuest::query()->whereIn('mac', $macs)->orderBy('created_at')->get()->each(function ($g) use (&$out) { $out[$g->mac] = $g; });
        }

        return $out;
    }

    /** MACs with a MAC login in radcheck, keyed by MAC. */
    private function macLogins(array $macs): array
    {
        $macs = array_map(fn ($m) => strtoupper(str_replace('-', ':', $m)), $macs);

        return $macs ? DB::table('radcheck')->whereIn('username', $macs)->distinct()->pluck('username')->flip()->all() : [];
    }

    /** Only the columns this FreeRADIUS version has (radpostauth's station columns came in 3.2). */
    private function pick(string $table, array $wanted): array
    {
        return array_values(array_intersect($wanted, $this->columns($table)));
    }

    private function has(string $table, string $column): bool
    {
        return in_array($column, $this->columns($table), true);
    }

    private function columns(string $table): array
    {
        return $this->columns[$table] ??= array_map('strtolower', Schema::getColumnListing($table));
    }
}
