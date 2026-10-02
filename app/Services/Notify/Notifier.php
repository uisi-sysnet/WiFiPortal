<?php

namespace App\Services\Notify;

use App\Models\SystemEvent;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Sends new events to Telegram, once a minute (notify:send). Everything since the
 * last run goes in one message, so an outage of 200 access points is one message,
 * not 200. Events Telegram is not asked for, or too old to matter, are marked
 * handled without sending. If Telegram can't be reached the events wait for the
 * next run (up to STALE_HOURS).
 */
class Notifier
{
    /** Events older than this are not sent any more (e.g. after the server was down). */
    public const STALE_HOURS = 6;

    /** Events listed one by one; the rest are counted. */
    private const LISTED = 15;

    private const ICON = ['down' => '🔴', 'warn' => '🟠', 'ok' => '🟢', 'info' => '🔵'];

    public function __construct(private NotifySettings $settings, private Telegram $telegram)
    {
    }

    /** @return int events sent */
    public function dispatch(): int
    {
        $pending = SystemEvent::query()->where('notify', true)->whereNull('notified_at')->orderBy('id')->limit(1000)->get();
        if ($pending->isEmpty()) {
            return 0;
        }

        $cfg = $this->settings->telegram();
        $wanted = $pending->filter(fn (SystemEvent $e) => $this->settings->telegramReady()
            && $e->created_at->gt(now()->subHours(self::STALE_HOURS))
            && $this->wants($cfg['events'], $e));

        if ($wanted->isNotEmpty()) {
            try {
                $this->telegram->send($this->message($wanted));
            } catch (Throwable $e) {
                report($e);

                return 0; // try again next minute
            }
        }

        SystemEvent::whereIn('id', $pending->pluck('id'))->update(['notified_at' => now()]);

        return $wanted->count();
    }

    private function wants(array $events, SystemEvent $e): bool
    {
        if ($e->level === 'ok') {
            return in_array('ok', $events, true);
        }

        return in_array($e->kind, $events, true);
    }

    /** One message for everything new: worst first, then oldest first. */
    public function message(Collection $events): string
    {
        $rank = ['down' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3];
        $events = $events->sortBy([fn ($a, $b) => $rank[$a->level] <=> $rank[$b->level], fn ($a, $b) => $a->id <=> $b->id])->values();
        $tz = (string) config('hotspot.history.timezone');

        $counts = $events->groupBy('level')->map->count();
        $head = collect(['down' => 'down', 'warn' => 'warning', 'ok' => 'recovered', 'info' => 'info'])
            ->filter(fn ($w, $level) => $counts->has($level))
            ->map(fn ($w, $level) => self::ICON[$level].' '.$counts[$level].' '.$w)->join('   ');

        $lines = ['<b>Public WiFi Control</b>'.($events->count() > 1 ? "\n".$head : '')];
        $detailed = $events->count() <= 5;
        foreach ($events->take(self::LISTED) as $e) {
            $line = self::ICON[$e->level].' <b>'.e($e->title).'</b> <i>'.$e->created_at->copy()->setTimezone($tz)->format('H:i').'</i>';
            if ($detailed && $e->detail) {
                $line .= "\n".e(mb_strimwidth($e->detail, 0, 300, '…'));
            }
            $lines[] = $line;
        }
        if ($events->count() > self::LISTED) {
            $more = $events->slice(self::LISTED)->groupBy('kind')
                ->map(fn ($list, $kind) => $list->count().' '.strtolower(SystemEvent::KINDS[$kind] ?? $kind))->join(', ');
            $lines[] = '…and '.($events->count() - self::LISTED).' more ('.$more.').';
        }
        $lines[] = '<a href="'.e(rtrim((string) config('app.url'), '/').'/logs').'">Open the log</a>';

        return implode("\n\n", $lines);
    }
}
