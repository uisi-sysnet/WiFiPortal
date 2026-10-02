<?php

namespace App\Services\Portal;

use App\Models\HotspotGuest;
use App\Models\MikrotikRouter;
use App\Services\Mikrotik\HotspotProvisioner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Generates a unique username and password for every registration.
 *
 *   username  prefix + 8 characters, e.g. "wifi-k7m2p9qx" (unique across all guests)
 *   password  12 random characters, never shown to the user (kept encrypted for roaming)
 *
 * Both are locked to the phone's MAC address and stop working when the
 * registration expires (its validity depends on the type of user):
 *   RADIUS configured -> rows in radcheck: Cleartext-Password, Calling-Station-Id and
 *                        Expiration, plus the MAC itself as a login ("mac-as-username-and-
 *                        password") so routers can let the phone in with no page at all.
 *                        Works on every router at once.
 *   no RADIUS yet     -> a local /ip hotspot user on each router the phone uses.
 *
 * Expired credentials are removed by `php artisan hotspot:expire-credentials`.
 */
class GuestCredentials
{
    // No 0/o, 1/l/i: easy to read if an admin ever has to type one.
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public function __construct(private HotspotProvisioner $routerApi)
    {
    }

    public function usesRadius(): bool
    {
        return ! empty(config('hotspot.radius.host')) && ! empty(config('hotspot.radius.secret'));
    }

    /** @return array{0:string,1:string} username, password */
    public function issue(MikrotikRouter $router, ?string $mac, Carbon $expiresAt): array
    {
        $username = $this->uniqueUsername();
        $password = $this->random(12);

        if ($this->usesRadius()) {
            $expiration = $this->radiusTime($expiresAt);
            DB::table('radcheck')->insert(array_values(array_filter([
                ['username' => $username, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => $password],
                // MikroTik sends the phone's MAC as Calling-Station-Id; "==" makes it a requirement.
                $mac ? ['username' => $username, 'attribute' => 'Calling-Station-Id', 'op' => '==', 'value' => $mac] : null,
                // FreeRADIUS rejects it after this, and cuts sessions off at this time.
                ['username' => $username, 'attribute' => 'Expiration', 'op' => ':=', 'value' => $expiration],
            ])));

            // The MAC as its own login: routers try it first, so a registered phone goes
            // online on any router without seeing the captive portal.
            if ($mac) {
                DB::table('radcheck')->where('username', $mac)->delete();
                DB::table('radcheck')->insert([
                    ['username' => $mac, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => $mac],
                    ['username' => $mac, 'attribute' => 'Expiration', 'op' => ':=', 'value' => $expiration],
                ]);
            }
        } else {
            $this->routerApi->upsertGuestUser($router, $username, $password, $mac);
        }

        return [$username, $password];
    }

    /**
     * Lets a still-valid registration log in on this router too (roaming).
     * With RADIUS nothing is needed; without it, the router gets its own copy.
     */
    public function roam(HotspotGuest $guest, MikrotikRouter $router): void
    {
        if ($this->usesRadius()) {
            return;
        }
        $ids = $guest->routerIdsWithLogin();
        if (in_array($router->id, $ids, true)) {
            return;
        }
        $this->routerApi->upsertGuestUser($router, $guest->username, $guest->password, $guest->mac);
        $guest->forceFill(['local_router_ids' => [...$ids, $router->id]])->save();
    }

    /** Deletes the credentials of expired registrations. Returns how many were removed. */
    public function expire(): int
    {
        $removed = 0;

        HotspotGuest::query()
            ->whereNull('revoked_at')
            ->where('expires_at', '<', now())
            ->chunkById(200, function ($guests) use (&$removed) {
                if ($this->usesRadius()) {
                    DB::table('radcheck')->whereIn('username', $guests->pluck('username'))->delete();
                    // The MAC login goes too, unless the phone has registered again since
                    $macs = $guests->pluck('mac')->filter()->unique();
                    $stillValid = HotspotGuest::query()->whereIn('mac', $macs)->whereNull('revoked_at')
                        ->where('expires_at', '>=', now())->pluck('mac')->all();
                    DB::table('radcheck')->whereIn('username', $macs->diff($stillValid)->values())->delete();

                    HotspotGuest::whereIn('id', $guests->pluck('id'))->update(['revoked_at' => now()]);
                    $removed += $guests->count();

                    return;
                }

                // One API connection per router; a guest is done once every router it used is cleaned.
                $byRouter = [];
                foreach ($guests as $g) {
                    foreach ($g->routerIdsWithLogin() as $id) {
                        $byRouter[$id][] = $g;
                    }
                }
                $failed = [];
                foreach ($byRouter as $routerId => $list) {
                    $router = MikrotikRouter::find($routerId);
                    if (! $router) {
                        continue; // router removed from the dashboard: nothing to clean
                    }
                    try {
                        $this->routerApi->removeGuestUsers($router, array_map(fn ($g) => $g->username, $list));
                    } catch (Throwable $e) {
                        report($e);
                        foreach ($list as $g) {
                            $failed[$g->id] = true; // router unreachable: try again next run
                        }
                    }
                }
                $done = $guests->reject(fn ($g) => isset($failed[$g->id]));
                HotspotGuest::whereIn('id', $done->pluck('id'))->update(['revoked_at' => now()]);
                $removed += $done->count();
            });

        return $removed;
    }

    /** FreeRADIUS "Expiration" format, in the RADIUS server's time zone (RADIUS_TIMEZONE). */
    private function radiusTime(Carbon $at): string
    {
        return $at->copy()->setTimezone((string) config('hotspot.radius.timezone'))->format('M d Y H:i:s');
    }

    private function uniqueUsername(): string
    {
        $prefix = (string) config('hotspot.username_prefix');

        for ($try = 0; $try < 10; $try++) {
            $candidate = $prefix.$this->random(8);
            $taken = HotspotGuest::where('username', $candidate)->exists()
                || ($this->usesRadius() && DB::table('radcheck')->where('username', $candidate)->exists());
            if (! $taken) {
                return $candidate;
            }
        }

        throw new RuntimeException('Could not generate a unique hotspot username.');
    }

    private function random(int $length): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }
}
