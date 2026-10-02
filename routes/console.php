<?php

use App\Jobs\PollNetworkDevices;
use App\Jobs\PollRouters;
use App\Models\MikrotikRouter;
use App\Models\NetworkDevice;
use App\Models\User;
use App\Services\Dashboard\UserHistory;
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
        ['name' => $this->option('name'), 'password' => $password, 'role' => 'admin'] // hashed by the User model cast
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

// php artisan routers:poll  (every 30 seconds through the scheduler; ROUTER_POLL_SECONDS)
Artisan::command('routers:poll', function () {
    $batches = 0;
    MikrotikRouter::query()->select('id')->chunkById(max(1, (int) config('hotspot.poll.batch')), function ($chunk) use (&$batches) {
        PollRouters::dispatch($chunk->pluck('id')->all());
        $batches++;
    });
    $this->info("Queued {$batches} batch(es) of router checks.");
})->purpose('Check which routers answer and count hotspot users online');

$routerPoll = Schedule::command('routers:poll')->withoutOverlapping();
match ((int) config('hotspot.poll.seconds')) {
    15 => $routerPoll->everyFifteenSeconds(),
    60 => $routerPoll->everyMinute(),
    default => $routerPoll->everyThirtySeconds(),
};

// php artisan users:snapshot  (every 5 minutes: history for the "Users online" chart)
Artisan::command('users:snapshot', function (UserHistory $history) {
    $this->info($history->snapshot()
        ? 'Saved users online.'
        : 'Skipped: no router poll in the last 3 minutes (is routers:poll running?).');
})->purpose('Save the total users online for the dashboard chart');

Schedule::command('users:snapshot')->everyFiveMinutes();

// php artisan hotspot:expire-credentials  (runs every 15 minutes through the scheduler)
Artisan::command('hotspot:expire-credentials', function (\App\Services\Portal\GuestCredentials $credentials) {
    $this->info('Removed '.$credentials->expire().' expired hotspot login(s).');
})->purpose('Delete hotspot usernames and passwords whose time is up');

Schedule::command('hotspot:expire-credentials')->everyFifteenMinutes()->withoutOverlapping();

// php artisan devices:prune-client-stats  (daily: hourly client logs older than AP_CLIENT_STATS_DAYS)
Artisan::command('devices:prune-client-stats', function (\App\Services\Dashboard\ApClientReport $report) {
    $this->info('Deleted '.$report->prune().' old hourly client row(s).');
})->purpose('Delete hourly access point client logs past their keep time');

Schedule::command('devices:prune-client-stats')->dailyAt('03:20');

// php artisan notify:send  (every minute: new events to Telegram, in one message)
Artisan::command('notify:send', function (\App\Services\Notify\Notifier $notifier) {
    $this->info('Sent '.$notifier->dispatch().' event(s) to Telegram.');
})->purpose('Send new network events to Telegram');

Schedule::command('notify:send')->everyMinute()->withoutOverlapping();

// php artisan reports:send  (every minute: sends the scheduled report when it is due)
// php artisan reports:send --now  (send it right away)
Artisan::command('reports:send {--now}', function (\App\Services\Reports\ScheduledReport $report) {
    if ($this->option('now')) {
        $r = $report->send();
        $this->info("Report sent to {$r['emailed']} address(es) and {$r['telegram']} Telegram chat(s).");

        return;
    }
    $this->info($report->runIfDue() ? 'Scheduled report sent.' : 'No report due.');
})->purpose('Send the scheduled network report by email and Telegram');

Schedule::command('reports:send')->everyMinute()->withoutOverlapping();

// php artisan telegram:report  (every minute: the picture report at its times of day)
// php artisan telegram:report --now
Artisan::command('telegram:report {--now}', function (\App\Services\Reports\TelegramPictureReport $report) {
    if ($this->option('now')) {
        $this->info('Picture report sent to '.$report->send().' Telegram chat(s).');

        return;
    }
    $this->info($report->runIfDue() ? 'Picture report sent.' : 'No picture report due.');
})->purpose('Send the full system report as a picture to Telegram');

Schedule::command('telegram:report')->everyMinute()->withoutOverlapping();

// php artisan radius:prune  (daily: old login attempts and finished sessions)
Artisan::command('radius:prune', function (\App\Services\Radius\RadiusLog $radius) {
    [$auth, $acct] = $radius->prune();
    $this->info("Deleted {$auth} login attempt(s) and {$acct} session(s).");
})->purpose('Delete old RADIUS login attempts and sessions');

Schedule::command('radius:prune')->dailyAt('03:40');

// php artisan activity:prune  (daily: user activity older than ACTIVITY_LOG_DAYS, default 3 years)
Artisan::command('activity:prune', function () {
    $days = (int) config('hotspot.activity_log_days');
    $n = \App\Models\ActivityLog::query()->where('created_at', '<', now()->subDays($days))->delete();
    $this->info("Deleted {$n} activity log entr".($n === 1 ? 'y' : 'ies')." older than {$days} days.");
})->purpose('Delete user activity log entries past their keep time');

Schedule::command('activity:prune')->dailyAt('03:50');
