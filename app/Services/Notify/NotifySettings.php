<?php

namespace App\Services\Notify;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Telegram, email (SMTP) and scheduled-report settings from the Settings page.
 * Secrets (bot token, SMTP password) are stored encrypted with APP_KEY.
 */
class NotifySettings
{
    /** What can be sent to Telegram, as the Settings page lists them. */
    public const TELEGRAM_EVENTS = [
        'router' => 'Router stops answering',
        'ap' => 'Access point goes offline',
        'switch' => 'Switch goes offline',
        'capacity' => 'Router or network busy / full',
        'ok' => 'Back online and back to normal',
        'report' => 'The emailed report, also here (summary and PDF)',
    ];

    public const FREQUENCIES = ['daily' => 'Every day', 'weekly' => 'Every week', 'monthly' => 'Every month'];

    public const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /* ---------- Telegram ---------- */

    public function telegram(): array
    {
        return [
            'enabled' => (bool) Setting::read('telegram.enabled'),
            'token' => $this->secret('telegram.token'),
            'chats' => $this->list((string) Setting::read('telegram.chats', '')),
            'events' => $this->list((string) Setting::read('telegram.events', 'router,ap,switch,capacity,ok,report')),
        ];
    }

    public function telegramReady(): bool
    {
        $t = $this->telegram();

        return $t['enabled'] && $t['token'] && $t['chats'];
    }

    public function saveTelegram(bool $enabled, ?string $token, array $chats, array $events): void
    {
        Setting::write([
            'telegram.enabled' => $enabled ? '1' : null,
            'telegram.chats' => implode(',', $chats),
            'telegram.events' => implode(',', $events) ?: 'none',
        ]);
        if ($token !== null && $token !== '') {
            Setting::write(['telegram.token' => Crypt::encryptString($token)]);
        }
    }

    /* ---------- Email (SMTP) ---------- */

    public function mail(): array
    {
        return [
            'host' => Setting::read('mail.host'),
            'port' => (int) Setting::read('mail.port', 587),
            'encryption' => Setting::read('mail.encryption', 'tls'),
            'username' => Setting::read('mail.username'),
            'password' => $this->secret('mail.password'),
            'from_address' => Setting::read('mail.from_address'),
            'from_name' => Setting::read('mail.from_name', 'Public WiFi Control'),
        ];
    }

    public function saveMail(array $m): void
    {
        Setting::write([
            'mail.host' => $m['host'] ?? null,
            'mail.port' => $m['port'] ?? null,
            'mail.encryption' => $m['encryption'] ?? null,
            'mail.username' => $m['username'] ?? null,
            'mail.from_address' => $m['from_address'] ?? null,
            'mail.from_name' => $m['from_name'] ?? null,
        ]);
        if (! empty($m['password'])) {
            Setting::write(['mail.password' => Crypt::encryptString($m['password'])]);
        }
        if (empty($m['host'])) {
            Setting::write(['mail.password' => null]);
        }
    }

    /**
     * Points Laravel's mailer at the SMTP server from Settings. Without one, the
     * MAIL_* settings in .env are used as they are.
     */
    public function applyMail(): void
    {
        $m = $this->mail();
        if (! $m['host']) {
            return;
        }
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'scheme' => $m['encryption'] === 'ssl' ? 'smtps' : 'smtp', // smtp upgrades to TLS when the server offers it
                'host' => $m['host'],
                'port' => $m['port'] ?: 587,
                'username' => $m['username'],
                'password' => $m['password'],
                'timeout' => 20,
                'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST),
            ],
            'mail.from' => ['address' => $m['from_address'] ?: $m['username'], 'name' => $m['from_name'] ?: 'Public WiFi Control'],
        ]);
        app('mail.manager')->forgetMailers();
    }

    /* ---------- Scheduled report ---------- */

    public function report(): array
    {
        return [
            'enabled' => (bool) Setting::read('report.enabled'),
            'recipients' => $this->list((string) Setting::read('report.recipients', '')),
            'frequency' => Setting::read('report.frequency', 'daily'),
            'time' => Setting::read('report.time', '08:00'),
            'weekday' => (int) Setting::read('report.weekday', 1),
            'monthday' => (int) Setting::read('report.monthday', 1),
            'aps' => (bool) Setting::read('report.aps', '1'),
        ];
    }

    public function saveReport(array $r): void
    {
        Setting::write([
            'report.enabled' => ! empty($r['enabled']) ? '1' : null,
            'report.recipients' => implode(',', $r['recipients']),
            'report.frequency' => $r['frequency'],
            'report.time' => $r['time'],
            'report.weekday' => $r['weekday'] ?? 1,
            'report.monthday' => $r['monthday'] ?? 1,
            'report.aps' => ! empty($r['aps']) ? '1' : '0',
        ]);
    }

    /** Comma, semicolon, space or newline separated -> unique non-empty items. */
    public function list(string $value): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', $value)))));
    }

    private function secret(string $key): ?string
    {
        $value = Setting::read($key);
        if (! $value) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return null; // APP_KEY changed: enter it again
        }
    }
}
