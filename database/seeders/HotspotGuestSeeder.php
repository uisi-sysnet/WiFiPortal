<?php

namespace Database\Seeders;

use App\Models\HotspotGuest;
use App\Models\HotspotNetwork;
use App\Models\MikrotikRouter;
use Illuminate\Database\Seeder;

class HotspotGuestSeeder extends Seeder
{
    public function run(): void
    {
        $routerId = MikrotikRouter::query()->value('id');
        $network = HotspotNetwork::query()->first();
        $now = now();

        for ($number = 1; $number <= 20; $number++) {
            $createdAt = $now->copy()->subHours($number * 7);
            $state = $number % 4;

            HotspotGuest::query()->updateOrCreate(
                ['username' => sprintf('demo-guest-%02d', $number)],
                [
                    'mikrotik_router_id' => $network?->mikrotik_router_id ?? $routerId,
                    'hotspot_network_id' => $network?->id,
                    'resident' => $number % 3 === 0,
                    'name' => sprintf('Demo Guest %02d', $number),
                    'contact' => sprintf('guest%02d@example.test', $number),
                    'contact_type' => 'email',
                    'citizen_number' => null,
                    'mac' => sprintf('02:00:00:00:%02X:%02X', intdiv($number, 256), $number),
                    'ip' => sprintf('192.0.2.%d', $number),
                    'terms_hash' => hash('sha256', 'demo-terms-v1'),
                    'connected_at' => $state === 2 ? null : $createdAt->copy()->addMinutes(2),
                    'login_method' => $state === 2 ? null : 'browser',
                    'expires_at' => match ($state) {
                        0 => $now->copy()->subDay(),
                        default => $now->copy()->addDays(7),
                    },
                    'revoked_at' => $state === 3 ? $now->copy()->subHour() : null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]
            );
        }

        $this->command?->info('Seeded 20 demo hotspot guests.');
    }
}
