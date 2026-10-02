<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Services\Mikrotik\HotspotProvisioner;
use App\Services\Mikrotik\SubnetAllocator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One hotspot on a router: its own VLAN, subnet, DHCP server and hotspot
 * server. A router can run several (e.g. "Public WiFi" and "School").
 *
 * The subnet is /16 to /24 and can be changed by the admin. max_users caps the
 * DHCP range, so no more devices than that can join (null = the whole subnet).
 *
 * Which pages phones see is chosen per network:
 *   login_page_id  design whose login page, Terms and form rules are used
 *   ad_page_id     design whose advertisement page (and Connect button) is used
 * Point every network at the same designs to share one portal, give each its
 * own, or share the login page and vary only the advertisement.
 */
class HotspotNetwork extends Model
{
    use LogsActivity;

    public function activityType(): string
    {
        return 'hotspot network';
    }

    /** Design names instead of ids in the activity log. */
    public function activityValue(string $field, mixed $v): mixed
    {
        return in_array($field, ['login_page_id', 'ad_page_id'], true) ? (SplashPage::find($v)?->name ?? "#{$v}") : $v;
    }

    public const LOGIN_MODES = [
        'portal' => 'Captive portal from this system',
        'custom' => 'Custom URL (external portal)',
        'builtin' => "Router's built-in page",
    ];

    /** Key of a router's first network. Keeps the RouterOS names used before networks existed. */
    public const PRIMARY_KEY = 'hotspot';

    protected $fillable = [
        'name', 'key', 'vlan_id', 'interface',
        'subnet', 'gateway', 'pool_start', 'pool_end', 'max_users',
        'login_mode', 'login_url', 'login_page_id', 'ad_page_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $network) {
            $network->portal_code ??= Str::lower(Str::random(12));
        });
    }

    protected function casts(): array
    {
        return ['vlan_id' => 'integer', 'max_users' => 'integer', 'leases' => 'integer'];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(MikrotikRouter::class, 'mikrotik_router_id');
    }

    public function loginPage(): BelongsTo
    {
        return $this->belongsTo(SplashPage::class, 'login_page_id');
    }

    public function adPage(): BelongsTo
    {
        return $this->belongsTo(SplashPage::class, 'ad_page_id');
    }

    /** The design for the login page, Terms and form rules. */
    public function loginDesign(): SplashPage
    {
        return $this->loginPage ?? SplashPage::current();
    }

    /** The design for the advertisement page and the page after connecting. */
    public function adDesign(): SplashPage
    {
        return $this->adPage ?? SplashPage::current();
    }

    public function isPrimary(): bool
    {
        return $this->key === self::PRIMARY_KEY;
    }

    /** Key for a new network: the first one on a router keeps the original names. */
    public static function keyFor(int $vlanId, bool $first): string
    {
        return $first ? self::PRIMARY_KEY : self::PRIMARY_KEY.'-'.$vlanId;
    }

    /* ---------- RouterOS object names ---------- */

    /** "" for the first network, "-11" for the one on VLAN 11. */
    private function suffix(): string
    {
        return (string) Str::after($this->key, self::PRIMARY_KEY);
    }

    public function serverName(): string
    {
        return HotspotProvisioner::SERVER.$this->suffix();
    }

    /** The DHCP server provisioning creates for this network. */
    public function dhcpServerName(): string
    {
        return 'dhcp-'.$this->key;
    }

    public function profileName(): string
    {
        return HotspotProvisioner::SERVER_PROFILE.$this->suffix();
    }

    /** Folder of this network's hotspot pages. Separate folders, so each login.html can point elsewhere. */
    public function htmlDirectory(): string
    {
        return $this->key;
    }

    /** Subnet, gateway and pool, in the same shape as MikrotikRouter::network(). */
    public function addressing(): array
    {
        return [
            'subnet' => $this->subnet,
            'gateway' => $this->gateway,
            'pool_start' => $this->pool_start,
            'pool_end' => $this->pool_end,
        ];
    }

    public function prefix(): int
    {
        return (int) explode('/', (string) $this->subnet)[1];
    }

    /** Devices the subnet can serve. */
    public function capacity(): int
    {
        return SubnetAllocator::capacity($this->prefix());
    }

    /** Addresses DHCP can hand out (the pool, cut to the user limit if one is set). */
    public function poolSize(): int
    {
        return max(1, (int) ip2long((string) $this->pool_end) - (int) ip2long((string) $this->pool_start) + 1);
    }

    /** DHCP addresses in use as a % of the pool (null when not known). */
    public function poolPercent(): ?float
    {
        return $this->leases === null ? null : round($this->leases / $this->poolSize() * 100, 1);
    }

    /** "No limit (up to 4,093)" or "1,000 of 4,093" */
    public function usersLabel(): string
    {
        return $this->max_users
            ? number_format($this->max_users).' of '.number_format($this->capacity())
            : 'No limit (up to '.number_format($this->capacity()).')';
    }

    /* ---------- Login page ---------- */

    public function usesExternalLogin(): bool
    {
        return in_array($this->login_mode, ['portal', 'custom'], true);
    }

    /** Where the router's login.html sends people. */
    public function loginTarget(): ?string
    {
        return match ($this->login_mode) {
            'custom' => $this->login_url,
            'portal' => $this->portalUrl(),
            default => null,
        };
    }

    public function portalUrl(): string
    {
        return rtrim((string) config('hotspot.portal_url'), '/').'/portal/'.$this->portal_code;
    }

    /** The redirecting login.html the router downloads during provisioning. */
    public function loginFileUrl(): string
    {
        return rtrim((string) config('hotspot.portal_url'), '/').'/hotspot-files/'.$this->portal_code.'/login.html';
    }

    public function loginModeLabel(): string
    {
        return self::LOGIN_MODES[$this->login_mode] ?? self::LOGIN_MODES['builtin'];
    }
}
