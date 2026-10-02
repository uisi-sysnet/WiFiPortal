<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Barangay;
use App\Models\Setting;
use App\Models\User;
use App\Services\Notify\NotifySettings;
use App\Services\Notify\Telegram;
use App\Services\Portal\AccessValidity;
use App\Services\Reports\ScheduledReport;
use App\Services\Reports\TelegramPictureReport;
use Illuminate\Support\Facades\Mail;
use Throwable;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index(AccessValidity $validity, NotifySettings $notify, ScheduledReport $report, ?TelegramPictureReport $picture = null)
    {
        $picture ??= app(TelegramPictureReport::class);
        $telegram = $notify->telegram();
        $mail = $notify->mail();

        return view('settings.index', [
            'telegram' => ['token_set' => (bool) $telegram['token']] + $telegram,
            'mail' => ['password_set' => (bool) $mail['password']] + $mail,
            'report' => $notify->report(),
            'nextReport' => $report->nextSlot(),
            'reportToTelegram' => $report->toTelegram(),
            'picture' => $picture->config() + ['next' => $picture->nextSlot()],
            // Administrators first, then users, then viewers
            'accounts' => User::query()->orderByRaw("case role when 'admin' then 0 when 'user' then 1 else 2 end")->orderBy('name')->get(),
            'envMail' => ['mailer' => config('mail.default'), 'host' => config('mail.mailers.smtp.host'), 'from' => config('mail.from.address')],
            'validity' => collect(AccessValidity::CATEGORIES)->map(fn ($label, $key) => [
                'label' => $label,
                ...$validity->get($key),
            ])->all(),
            'barangays' => Barangay::query()->withCount('devices')->orderBy('name')->get(),
            'map' => [
                'latitude' => Setting::read('map.latitude'),
                'longitude' => Setting::read('map.longitude'),
                'zoom' => Setting::read('map.zoom'),
            ],
            'defaultZoom' => DashboardController::DEFAULT_ZOOM,
        ]);
    }

    /**
     * Where the dashboard map opens. All empty: the map frames the devices
     * automatically. Latitude and longitude go together; zoom needs a center.
     */
    public function updateMap(Request $request)
    {
        $data = $request->validateWithBag('map', [
            'latitude' => ['nullable', 'required_with:longitude,zoom', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude,zoom', 'numeric', 'between:-180,180'],
            'zoom' => ['nullable', 'integer', 'between:3,19'],
        ], [
            'latitude.required_with' => 'Enter the latitude too, or leave all three empty.',
            'longitude.required_with' => 'Enter the longitude too, or leave all three empty.',
            'zoom.between' => 'Zoom goes from 3 (a whole region) to 19 (a single building).',
        ]);

        $before = ['latitude' => Setting::read('map.latitude'), 'longitude' => Setting::read('map.longitude'), 'zoom' => Setting::read('map.zoom')];
        Setting::write([
            'map.latitude' => $data['latitude'] ?? null,
            'map.longitude' => $data['longitude'] ?? null,
            'map.zoom' => $data['zoom'] ?? null,
        ]);
        ActivityLog::settings('dashboard map', $before, ['latitude' => $data['latitude'] ?? null, 'longitude' => $data['longitude'] ?? null, 'zoom' => $data['zoom'] ?? null]);

        return redirect()->to(route('settings').'#map')->with('status', isset($data['latitude'])
            ? 'The dashboard map now opens on that spot.'
            : 'The dashboard map now frames your devices automatically.');
    }

    /**
     * How long each type of user stays online after registering. Applies to new
     * registrations; people already registered keep the end time they were given.
     */
    public function updateValidity(Request $request, AccessValidity $validity)
    {
        $rules = [];
        foreach (array_keys(AccessValidity::CATEGORIES) as $key) {
            $rules["{$key}_amount"] = ['required', 'integer', 'min:1', function ($attr, $value, $fail) use ($request, $key) {
                $hours = (int) $value * ($request->input("{$key}_unit") === 'days' ? 24 : 1);
                if ($hours > AccessValidity::MAX_HOURS) {
                    $fail('At most 365 days (8,760 hours).');
                }
            }];
            $rules["{$key}_unit"] = ['required', 'in:hours,days'];
        }
        $data = $request->validateWithBag('validity', $rules, [
            '*.required' => 'Enter a number.',
            '*.integer' => 'Whole numbers only.',
            '*.min' => 'At least 1.',
        ]);

        $snapshot = fn () => collect(AccessValidity::CATEGORIES)->mapWithKeys(fn ($label, $key) => [$label => $validity->label($key)])->all();
        $before = $snapshot();
        foreach (array_keys(AccessValidity::CATEGORIES) as $key) {
            $validity->set($key, (int) $data["{$key}_amount"], $data["{$key}_unit"]);
        }
        ActivityLog::settings('internet access time', $before, $snapshot());

        return redirect()->to(route('settings').'#validity')
            ->with('status', 'Access time saved. It applies to everyone who registers from now on.');
    }

    /* ---------- Telegram ---------- */

    public function updateTelegram(Request $request, NotifySettings $notify)
    {
        $request->merge(['chats' => $notify->list((string) $request->input('chats'))]);
        $data = $request->validateWithBag('telegram', [
            'enabled' => ['nullable', 'boolean'],
            'token' => ['nullable', 'string', 'max:100', 'regex:/^\d+:[A-Za-z0-9_\-]{30,}$/'],
            'chats' => ['array', 'max:10'],
            'chats.*' => ['regex:/^(-?\d{5,20}|@[A-Za-z][A-Za-z0-9_]{4,31})$/'],
            'events' => ['nullable', 'array'],
            'events.*' => ['in:'.implode(',', array_keys(NotifySettings::TELEGRAM_EVENTS))],
        ], [
            'token.regex' => 'That does not look like a bot token. It looks like 123456789:AAH... (from @BotFather).',
            'chats.*.regex' => 'A chat ID is a number like 123456789 or -1001234567890 (groups), or a channel like @mychannel.',
        ]);

        if ($request->boolean('enabled') && ! $data['chats']) {
            return back()->withErrors(['chats' => 'Add at least one chat ID, or use Find chats.'], 'telegram')->withInput();
        }
        if ($request->boolean('enabled') && empty($data['token']) && ! $notify->telegram()['token']) {
            return back()->withErrors(['token' => 'Enter the bot token.'], 'telegram')->withInput();
        }

        $before = $notify->telegram();
        $notify->saveTelegram($request->boolean('enabled'), $data['token'] ?? null, $data['chats'], $data['events'] ?? []);
        ActivityLog::settings('Telegram', $before, $notify->telegram());

        return redirect()->to(route('settings').'#telegram')->with('status', $request->boolean('enabled')
            ? 'Telegram alerts are on.' : 'Telegram settings saved. Alerts are off.');
    }

    /** AJAX: sends a test message with the token and chats in the form (or the saved ones). */
    public function testTelegram(Request $request, NotifySettings $notify, Telegram $telegram)
    {
        $chats = $notify->list((string) $request->input('chats')) ?: $notify->telegram()['chats'];
        try {
            $n = $telegram->send("✅ <b>Public WiFi Control</b>\nTest message from ".e($request->user()?->name ?? 'Settings').'. Telegram alerts will arrive in this chat.',
                $chats, $request->input('token') ?: null);
            ActivityLog::record('sent', 'Sent a Telegram test message to '.implode(', ', $chats), ['type' => 'settings', 'label' => 'Telegram']);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => "Sent to {$n} of ".count($chats).' chat'.(count($chats) === 1 ? '' : 's').'. Check Telegram.']);
    }

    /** AJAX: chats that wrote to the bot, to fill in the chat ID. */
    public function telegramChats(Request $request, NotifySettings $notify, Telegram $telegram)
    {
        $token = $request->input('token') ?: $notify->telegram()['token'];
        if (! $token) {
            return response()->json(['message' => 'Enter the bot token first.'], 422);
        }
        try {
            $chats = $telegram->recentChats($token);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['chats' => $chats, 'message' => $chats ? null
            : 'No chats yet. In Telegram, send /start to the bot (or add it to your group and send a message there), then try again.']);
    }

    /* ---------- Picture report to Telegram ---------- */

    public function updatePictureReport(Request $request, NotifySettings $notify, TelegramPictureReport $picture)
    {
        $data = $request->validateWithBag('picture', [
            'enabled' => ['nullable', 'boolean'],
            // AM: 00:00 to 11:59, PM: 12:00 to 23:59
            'am_time' => ['required', 'regex:/^(0\d|1[01]):[0-5]\d$/'],
            'pm_time' => ['required', 'regex:/^(1[2-9]|2[0-3]):[0-5]\d$/'],
            'am_on' => ['nullable', 'boolean'],
            'pm_on' => ['nullable', 'boolean'],
            'period' => ['required', 'in:'.implode(',', array_keys(TelegramPictureReport::PERIODS))],
        ], [
            'am_time.regex' => 'The AM report goes out between 00:00 and 11:59.',
            'pm_time.regex' => 'The PM report goes out between 12:00 and 23:59.',
        ]);

        if ($request->boolean('enabled') && ! $notify->telegramReady()) {
            return back()->withErrors(['enabled' => 'Switch on Telegram alerts above, with the bot token and a chat, first.'], 'picture')->withInput();
        }
        if ($request->boolean('enabled') && ! $request->boolean('am_on') && ! $request->boolean('pm_on')) {
            return back()->withErrors(['enabled' => 'Tick the AM report, the PM report, or both.'], 'picture')->withInput();
        }

        $before = array_diff_key($picture->config(), ['times' => 1]);
        $picture->save($request->boolean('enabled'), $data['am_time'], $data['pm_time'], $request->boolean('am_on'), $request->boolean('pm_on'), $data['period']);
        ActivityLog::settings('network status picture', $before, array_diff_key($picture->config(), ['times' => 1]));
        $c = $picture->config();

        return redirect()->to(route('settings').'#telegram')->with('status', $c['enabled']
            ? 'Status pictures on: '.implode(' and ', array_filter([$c['am_on'] ? 'AM at '.$c['am'] : null, $c['pm_on'] ? 'PM at '.$c['pm'] : null]))
                .' every day. Next one: '.$picture->nextSlot()->format('D, M j \a\t H:i').'.'
            : 'Status pictures off.');
    }

    /** The picture as it would be sent now, to check it before switching it on. */
    public function previewPictureReport(Request $request, TelegramPictureReport $picture)
    {
        $period = $request->validate(['period' => ['nullable', 'in:'.implode(',', array_keys(TelegramPictureReport::PERIODS))]])['period'] ?? null;

        $png = $picture->picture($period);
        ActivityLog::record('generated', 'Previewed the network status picture', ['type' => 'report', 'label' => 'Network status picture']);

        return response($png, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'no-store']);
    }

    public function sendPictureReport(TelegramPictureReport $picture)
    {
        try {
            $n = $picture->send();
            ActivityLog::record('sent', 'Sent the network status picture to Telegram now ('.$n.' '.($n === 1 ? 'chat' : 'chats').')', ['type' => 'report', 'label' => 'Network status picture']);
        } catch (Throwable $e) {
            return redirect()->to(route('settings').'#telegram')->with('error', 'The picture was not sent. '.$e->getMessage());
        }

        return redirect()->to(route('settings').'#telegram')->with('status', 'Picture report sent to '.$n.' Telegram '.($n === 1 ? 'chat' : 'chats').'.');
    }

    /* ---------- Email ---------- */

    public function updateMail(Request $request, NotifySettings $notify)
    {
        $data = $request->validateWithBag('mail', [
            'host' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'port' => ['nullable', 'required_with:host', 'integer', 'between:1,65535'],
            'encryption' => ['nullable', 'in:tls,ssl,none'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'from_address' => ['nullable', 'required_with:host', 'email'],
            'from_name' => ['nullable', 'string', 'max:80'],
        ], [
            'host.regex' => 'Enter the mail server name, like smtp.gmail.com.',
            'from_address.required_with' => 'Enter the address the reports come from.',
        ]);
        $before = $notify->mail();
        $notify->saveMail($data);
        ActivityLog::settings('email (mail server)', $before, $notify->mail());

        return redirect()->to(route('settings').'#mail')->with('status', ! empty($data['host'])
            ? 'Mail server saved. Send a test email to check it.'
            : 'Mail server cleared: the MAIL_ settings in .env are used.');
    }

    /** AJAX: one short email with the saved settings. */
    public function testMail(Request $request, NotifySettings $notify, ScheduledReport $report)
    {
        $to = $request->validate(['to' => ['required', 'email']])['to'];
        try {
            $notify->applyMail();
            Mail::raw("This is a test email from Public WiFi Control.\n\nIf you can read this, scheduled reports will reach this address.\n\nSystem developed by Uplink Integrated Solutions Inc. - System & Network Department",
                fn ($m) => $m->to($to)->subject('Test email | Public WiFi Control'));
            ActivityLog::record('sent', 'Sent a test email to '.$to, ['type' => 'settings', 'label' => 'Email']);
        } catch (Throwable $e) {
            return response()->json(['message' => $report->explainMail($e)], 422);
        }

        return response()->json(['message' => "Sent to {$to}. Check the inbox (and the spam folder)."]);
    }

    /* ---------- Automatic report ---------- */

    public function updateReport(Request $request, NotifySettings $notify, ScheduledReport $report)
    {
        $request->merge(['recipients' => $notify->list((string) $request->input('recipients'))]);
        $data = $request->validateWithBag('report', [
            'enabled' => ['nullable', 'boolean'],
            'recipients' => ['array', 'max:30'],
            'recipients.*' => ['email'],
            'frequency' => ['required', 'in:'.implode(',', array_keys(NotifySettings::FREQUENCIES))],
            'time' => ['required', 'date_format:H:i'],
            'weekday' => ['exclude_unless:frequency,weekly', 'required', 'integer', 'between:1,7'],
            'monthday' => ['exclude_unless:frequency,monthly', 'required', 'integer', 'between:1,28'],
            'aps' => ['nullable', 'boolean'],
        ], [
            'recipients.*.email' => ':input is not an email address.',
            'monthday.between' => 'Choose a day from 1 to 28 (every month has those).',
        ]);

        if ($request->boolean('enabled') && ! $data['recipients'] && ! $report->toTelegram()) {
            return back()->withErrors(['recipients' => 'Add at least one email address, or switch on reports in the Telegram section.'], 'report')->withInput();
        }

        $before = $notify->report();
        $notify->saveReport($data + ['enabled' => $request->boolean('enabled'), 'aps' => $request->boolean('aps')]);
        ActivityLog::settings('automatic report', $before, $notify->report());
        $report->markSent(); // count from the next time, not the one that just passed

        return redirect()->to(route('settings').'#report')->with('status', $request->boolean('enabled')
            ? 'Automatic report on. Next one: '.$report->nextSlot()->format('D, M j, Y \a\t g:i A').'.'
            : 'Automatic report off.');
    }

    public function sendReport(ScheduledReport $report)
    {
        try {
            $r = $report->send();
            ActivityLog::record('sent', 'Sent the network report now ('.$r['emailed'].' email, '.$r['telegram'].' Telegram)', ['type' => 'report', 'label' => 'Network report']);
        } catch (Throwable $e) {
            return redirect()->to(route('settings').'#report')->with('error', 'The report was not sent. '.$e->getMessage());
        }

        return redirect()->to(route('settings').'#report')->with('status', 'Report sent'
            .($r['emailed'] ? ' to '.$r['emailed'].' email '.($r['emailed'] === 1 ? 'address' : 'addresses') : '')
            .($r['telegram'] ? ($r['emailed'] ? ' and' : '').' to Telegram' : '').'.');
    }

    public function storeBarangay(Request $request)
    {
        $name = $this->validName($request, 'addBarangay');
        Barangay::create(['name' => $name]);

        return $this->back("{$name} added.");
    }

    public function updateBarangay(Request $request, Barangay $barangay)
    {
        $old = $barangay->name;
        $name = $this->validName($request, 'barangay'.$barangay->id, $barangay->id);
        $barangay->update(['name' => $name]);

        return $this->back($old === $name ? 'No change.' : "{$old} renamed to {$name}. Its devices moved with it.");
    }

    public function destroyBarangay(Barangay $barangay)
    {
        $inUse = $barangay->devices()->count();
        if ($inUse > 0) {
            return $this->back("{$barangay->name} still has {$inUse} ".str('device')->plural($inUse)
                .'. Move them to another barangay first.', 'error');
        }

        $barangay->delete();

        return $this->back("{$barangay->name} deleted.");
    }

    private function validName(Request $request, string $bag, ?int $ignoreId = null): string
    {
        $request->merge(['name' => Barangay::cleanName($request->input('name'))]);

        $request->validateWithBag($bag, [
            'name' => ['required', 'string', 'max:80', function ($attr, $value, $fail) use ($ignoreId) {
                if (Barangay::nameTaken($value, $ignoreId)) {
                    $fail("{$value} is already on the list.");
                }
            }],
        ], ['name.required' => 'Enter the barangay name.']);

        return $request->input('name');
    }

    private function back(string $message, string $key = 'status')
    {
        return redirect()->to(route('settings').'#barangays')->with($key, $message);
    }
}
