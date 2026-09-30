<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Terms and Conditions | {{ $page->site_name }}</title>
<style>body{margin:0;font:16px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#16242E;background:#F3F5F4}main{max-width:680px;margin:0 auto;padding:28px 20px 48px}a{color:#0E7C66}</style>
</head>
<body>
<main>
  <p><a href="{{ route('portal.show', ['network' => $network->portal_code]) }}">Back to login</a></p>
  <h1>Terms and Conditions</h1>
  {!! $page->termsHtml() !!}
</main>
</body>
</html>
