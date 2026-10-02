{{--
  Settings: Telegram alerts, mail server (SMTP) and the automatic report.
  Needs: $telegram, $mail, $report, $nextReport, $reportToTelegram, $envMail.
--}}
@php
  $tErr = $errors->getBag('telegram');
  $mErr = $errors->getBag('mail');
  $rErr = $errors->getBag('report');
  // Lists (chats, recipients) come back from a failed save as arrays: show them as text again
  $back = fn ($v) => is_array($v) ? implode(', ', $v) : $v;
  $tOld = fn ($f, $d) => $tErr->any() ? $back(old($f, $d)) : $d;
  $mOld = fn ($f, $d) => $mErr->any() ? old($f, $d) : $d;
  $rOld = fn ($f, $d) => $rErr->any() ? $back(old($f, $d)) : $d;
  $events = $tErr->any() ? (array) old('events', []) : $telegram['events'];
  $events = array_map('strval', $events);
  $freq = $rOld('frequency', $report['frequency']);
  $pErr = $errors->getBag('picture');
  $pOld = fn ($f, $d) => $pErr->any() ? $back(old($f, $d)) : $d;
@endphp
<style>
.nt-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px 24px}
.nt-grid .full{grid-column:1/-1}
.nt-grid .field{margin:0}
.nt-checks{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:6px 18px;margin:4px 0 0;padding:0;border:0}
.nt-checks legend{font-weight:600;margin-bottom:6px}
.nt-check{display:flex;gap:8px;align-items:center;font-weight:400}
.nt-switch{display:flex;gap:10px;align-items:center;font-weight:600;margin:0 0 14px}
.nt-switch input{width:18px;height:18px}
.nt-msg{margin:0;font-size:.86rem}
.nt-msg.ok{color:#0e670d}.nt-msg.bad{color:#B3372E}
.nt-chats{list-style:none;margin:6px 0 0;padding:0;display:grid;gap:4px}
.nt-chats button{font:inherit;font-size:.82rem;text-align:left;background:#F3F8F4;border:1px solid #C9D6CE;border-radius:6px;padding:5px 9px;cursor:pointer}
.nt-steps{margin:0 0 14px;padding-left:20px;font-size:.88rem;color:#5c6b66;max-width:1200px}
.nt-next{font-size:.88rem;margin:0 0 14px;padding:8px 12px;background:#F3F8F4;border-left:3px solid #0e670d;max-width:1200px}
.nt-inline{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.nt-sub{margin-top:28px;padding-top:20px;border-top:1px solid #C9D6CE}
.nt-sub h3{margin:0 0 6px;font-size:1.05rem}
.nt-inline{flex-wrap:nowrap}
.settings-section .nt-inline input:not([type=checkbox]),.settings-section .nt-inline select{width:auto}
.settings-section .nt-inline #mail-test-to{width:240px}
@media (max-width:700px){.nt-grid,.nt-checks{grid-template-columns:1fr}}
</style>

{{-- ---------- Telegram ---------- --}}
<section class="settings-section" id="telegram" aria-labelledby="tg-title">
  <h2 id="tg-title">Telegram alerts</h2>
  <p class="hint" style="margin:0 0 10px">Get a message when a router, access point or switch goes down or comes back, and when a router or network gets busy. Everything new in the same minute comes as one message.</p>
  <ol class="nt-steps">
    <li>In Telegram, open <b>@BotFather</b>, send <span class="mono">/newbot</span>, and copy the token it gives you.</li>
    <li>Send <span class="mono">/start</span> to your new bot, or add it to your team's group and send any message there.</li>
    <li>Paste the token below and press <b>Find chats</b>, then pick the chat.</li>
  </ol>

  <form method="POST" action="{{ route('settings.telegram') }}" id="tg-form" novalidate>
    @csrf @method('PUT')
    <label class="nt-switch"><input type="checkbox" name="enabled" value="1" @checked($tOld('enabled', $telegram['enabled']))> Send alerts to Telegram</label>
    <div class="nt-grid">
      <div class="field">
        <label for="tg-token">Bot token</label>
        <input id="tg-token" name="token" type="password" class="mono" autocomplete="off" maxlength="100"
               placeholder="{{ $telegram['token_set'] ? 'Saved. Leave blank to keep it.' : '123456789:AAH...' }}"
               @if($tErr->has('token')) aria-invalid="true" aria-describedby="tg-token-error" @endif>
        @if ($tErr->has('token'))<p class="error" id="tg-token-error">{{ $tErr->first('token') }}</p>@endif
        <p class="hint">Stored encrypted.</p>
      </div>
      <div class="field">
        <label for="tg-chats">Chat IDs</label>
        <input id="tg-chats" name="chats" type="text" class="mono" maxlength="300"
               value="{{ $tOld('chats', implode(', ', $telegram['chats'])) }}" placeholder="-1001234567890"
               @if($tErr->has('chats') || $tErr->has('chats.*')) aria-invalid="true" @endif>
        @foreach (['chats', 'chats.0', 'chats.1', 'chats.2'] as $f)
          @if ($tErr->has($f))<p class="error">{{ $tErr->first($f) }}</p>@endif
        @endforeach
        <p class="hint">Separate several with commas. Groups start with a minus sign.</p>
        <ul class="nt-chats" id="tg-found" hidden></ul>
      </div>
      <fieldset class="nt-checks full">
        <legend>Send a message when</legend>
        @foreach (\App\Services\Notify\NotifySettings::TELEGRAM_EVENTS as $key => $label)
          <label class="nt-check"><input type="checkbox" name="events[]" value="{{ $key }}" @checked(in_array($key, $events, true))> {{ $label }}</label>
        @endforeach
      </fieldset>
    </div>
    <div class="actions" style="margin-top:14px">
      <button class="btn" type="submit">Save Telegram</button>
      <button class="btn quiet" type="button" id="tg-find">Find chats</button>
      <button class="btn quiet" type="button" id="tg-test">Send test message</button>
      <p class="nt-msg" id="tg-msg" role="status" aria-live="polite"></p>
    </div>
  </form>

  {{-- Full report as a picture, at set times of day --}}
  <div class="nt-sub" id="picture">
    <h3>Network status picture (troubleshooting)</h3>
    <p class="hint" style="margin:0 0 12px">Two pictures a day in the Telegram chats above, an <b>AM report</b> and a <b>PM report</b>, at the times you set ({{ config('hotspot.history.timezone') }}): the system status (critical, warning or normal), routers, switches and access points online and offline,
      <b>what to check first</b> with a first diagnosis from "Connected to" (e.g. an access point is offline but its switch is online: the access point itself is the problem), and every offline device with its barangay and location.</p>
    @if ($picture['enabled'])
      <p class="nt-next">Next: <b>{{ \App\Services\Reports\TelegramPictureReport::label($picture['next']) }}, {{ $picture['next']->format('l, F j \a\t H:i') }}</b>.
        Every day: {{ implode(' and ', array_filter([$picture['am_on'] ? 'AM at '.$picture['am'] : null, $picture['pm_on'] ? 'PM at '.$picture['pm'] : null])) }}.</p>
    @endif
    <form method="POST" action="{{ route('settings.picture') }}" novalidate>
      @csrf @method('PUT')
      <label class="nt-switch"><input type="checkbox" name="enabled" value="1" @checked($pOld('enabled', $picture['enabled']))> Send the status picture to Telegram</label>
      @if ($pErr->has('enabled'))<p class="error" style="margin:-8px 0 12px">{{ $pErr->first('enabled') }}</p>@endif
      <div class="nt-grid">
        @foreach (['am' => ['AM report', 'Before noon: 00:00 to 11:59, e.g. 08:00 before the day starts.', '00:00', '11:59'],
                   'pm' => ['PM report', 'Noon or later: 12:00 to 23:59, e.g. 20:00 during the evening peak.', '12:00', '23:59']] as $k => [$title, $help, $min, $max])
          <div class="field">
            <label class="nt-check" style="font-weight:600"><input type="checkbox" name="{{ $k }}_on" value="1" @checked($pOld($k.'_on', $picture[$k.'_on']))> {{ $title }} at</label>
            <input id="pic-{{ $k }}" name="{{ $k }}_time" type="time" min="{{ $min }}" max="{{ $max }}" step="60" value="{{ $pOld($k.'_time', $picture[$k]) }}"
                   aria-label="{{ $title }} time" @if($pErr->has($k.'_time')) aria-invalid="true" @endif>
            @if ($pErr->has($k.'_time'))<p class="error">{{ $pErr->first($k.'_time') }}</p>@endif
            <p class="hint">{{ $help }}</p>
          </div>
        @endforeach
        <div class="field">
          <label for="pic-period">Count outages over</label>
          <select id="pic-period" name="period">
            @foreach (\App\Services\Reports\TelegramPictureReport::PERIODS as $v => $l)<option value="{{ $v }}" @selected($pOld('period', $picture['period']) === $v)>{{ $l }}</option>@endforeach
          </select>
          <p class="hint">What is offline is always as of the moment it is sent.</p>
        </div>
      </div>
      <div class="actions" style="margin-top:4px">
        <button class="btn" type="submit">Save picture schedule</button>
        <a class="btn quiet" href="{{ route('settings.picture.preview') }}" target="_blank" rel="noopener">Preview picture</a>
      </div>
    </form>
    <form method="POST" action="{{ route('settings.picture.send') }}" style="margin-top:10px" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Sending...'">
      @csrf
      <button class="btn quiet" type="submit">Send the picture now</button>
      <span class="hint">To the chats saved above.</span>
    </form>
  </div>
</section>

{{-- ---------- Mail server ---------- --}}
<section class="settings-section" id="mail" aria-labelledby="mail-title">
  <h2 id="mail-title">Email (mail server)</h2>
  <p class="hint" style="margin:0 0 14px">The server that sends the reports. For Gmail: smtp.gmail.com, port 587, TLS, your address, and an <b>app password</b> (Google Account &gt; Security &gt; App passwords).
    @if (! $mail['host']) Empty now: the MAIL_ settings in .env are used ({{ $envMail['mailer'] === 'smtp' ? $envMail['host'] : 'mailer "'.$envMail['mailer'].'", which does not send real email' }}). @endif</p>

  <form method="POST" action="{{ route('settings.mail') }}" novalidate>
    @csrf @method('PUT')
    <div class="nt-grid">
      <div class="field">
        <label for="mail-host">SMTP server</label>
        <input id="mail-host" name="host" type="text" class="mono" maxlength="255" value="{{ $mOld('host', $mail['host']) }}" placeholder="smtp.gmail.com"
               @if($mErr->has('host')) aria-invalid="true" @endif>
        @if ($mErr->has('host'))<p class="error">{{ $mErr->first('host') }}</p>@endif
      </div>
      <div class="field">
        <label for="mail-port">Port and security</label>
        <div class="nt-inline">
          <input id="mail-port" name="port" type="number" min="1" max="65535" style="max-width:110px" value="{{ $mOld('port', $mail['host'] ? $mail['port'] : 587) }}">
          <label class="sr-only" for="mail-enc">Security</label>
          <select id="mail-enc" name="encryption">
            @foreach (['tls' => 'TLS (port 587)', 'ssl' => 'SSL (port 465)', 'none' => 'None'] as $v => $l)
              <option value="{{ $v }}" @selected($mOld('encryption', $mail['encryption']) === $v)>{{ $l }}</option>
            @endforeach
          </select>
        </div>
        @if ($mErr->has('port'))<p class="error">{{ $mErr->first('port') }}</p>@endif
      </div>
      <div class="field">
        <label for="mail-user">Username</label>
        <input id="mail-user" name="username" type="text" autocomplete="off" maxlength="255" value="{{ $mOld('username', $mail['username']) }}" placeholder="reports@example.com">
      </div>
      <div class="field">
        <label for="mail-pass">Password</label>
        <input id="mail-pass" name="password" type="password" autocomplete="new-password" maxlength="255"
               placeholder="{{ $mail['password_set'] ? 'Saved. Leave blank to keep it.' : '' }}">
        <p class="hint">Stored encrypted.</p>
      </div>
      <div class="field">
        <label for="mail-from">From address</label>
        <input id="mail-from" name="from_address" type="email" maxlength="255" value="{{ $mOld('from_address', $mail['from_address']) }}" placeholder="reports@example.com"
               @if($mErr->has('from_address')) aria-invalid="true" @endif>
        @if ($mErr->has('from_address'))<p class="error">{{ $mErr->first('from_address') }}</p>@endif
      </div>
      <div class="field">
        <label for="mail-name">From name</label>
        <input id="mail-name" name="from_name" type="text" maxlength="80" value="{{ $mOld('from_name', $mail['from_name']) }}">
      </div>
    </div>
    <div class="actions" style="margin-top:14px">
      <button class="btn" type="submit">Save mail server</button>
      <span class="nt-inline">
        <label class="sr-only" for="mail-test-to">Send a test to</label>
        <input id="mail-test-to" type="email" placeholder="Send a test to..." value="{{ auth()->user()?->email }}">
        <button class="btn quiet" type="button" id="mail-test">Send test email</button>
      </span>
      <p class="nt-msg" id="mail-msg" role="status" aria-live="polite"></p>
    </div>
    <p class="hint" style="margin:6px 0 0">Save first: the test uses the saved settings.</p>
  </form>
</section>

{{-- ---------- Automatic report ---------- --}}
<section class="settings-section" id="report" aria-labelledby="report-title">
  <h2 id="report-title">Automatic report</h2>
  <p class="hint" style="margin:0 0 14px">A summary of the network (what is down, outages, users online, registrations, clients per access point, capacity, RADIUS) with the Users report PDF attached.
    Daily covers the last 24 hours, weekly the last 7 days, monthly the last 30 days.{{ $reportToTelegram ? ' It also goes to Telegram.' : '' }}</p>
  @if ($report['enabled'])
    <p class="nt-next">Next report: <b>{{ $nextReport->format('l, F j, Y \a\t g:i A') }}</b> ({{ $nextReport->timezone }}).</p>
  @endif

  <form method="POST" action="{{ route('settings.report') }}" novalidate>
    @csrf @method('PUT')
    <label class="nt-switch"><input type="checkbox" name="enabled" value="1" @checked($rOld('enabled', $report['enabled']))> Send the report automatically</label>
    <div class="nt-grid">
      <div class="field full">
        <label for="rp-to">Send to</label>
        <textarea id="rp-to" name="recipients" rows="2" maxlength="2000" placeholder="noc@example.com, it-head@example.com"
                  @if($rErr->has('recipients') || $rErr->has('recipients.*')) aria-invalid="true" @endif>{{ $rOld('recipients', implode(', ', $report['recipients'])) }}</textarea>
        @foreach ($rErr->getMessages() as $key => $msgs)
          @if (str_starts_with($key, 'recipients'))<p class="error">{{ $msgs[0] }}</p>@endif
        @endforeach
        <p class="hint">Email addresses, separated by commas.</p>
      </div>
      <div class="field">
        <label for="rp-freq">How often</label>
        <select id="rp-freq" name="frequency">
          @foreach (\App\Services\Notify\NotifySettings::FREQUENCIES as $v => $l)<option value="{{ $v }}" @selected($freq === $v)>{{ $l }}</option>@endforeach
        </select>
      </div>
      <div class="field">
        <label for="rp-time">At</label>
        <input id="rp-time" name="time" type="time" value="{{ $rOld('time', $report['time']) }}" required>
        @if ($rErr->has('time'))<p class="error">{{ $rErr->first('time') }}</p>@endif
      </div>
      <div class="field" data-when="weekly" @if($freq !== 'weekly') hidden @endif>
        <label for="rp-weekday">On</label>
        <select id="rp-weekday" name="weekday">
          @foreach (\App\Services\Notify\NotifySettings::WEEKDAYS as $v => $l)<option value="{{ $v }}" @selected((int) $rOld('weekday', $report['weekday']) === $v)>{{ $l }}</option>@endforeach
        </select>
      </div>
      <div class="field" data-when="monthly" @if($freq !== 'monthly') hidden @endif>
        <label for="rp-monthday">On day</label>
        <select id="rp-monthday" name="monthday">
          @for ($d = 1; $d <= 28; $d++)<option value="{{ $d }}" @selected((int) $rOld('monthday', $report['monthday']) === $d)>{{ $d }}</option>@endfor
        </select>
        @if ($rErr->has('monthday'))<p class="error">{{ $rErr->first('monthday') }}</p>@endif
      </div>
      <label class="nt-check full"><input type="checkbox" name="aps" value="1" @checked($rOld('aps', $report['aps']))> Add the clients per access point page to the PDF</label>
    </div>
    <div class="actions" style="margin-top:14px">
      <button class="btn" type="submit">Save schedule</button>
    </div>
  </form>
  <form method="POST" action="{{ route('settings.report.send') }}" style="margin-top:10px" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Sending...'">
    @csrf
    <button class="btn quiet" type="submit">Send a report now</button>
    <span class="hint">Uses the saved recipients and settings{{ $reportToTelegram ? ', and Telegram' : '' }}.</span>
  </form>
</section>

<script>
(function () {
  const csrf = document.querySelector('#tg-form input[name=_token]').value;
  const $ = (id) => document.getElementById(id);
  const say = (el, text, ok) => { el.textContent = text; el.className = 'nt-msg ' + (ok ? 'ok' : 'bad'); };
  async function post(url, body) {
    const res = await fetch(url, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.errors ? Object.values(data.errors)[0][0] : (data.message || 'Something went wrong (' + res.status + ').'));
    return data;
  }
  const tgBody = () => { const f = new FormData(); f.append('token', $('tg-token').value.trim()); f.append('chats', $('tg-chats').value); return f; };

  $('tg-test').addEventListener('click', async (e) => {
    e.target.disabled = true; say($('tg-msg'), 'Sending...', true);
    try { say($('tg-msg'), (await post(@json(route('settings.telegram.test')), tgBody())).message, true); }
    catch (err) { say($('tg-msg'), err.message, false); }
    finally { e.target.disabled = false; }
  });

  $('tg-find').addEventListener('click', async (e) => {
    e.target.disabled = true; say($('tg-msg'), 'Asking Telegram...', true);
    const list = $('tg-found');
    try {
      const data = await post(@json(route('settings.telegram.chats')), tgBody());
      list.innerHTML = '';
      (data.chats || []).forEach((c) => {
        const li = document.createElement('li');
        const b = document.createElement('button');
        b.type = 'button';
        b.textContent = c.name + ' (' + c.type + '): ' + c.id;
        b.addEventListener('click', () => {
          const now = $('tg-chats').value.split(/[\s,;]+/).filter(Boolean);
          if (!now.includes(c.id)) now.push(c.id);
          $('tg-chats').value = now.join(', ');
          say($('tg-msg'), 'Added ' + c.name + '. Save to keep it.', true);
        });
        li.appendChild(b); list.appendChild(li);
      });
      list.hidden = !(data.chats || []).length;
      say($('tg-msg'), data.message || 'Click a chat to add it.', !data.message);
    } catch (err) { say($('tg-msg'), err.message, false); }
    finally { e.target.disabled = false; }
  });

  $('mail-test').addEventListener('click', async (e) => {
    e.target.disabled = true; say($('mail-msg'), 'Sending...', true);
    const f = new FormData(); f.append('to', $('mail-test-to').value.trim());
    try { say($('mail-msg'), (await post(@json(route('settings.mail.test')), f)).message, true); }
    catch (err) { say($('mail-msg'), err.message, false); }
    finally { e.target.disabled = false; }
  });

  // Weekday or day of month, depending on how often
  $('rp-freq').addEventListener('change', (e) => {
    document.querySelectorAll('[data-when]').forEach((el) => { el.hidden = el.dataset.when !== e.target.value; });
  });
})();
</script>
