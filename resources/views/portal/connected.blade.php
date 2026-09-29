<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Connected | {{ $page->site_name }}</title>
<style>
  body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#EEF2F0;color:#16242E;font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;text-align:center}
  .ok{width:64px;height:64px;margin:0 auto 14px;border-radius:50%;display:grid;place-items:center;background:#0E7C66}
  h1{margin:0 0 6px;font-size:1.5rem}
  p{margin:0;color:#56666E;max-width:32ch}
</style>
</head>
<body>
  <main>
    <div class="ok" aria-hidden="true">
      <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17l9-10"/></svg>
    </div>
    <h1>You're connected</h1>
    <p>Welcome to {{ $page->site_name }}. You can close this page and start browsing.</p>
  </main>
</body>
</html>