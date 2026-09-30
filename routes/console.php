<?php

use App\Jobs\PollNetworkDevices;
use App\Models\NetworkDevice;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// php artisan wifi:make-admin admin@example.com --name="Network Admin"
Artisan::command('wifi:make-admin {email} {--name=Administrator}', function (string $email) {
    $password = $this->secret('Password (min 12 characters)');

    if (strlen((string) $password) < 12) {
        $this->error('Password must be at least 12 characters.');

        return 1;
    }

    User::updateOrCreate(
        ['email' => $email],
        ['name' => $this->option('name'), 'password' => $password] // hashed by the User model cast
    );

    $this->info("Admin {$email} is ready. Sign in at /login.");
})->purpose('Create or reset a dashboard administrator');

// php artisan devices:poll  (runs every minute through the scheduler)
Artisan::command('devices:poll', function () {
    $batches = 0;
    NetworkDevice::query()->select('id')->chunkById(config('devices.poll_batch'), function ($chunk) use (&$batches) {
        PollNetworkDevices::dispatch($chunk->pluck('id')->all());
        $batches++;
    });
    $this->info("Queued {$batches} batch(es) of SNMP checks.");
})->purpose('Check every access point and switch over SNMP');

Schedule::command('devices:poll')->everyMinute()->withoutOverlapping();

// php artisan hotspot:expire-credentials  (runs every 15 minutes through the scheduler)
Artisan::command('hotspot:expire-credentials', function (\App\Services\Portal\GuestCredentials $credentials) {
    $this->info('Removed '.$credentials->expire().' expired hotspot login(s).');
})->purpose('Delete hotspot usernames and passwords whose time is up');

Schedule::command('hotspot:expire-credentials')->everyFifteenMinutes()->withoutOverlapping();
