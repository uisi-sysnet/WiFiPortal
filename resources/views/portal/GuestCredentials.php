<?php

namespace App\Services\Portal;

use App\Models\HotspotGuest;
use App\Models\MikrotikRouter;
use App\Services\Mikrotik\HotspotProvisioner;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Generates a unique username and password for every registration.
 *
 *   username  prefix + 8 characters, e.g. "wifi-k7m2p9qx" (unique across all guests)
 *   password  12 random characters, never shown to the user
 *
 * Both are locked to the phone's MAC address, so they only work on that device:
 *   RADIUS configured -> rows in radcheck (Cleartext-Password + Calling-Station-Id)
 *   no RADIUS yet     -> local /ip hotspot user on that router with its mac-address set
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
    public function issue(MikrotikRouter $router, ?string $mac): array
    {
        $username = $this->uniqueUsername();
        $password = $this->random(12);

        if ($this->usesRadius()) {
            DB::table('radcheck')->insert(array_filter([
                ['username' => $username, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => $password],
                // MikroTik sends the phone's MAC as Calling-Station-Id; "==" makes it a requirement.
                $mac ? ['username' => $username, 'attribute' => 'Calling-Station-Id', 'op' => '==', 'value' => $mac] : null,
            ]));
        } else {
            $this->routerApi->upsertGuestUser($router, $username, $password, $mac);
        }

        return [$username, $password];
    }

    /** Deletes the credentials of expired registrations. Returns how many were removed. */
    public function expire(): int
    {
        $removed = 0;

        HotspotGuest::query()
            ->whereNull('revoked_at')
            ->where('expires_at', '<', now())
            ->with('router')
            ->chunkById(200, function ($guests) use (&$removed) {
                if ($this->usesRadius()) {
                    DB::table('radcheck')->whereIn('username', $guests->pluck('username'))->delete();
                } else {
                    // One API connection per router for the whole batch.
                    foreach ($guests->groupBy('mikrotik_router_id') as $list) {
                        $router = $list->first()->router;
                        if ($router) {
                            try {
                                $this->routerApi->removeGuestUsers($router, $list->pluck('username')->all());
                            } catch (\Throwable $e) {
                                report($e);
                                continue; // router unreachable: try again next run
                            }
                        }
                        HotspotGuest::whereIn('id', $list->pluck('id'))->update(['revoked_at' => now()]);
                        $removed += $list->count();
                    }

                    return;
                }

                HotspotGuest::whereIn('id', $guests->pluck('id'))->update(['revoked_at' => now()]);
                $removed += $guests->count();
            });

        return $removed;
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