<?php

namespace App\Services\Portal;

use App\Models\MikrotikRouter;
use App\Services\Mikrotik\HotspotProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates the username/password the splash page hands to the router.
 *
 * The username is tied to the device MAC, so each phone has one account
 * that gets a fresh password on every registration (the table never grows
 * past the number of devices).
 *
 *   RADIUS configured -> row in radcheck, read by FreeRADIUS (scales to all sites)
 *   no RADIUS yet     -> local /ip hotspot user on that router, via the API
 */
class GuestCredentials
{
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
        $hex = strtolower(preg_replace('/[^0-9A-Fa-f]/', '', (string) $mac));
        $username = strlen($hex) === 12 ? 'mac-'.$hex : 'guest-'.Str::lower(Str::random(10));
        $password = Str::random(16);

        if ($this->usesRadius()) {
            DB::table('radcheck')->updateOrInsert(
                ['username' => $username, 'attribute' => 'Cleartext-Password'],
                ['op' => ':=', 'value' => $password]
            );
        } else {
            $this->routerApi->upsertGuestUser($router, $username, $password);
        }

        return [$username, $password];
    }
}
