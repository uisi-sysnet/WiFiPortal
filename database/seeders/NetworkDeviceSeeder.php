<?php

namespace Database\Seeders;

use App\Models\Barangay;
use App\Models\MikrotikRouter;
use App\Models\NetworkDevice;
use Illuminate\Database\Seeder;

class NetworkDeviceSeeder extends Seeder
{
    public function run(): void
    {
        // ------------------------------------------------------------------
        // Dependencies — bail out early if the parent records don't exist.
        // ------------------------------------------------------------------
        $barangays = Barangay::query()->pluck('id')->all();
        $routers   = MikrotikRouter::query()->pluck('id')->all();

        if (empty($barangays)) {
            $this->command->error('No barangays found. Seed Barangay first.');
            return;
        }

        // ------------------------------------------------------------------
        // Pools of realistic values.
        // ------------------------------------------------------------------
        $brandModels = [
            'Ubiquiti'  => ['UniFi AC Lite', 'UniFi AC Pro', 'UniFi 6 Lite', 'UniFi 6 Pro', 'NanoStation M5', 'PowerBeam AC'],
            'TP-Link'   => ['EAP225', 'EAP245', 'EAP610', 'EAP615-Wall', 'Archer C6', 'TL-SG108'],
            'MikroTik'  => ['hAP ac²', 'hAP ac³', 'cAP ac', 'cAP lite', 'RB260GS', 'CRS112-8G'],
            'Cisco'     => ['Aironet 1832i', 'Catalyst 2960', 'Catalyst 3560', 'Meraki MR36', 'SG350-10'],
            'Aruba'     => ['AP-505', 'AP-515', 'AP-635', 'Instant On 1930'],
            'Huawei'    => ['AP4050DN', 'AP5030DN', 'S5700-LI', 'S2720-EI'],
            'Ruijie'    => ['RG-AP820-L', 'RG-AP740-I', 'RG-NBS3100-8GT2SFP'],
            'Netgear'   => ['WAX610', 'WAX620', 'GS308E', 'GS110TP'],
        ];

        $barangaySuffixes = [
            'Poblacion', 'San Roque', 'Santa Cruz', 'San Isidro', 'Bagong Silang',
            'Malinao', 'Tagumpay', 'Mabini', 'Rizal', 'Bonifacio',
            'Del Pilar', 'Luna', 'Aguinaldo', 'Quezon', 'Osmeña',
        ];

        $locationHints = [
            'Barangay Hall', 'Covered Court', 'Health Center', 'Elementary School',
            'High School', 'Plaza', 'Public Market', 'Gymnasium', 'Day Care Center',
            'Multi-Purpose Hall', 'Transport Terminal', 'Sports Complex',
        ];

        $statuses = ['online', 'online', 'online', 'online', 'offline', 'offline', 'unknown'];

        // ------------------------------------------------------------------
        // Seed 100 devices.
        // ------------------------------------------------------------------
        for ($i = 1; $i <= 100; $i++) {
            $type     = $i % 5 === 0 ? 'switch' : 'ap';            // ~20% switches
            $brand    = array_rand($brandModels);
            $model    = $brandModels[$brand][array_rand($brandModels[$brand])];
            $status   = $statuses[array_rand($statuses)];

            $barangayId = $barangays[array_rand($barangays)];

            // ~70% of devices get coordinates around the Philippines.
            $hasCoords = $i % 10 !== 0;
            $latitude  = $hasCoords ? round(mt_rand(5000000, 19000000) / 1000000, 7) : null;
            $longitude = $hasCoords ? round(mt_rand(120000000, 126000000) / 1000000, 7) : null;

            // A router is required for SNMP; link most devices to one when available.
            $routerId = ! empty($routers) && $i % 7 !== 0
                ? $routers[array_rand($routers)]
                : null;

            // Older devices (every 9th) use SNMP v3, the rest use v2c/v1.
            $useV3 = $i % 9 === 0;

            $name = sprintf(
                '%s-%03d %s',
                $type === 'ap' ? 'AP' : 'SW',
                $i,
                $locationHints[array_rand($locationHints)]
            );

            NetworkDevice::query()->create([
                'type'              => $type,
                'name'              => $name,
                'brand'             => $brand,
                'model'             => $model,
                'mac_address'       => $this->randomMac(),
                'serial_number'     => strtoupper(bin2hex(random_bytes(4))),
                'firmware_version'  => sprintf('%d.%d.%d', mt_rand(1, 8), mt_rand(0, 12), mt_rand(0, 30)),

                'mikrotik_router_id' => $routerId,
                'uplink_device_id'   => null,
                'barangay_id'        => $barangayId,

                'location'   => $locationHints[array_rand($locationHints)].' '.$barangaySuffixes[array_rand($barangaySuffixes)],
                'latitude'   => $latitude,
                'longitude'  => $longitude,
                'deployed_at' => now()->subDays(mt_rand(10, 900))->toDateString(),
                'warranty'   => now()->addMonths(mt_rand(1, 36))->toDateString(),

                'host'       => '10.'.mt_rand(0, 20).'.'.mt_rand(0, 255).'.'.mt_rand(2, 254),
                'snmp_port'  => 161,
                'snmp_version' => $useV3 ? '3' : '2c',

                // v2c only
                'community' => $useV3 ? null : 'public',

                // v3 only
                'v3_username'        => $useV3 ? 'snmpuser'.mt_rand(1, 50) : null,
                'v3_security_level'  => $useV3 ? 'authPriv' : null,
                'v3_auth_protocol'   => $useV3 ? 'SHA' : null,
                'v3_auth_password'   => $useV3 ? 'AuthPass'.mt_rand(100, 999).'!' : null,
                'v3_priv_protocol'   => $useV3 ? 'AES' : null,
                'v3_priv_password'   => $useV3 ? 'PrivPass'.mt_rand(100, 999).'!' : null,

                // Live monitoring fields
                'status'          => $status,
                'uptime_seconds'  => $status === 'online' ? mt_rand(3600, 60 * 60 * 24 * 180) : null,
                'clients'         => $status === 'online' ? mt_rand(0, 60) : null,
                'utilization'     => $status === 'online' ? mt_rand(1, 95) : null,
                'failures'        => $status === 'offline' ? mt_rand(1, 12) : 0,
                'last_seen_at'    => $status === 'online' ? now()->subMinutes(mt_rand(1, 5)) : now()->subHours(mt_rand(1, 72)),
                'last_checked_at' => now()->subMinutes(mt_rand(1, 10)),
                'last_error'      => $status === 'offline'
                    ? collect(['Connection timed out', 'No SNMP response', 'Host unreachable', 'Authentication failure'])->random()
                    : null,
            ]);
        }

        $this->command->info('Seeded 100 network devices.');
    }

    /**
     * Generate a random, locally-administered unicast MAC address.
     */
    private function randomMac(): string
    {
        $bytes = [mt_rand(0, 255) & 0xFE | 0x02]; // unicast + locally administered
        for ($j = 0; $j < 5; $j++) {
            $bytes[] = mt_rand(0, 255);
        }

        return strtoupper(implode(':', array_map(fn ($b) => str_pad(dechex($b), 2, '0', STR_PAD_LEFT), $bytes)));
    }
}