<?php

namespace App\Services\Reports;

use App\Mail\NetworkReportMail;
use App\Models\Setting;
use App\Models\SystemEvent;
use App\Services\Notify\NotifySettings;
use App\Services\Notify\Telegram;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * The automatic report: every day by default, or every week or month, at the
 * time set in Settings (Asia/Manila). Emailed to the recipients with the Users
 * report PDF attached, and sent to Telegram when that is switched on.
 * reports:send checks every minute whether one is due.
 */
class ScheduledReport
{
    /** A report more than this late (server was off) is skipped, not sent hours later. */
    private const LATE_HOURS = 6;

    public const RANGE = ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month'];

    public function __construct(
        private NotifySettings $settings,
        private NetworkSummary $summary,
        private UsersReport $users,
        private Telegram $telegram,
    ) {
    }

    /** The most recent time a report was due (now or earlier), in the dashboard time zone. */
    public function lastSlot(?Carbon $now = null): Carbon
    {
        $r = $this->settings->report();
        $tz = (string) config('hotspot.history.timezone');
        $now = ($now ?? now())->copy()->setTimezone($tz);
        [$h, $m] = array_map('intval', explode(':', $r['time'] ?: '08:00') + [0, 0]);

        $slot = match ($r['frequency']) {
            'weekly' => $now->copy()->startOfWeek()->addDays(max(1, min(7, $r['weekday'])) - 1)->setTime($h, $m),
            'monthly' => $now->copy()->startOfMonth()->addDays(max(1, min(28, $r['monthday'])) - 1)->setTime($h, $m),
            default => $now->copy()->setTime($h, $m),
        };
        if ($slot->gt($now)) {
            $slot = match ($r['frequency']) {
                'weekly' => $slot->subWeek(),
                'monthly' => $slot->subMonthNoOverflow(),
                default => $slot->subDay(),
            };
        }

        return $slot;
    }

    /** When the next one goes out, for the Settings page. */
    public function nextSlot(): Carbon
    {
        $r = $this->settings->report();
        $last = $this->lastSlot();

        return match ($r['frequency']) {
            'weekly' => $last->copy()->addWeek(),
            'monthly' => $last->copy()->addMonthNoOverflow(),
            default => $last->copy()->addDay(),
        };
    }

    /** Sends the report if one is due and not sent yet. Returns true when sent. */
    public function runIfDue(): bool
    {
        $r = $this->settings->report();
        if (! $r['enabled'] || (! $r['recipients'] && ! $this->toTelegram())) {
            return false;
        }

        $slot = $this->lastSlot();
        $done = Setting::read('report.last_slot');
        if (($done && Carbon::parse($done)->gte($slot)) || $slot->lt(now()->subHours(self::LATE_HOURS))) {
            return false;
        }

        // Mark first: a slow or failing send must not repeat every minute
        $this->markSent($slot);
        try {
            $this->send();
        } catch (Throwable $e) {
            report($e);
            SystemEvent::log('warn', 'report', 'Scheduled report could not be sent', $e->getMessage());

            return false;
        }

        return true;
    }

    /** Remember the current slot as done (also when the schedule is saved, so it doesn't fire at once). */
    public function markSent(?Carbon $slot = null): void
    {
        Setting::write(['report.last_slot' => ($slot ?? $this->lastSlot())->copy()->utc()->toIso8601String()]);
    }

    /**
     * Builds and sends the report now: email (with the PDF) and Telegram.
     *
     * @param  array|null  $recipients  override (e.g. a test to one address)
     * @return array{emailed:int, telegram:int}
     */
    public function send(?array $recipients = null, bool $telegram = true): array
    {
        $cfg = $this->settings->report();
        $range = self::RANGE[$cfg['frequency']] ?? 'day';
        $recipients ??= $cfg['recipients'];

        $summary = $this->summary->build($range);
        $data = $this->users->data(['range' => $range], $cfg['aps'], 'the automatic '.($cfg['frequency'] ?? 'daily').' report');
        $pdf = $this->users->pdf($data)->output();
        $file = 'network-report-'.$range.'-'.now((string) config('hotspot.history.timezone'))->format('Y-m-d').'.pdf';

        $emailed = 0;
        $errors = [];
        if ($recipients) {
            try {
                $this->settings->applyMail();
                Mail::to($recipients)->send(new NetworkReportMail($summary, $pdf, $file, ucfirst($cfg['frequency'] ?? 'daily')));
                $emailed = count($recipients);
            } catch (Throwable $e) {
                $errors[] = 'Email: '.$this->explainMail($e);
            }
        }

        $sent = 0;
        if ($telegram && $this->toTelegram()) {
            try {
                $sent = $this->telegram->sendDocument($pdf, $file, $this->summary->telegramText($summary));
            } catch (Throwable $e) {
                $errors[] = 'Telegram: '.$e->getMessage();
            }
        }

        if (! $emailed && ! $sent) {
            throw new RuntimeException($errors ? implode(' ', $errors) : 'No recipients: add an email address or switch on Telegram reports.');
        }

        SystemEvent::log($errors ? 'warn' : 'info', 'report', ucfirst($summary['title']).' report sent'
            .($emailed ? ' to '.$emailed.' '.($emailed === 1 ? 'address' : 'addresses') : '')
            .($sent ? ($emailed ? ' and' : '').' to Telegram' : ''), $errors ? implode(' ', $errors) : null, notify: false);

        return ['emailed' => $emailed, 'telegram' => $sent];
    }

    public function toTelegram(): bool
    {
        return $this->settings->telegramReady() && in_array('report', $this->settings->telegram()['events'], true);
    }

    /** SMTP errors are long; keep the part an admin can act on. */
    public function explainMail(Throwable $e): string
    {
        $m = $e->getMessage();

        return match (true) {
            str_contains($m, 'Connection could not be established') || str_contains($m, 'Connection refused') || str_contains($m, 'timed out')
                => 'Could not reach the mail server. Check the SMTP host and port, and that this server may connect out on that port.',
            str_contains($m, '535') || stripos($m, 'authentication') !== false || stripos($m, 'username and password') !== false
                => 'The mail server rejected the username or password. For Gmail, use an app password.',
            stripos($m, 'certificate') !== false || stripos($m, 'ssl') !== false || stripos($m, 'tls') !== false
                => 'Secure connection failed. Use port 587 with TLS, or 465 with SSL.',
            default => mb_strimwidth($m, 0, 300, '…'),
        };
    }
}
