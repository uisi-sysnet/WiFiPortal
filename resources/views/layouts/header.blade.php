<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>@yield('title', 'Public WiFi Control')</title>
  @stack('head')

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">

  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            ink: '#16242E',
            'ink-2': '#465A66',
            paper: '#EDF0EE',
            panel: '#FFFFFF',
            line: '#CAD3CE',
            signal: '#0E7C66',
            'signal-soft': '#DCEFE9',
            warn: '#A8660F',
            fail: '#B3372E',
            focus: '#F2B84B',
          },
          fontFamily: {
            sans: ['"Public Sans"', 'system-ui', '-apple-system', '"Segoe UI"', 'sans-serif'],
            mono: ['"IBM Plex Mono"', 'ui-monospace', 'Menlo', 'Consolas', 'monospace'],
          },
          borderRadius: {
            DEFAULT: '6px',
          },
        },
      },
    }
  </script>

  <style type="text/tailwindcss">
    @layer base {
      body {
        @apply m-0 bg-paper text-ink font-sans text-[15px] leading-[1.55] tabular-nums;
      }
      a {
        @apply text-signal;
      }
      :focus-visible {
        @apply outline outline-[3px] outline-focus outline-offset-2;
      }
    }

    :root{
      --ink:#16242E; --ink-2:#465A66; --paper:#EDF0EE; --panel:#FFFFFF; --line:#CAD3CE;
      --signal:#0E7C66; --signal-soft:#DCEFE9; --warn:#A8660F; --fail:#B3372E; --focus:#F2B84B;
      --radius:6px;
    }
    *{box-sizing:border-box}
    body{margin:0;background:var(--paper);color:var(--ink);font:15px/1.55 "Public Sans",system-ui,-apple-system,"Segoe UI",sans-serif;font-variant-numeric:tabular-nums}
    .mono{font-family:"IBM Plex Mono",ui-monospace,Menlo,Consolas,monospace;font-size:.9em}
    a{color:var(--signal)}
    :focus-visible{outline:3px solid var(--focus);outline-offset:2px}

    main{max-width:1100px;margin:0 auto;padding:36px 24px 72px}
    .page-head{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;margin-bottom:24px;flex-wrap:wrap}
    h1{font-size:1.9rem;line-height:1.15;font-weight:700;letter-spacing:-.02em;margin:0}
    h2{font-size:1.15rem;margin:0 0 10px}
    .lede{color:var(--ink-2);margin:6px 0 0;max-width:64ch}

    .btn{display:inline-flex;align-items:center;gap:8px;background:var(--signal);color:#fff;border:1px solid var(--signal);border-radius:var(--radius);padding:10px 16px;font:inherit;font-weight:600;cursor:pointer;text-decoration:none}
    .btn:hover{filter:brightness(.92)}
    .btn.quiet{background:transparent;color:var(--ink);border-color:var(--line)}
    .btn.danger{background:transparent;color:var(--fail);border-color:currentColor}

    .notice{background:var(--signal-soft);border-left:4px solid var(--signal);padding:12px 16px;margin-bottom:24px}
    .alert{background:#F7E4E2;border-left:4px solid var(--fail);padding:12px 16px;margin-bottom:24px}

    .table-wrap{overflow-x:auto;border:1px solid var(--line);background:var(--panel)}
    table{width:100%;border-collapse:collapse}
    th,td{text-align:left;padding:12px 16px;border-bottom:1px solid var(--line);vertical-align:top;white-space:nowrap}
    th{font-size:.82rem;font-weight:600;color:var(--ink-2);background:#F5F7F6}
    tr:last-child td{border-bottom:0}
    td a{font-weight:600;text-decoration:none}
    td small{display:block;color:var(--ink-2)}

    .status{display:inline-flex;align-items:center;gap:7px;font-weight:600;font-size:.88rem}
    .status::before{content:"";width:9px;height:9px;border-radius:50%;background:currentColor}
    .status.online{color:var(--signal)}
    .status.pending,.status.provisioning{color:var(--warn)}
    .status.provisioning::before{animation:pulse 1.2s ease-in-out infinite}
    .status.failed,.status.offline{color:var(--fail)}
    .status.unknown{color:var(--ink-2)}
    .counts{display:flex;flex-wrap:wrap;gap:20px;margin:0 0 16px}
    .row-actions{display:flex;gap:8px;justify-content:flex-end}
    .row-actions form{margin:0}
    .btn.sm{padding:5px 10px;font-size:.85rem}
    .sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
    @keyframes pulse{50%{opacity:.3}}
    @media (prefers-reduced-motion:reduce){.status.provisioning::before{animation:none}}

    /* Address plan: one cell per gateway block */
    .plan{background:var(--panel);border:1px solid var(--line);padding:18px 20px 20px;margin-bottom:28px}
    .plan p{margin:0;color:var(--ink-2);max-width:78ch}
    .plan strong{color:var(--ink)}
    .plan-grid{display:grid;grid-template-columns:repeat(64,minmax(0,1fr));gap:2px;margin-top:14px}
    .plan-grid span{aspect-ratio:1;background:#DCE2DF;border-radius:1px}
    .plan-grid span.used{background:var(--signal)}
    .plan-grid span.next{background:var(--focus)}
    .legend{display:flex;gap:18px;margin-top:10px;font-size:.82rem;color:var(--ink-2)}
    .legend i{display:inline-block;width:10px;height:10px;margin-right:6px;vertical-align:-1px;background:#DCE2DF}
    .legend i.used{background:var(--signal)} .legend i.next{background:var(--focus)}

    /* Forms */
    .form-layout{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:28px;align-items:start}
    fieldset{border:1px solid var(--line);background:var(--panel);padding:20px 22px 6px;margin:0 0 20px;border-radius:var(--radius);min-width:0}
    legend{font-weight:700;padding:0 6px;font-size:1.02rem}
    .field{display:grid;gap:6px;margin-bottom:18px}
    .field label{font-weight:600;font-size:.92rem}
    .hint{font-size:.85rem;color:var(--ink-2);margin:0}
    .error{font-size:.86rem;color:var(--fail);margin:0}
    input[type=text],input[type=email],input[type=password],input[type=number]{font:inherit;width:100%;padding:9px 11px;border:1px solid #A7B4AD;border-radius:4px;background:#fff;color:var(--ink)}
    input[aria-invalid="true"]{border-color:var(--fail)}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    .check{display:flex;gap:10px;align-items:center;font-weight:500}
    .check input{width:18px;height:18px;accent-color:var(--signal)}
    .actions{display:flex;gap:12px;align-items:center}

    .aside{position:sticky;top:20px;background:var(--ink);color:#DCE7E2;padding:22px;border-radius:var(--radius)}
    .aside h2{color:#fff}
    .aside dl{margin:14px 0 0;display:grid;grid-template-columns:auto 1fr;gap:8px 14px}
    .aside dt{color:#93A8A0;font-size:.85rem}
    .aside dd{margin:0;color:#fff}
    .aside p{font-size:.88rem;margin:14px 0 0;color:#B9C9C2}

    /* Detail page */
    .facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1px;background:var(--line);border:1px solid var(--line);margin-bottom:28px}
    .facts div{background:var(--panel);padding:14px 16px}
    .facts dt{font-size:.82rem;color:var(--ink-2)}
    .facts dd{margin:2px 0 0;font-weight:600}
    .log{background:var(--panel);border:1px solid var(--line);margin:0;padding:12px 16px 12px 44px}
    .log li{padding:6px 0;border-bottom:1px solid #E6EAE8}
    .log li:last-child{border-bottom:0}
    .log time{color:var(--ink-2);font-size:.82rem;margin-left:8px}

    @media (max-width:860px){
      .form-layout{grid-template-columns:1fr}
      .aside{position:static}
      .row{grid-template-columns:1fr}
      .topbar{flex-wrap:wrap;gap:12px 20px;padding:12px 18px}
    }
  </style>
</head>
<body class="@yield('body-class')">
