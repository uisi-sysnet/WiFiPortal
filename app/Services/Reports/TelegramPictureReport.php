<?php

namespace App\Services\Reports;

use App\Models\Setting;
use App\Models\SystemEvent;
use App\Models\MikrotikRouter;
use App\Services\Monitoring\Troubleshooter;
use App\Services\Notify\NotifySettings;
use App\Services\Notify\Telegram;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * The network status report as a picture, for troubleshooting, sent to the Telegram
 * chats twice a day, an AM and a PM report, at the times set in Settings
 * (default 08:00 and 20:00, Asia/Manila; either can be switched off):
 * system status (critical / warning / normal), devices online and offline, what to
 * check first with a first diagnosis, and every offline device with its location.
 * Outage counts cover the last 24 hours (or 7 days). telegram:report checks every minute.
 */
class TelegramPictureReport
{
    /** A send more than this late (server was off) is skipped. */
    private const LATE_HOURS = 3;

    public const DEFAULT_AM = '08:00';

    public const DEFAULT_PM = '20:00';

    public const PERIODS = ['day' => 'Last 24 hours', 'week' => 'Last 7 days'];

    public function __construct(
        private NotifySettings $settings,
        private Troubleshooter $troubleshooter,
        private ReportImage $image,
        private Telegram $telegram,
    ) {
    }

    /**
     * @return array{enabled:bool, am:string, pm:string, am_on:bool, pm_on:bool, times:array<int,string>, period:string}
     *   times: the AM and PM times that are switched on, in order
     */
    public function config(): array
    {
        $am = (string) Setting::read('tgreport.am', self::DEFAULT_AM);
        $pm = (string) Setting::read('tgreport.pm', self::DEFAULT_PM);
        $amOn = Setting::read('tgreport.am_on', '1') === '1';
        $pmOn = Setting::read('tgreport.pm_on', '1') === '1';

        return [
            'enabled' => (bool) Setting::read('tgreport.enabled') && ($amOn || $pmOn),
            'am' => $am, 'pm' => $pm, 'am_on' => $amOn, 'pm_on' => $pmOn,
            'times' => array_values(array_filter([$amOn ? $am : null, $pmOn ? $pm : null])),
            'period' => Setting::read('tgreport.period', 'day'),
        ];
    }

    public function save(bool $enabled, string $am, string $pm, bool $amOn, bool $pmOn, string $period): void
    {
        Setting::write([
            'tgreport.enabled' => $enabled ? '1' : null,
            'tgreport.am' => $am,
            'tgreport.pm' => $pm,
            'tgreport.am_on' => $amOn ? '1' : '0',
            'tgreport.pm_on' => $pmOn ? '1' : '0',
            'tgreport.period' => $period,
        ]);
        $this->markSent(); // count from the next time, not one that just passed
    }

    /** "AM report" before noon, "PM report" after. */
    public static function label(Carbon $at): string
    {
        return $at->hour < 12 ? 'AM report' : 'PM report';
    }

    /** The most recent scheduled time, now or earlier. */
    public function lastSlot(?Carbon $now = null): Carbon
    {
        $tz = (string) config('hotspot.history.timezone');
        $now = ($now ?? now())->copy()->setTimezone($tz);
        $slots = [];
        foreach ([$now->copy()->subDay(), $now] as $day) {
            foreach ($this->config()['times'] ?: [self::DEFAULT_AM] as $t) {
                [$h, $m] = array_map('intval', explode(':', $t));
                $slot = $day->copy()->setTime($h, $m);
                if ($slot->lte($now)) {
                    $slots[] = $slot;
                }
            }
        }

        return collect($slots)->sortByDesc(fn ($s) => $s->getTimestamp())->first();
    }

    public function nextSlot(): Carbon
    {
        $tz = (string) config('hotspot.history.timezone');
        $now = now($tz);
        foreach ([$now, $now->copy()->addDay()] as $day) {
            foreach ($this->config()['times'] ?: [self::DEFAULT_AM] as $t) {
                [$h, $m] = array_map('intval', explode(':', $t));
                $slot = $day->copy()->setTime($h, $m);
                if ($slot->gt($now)) {
                    return $slot;
                }
            }
        }

        return $now->copy()->addDay();
    }

    public function runIfDue(): bool
    {
        if (! $this->config()['enabled'] || ! $this->settings->telegramReady()) {
            return false;
        }
        $slot = $this->lastSlot();
        $done = Setting::read('tgreport.last_slot');
        if (($done && Carbon::parse($done)->gte($slot)) || $slot->lt(now()->subHours(self::LATE_HOURS))) {
            return false;
        }

        $this->markSent($slot);
        try {
            $this->send();
        } catch (Throwable $e) {
            report($e);
            SystemEvent::log('warn', 'report', 'Telegram picture report could not be sent', $e->getMessage(), notify: false);

            return false;
        }

        return true;
    }

    public function markSent(?Carbon $slot = null): void
    {
        Setting::write(['tgreport.last_slot' => ($slot ?? $this->lastSlot())->copy()->utc()->toIso8601String()]);
    }

    /** The picture itself (also shown by the Preview button). */
    public function picture(?string $period = null): string
    {
        return $this->image->png($this->troubleshooter->analyse(), $this->extra($period ?? $this->config()['period']));
    }

    /** Users online and outages in the period, for the status box. */
    private function extra(string $period): array
    {
        $from = $period === 'week' ? now()->subWeek() : now()->subDay();
        $events = SystemEvent::query()->where('created_at', '>=', $from)->whereIn('kind', ['router', 'ap', 'switch']);
        $online = MikrotikRouter::query()->where('link_status', 'online');

        return [
            'at' => now((string) config('hotspot.history.timezone')),
            'users' => (clone $online)->exists() ? (int) $online->sum('active_users') : null,
            'outages' => (clone $events)->where('level', 'down')->count(),
            'recoveries' => (clone $events)->where('level', 'ok')->count(),
            'period' => self::PERIODS[$period] ?? 'Last 24 hours',
            'label' => self::label(now((string) config('hotspot.history.timezone'))),
        ];
    }

    /** Telegram caption: the status and the first things to check (HTML, at most 1,000 characters). */
    public function caption(array $t): string
    {
        $icon = ['critical' => '🔴', 'warning' => '🟠', 'normal' => '🟢'][$t['status']['level']];
        $c = $t['counts'];
        $lines = [
            '<b>'.self::label(now((string) config('hotspot.history.timezone'))).'</b> · network status',
            $icon.' <b>'.strtoupper($t['status']['level']).'</b>: '.e(ucfirst($t['status']['reasons'][0] ?? '')),
            'Routers '.$c['router']['online'].'/'.$c['router']['total'].' · Switches '.$c['switch']['online'].'/'.$c['switch']['total']
                .' · APs '.$c['ap']['online'].'/'.$c['ap']['total'].' online',
        ];
        if ($t['roots']) {
            $lines[] = '<b>Check first:</b>';
            foreach (array_slice($t['roots'], 0, 5) as $i => $r) {
                $lines[] = ($i + 1).'. '.e($r['label'].' '.$r['name']).($r['where'] ? ' ('.e($r['where']).')' : '')
                    .(count($r['behind']) ? ' +'.count($r['behind']).' behind it' : '');
            }
            if (count($t['roots']) > 5) {
                $lines[] = '…and '.(count($t['roots']) - 5).' more in the picture.';
            }
        }

        return mb_substr(implode("\n", $lines), 0, 1000);
    }

    /** @return int chats that received it */
    public function send(): int
    {
        if (! $this->settings->telegramReady()) {
            throw new RuntimeException('Telegram is off or not set up: switch it on and add the bot token and a chat first.');
        }
        $period = $this->config()['period'];
        $t = $this->troubleshooter->analyse();
        $png = $this->image->png($t, $this->extra($period));
        $name = 'network-status-'.now((string) config('hotspot.history.timezone'))->format('Y-m-d-Hi').'.png';

        $n = $this->telegram->sendPhoto($png, $name, $this->caption($t));
        SystemEvent::log('info', 'report', self::label(now((string) config('hotspot.history.timezone'))).' sent to Telegram: '.$t['status']['level']
            .($t['offline'] ? ', '.count($t['offline']).' offline' : ''), notify: false);

        return $n;
    }
}
