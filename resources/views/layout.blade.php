<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Easy!Appointments MCP server')</title>
    <style>
        :root { --bg: #f7f7f5; --card: #fff; --ink: #1d1d1b; --muted: #66665f; --line: #e3e3dc; --accent: #2457c5; --ok: #1f7a4d; --warn: #9a5b00; --bad: #b3261e; }
        @media (prefers-color-scheme: dark) { :root { --bg: #151514; --card: #1e1e1c; --ink: #ececea; --muted: #a3a39c; --line: #34342f; --accent: #8fb0ff; --ok: #6fd3a0; --warn: #f0b860; --bad: #ff8a80; } }
        * { box-sizing: border-box; }
        body { margin: 0; font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--bg); color: var(--ink); }
        main { max-width: 960px; margin: 0 auto; padding: 32px 16px 64px; }
        .narrow { max-width: 460px; }
        h1 { font-size: 24px; margin: 0 0 8px; } h2 { font-size: 17px; margin: 32px 0 12px; }
        p { margin: 0 0 12px; } .muted { color: var(--muted); } .small { font-size: 13px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
        label { display: block; font-weight: 600; margin: 12px 0 4px; }
        input[type=email], input[type=password], select { width: 100%; padding: 9px 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--card); color: var(--ink); font: inherit; }
        button, .button { display: inline-block; padding: 9px 16px; border-radius: 8px; border: 1px solid var(--accent); background: var(--accent); color: #fff; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
        button.quiet, .button.quiet { background: transparent; color: var(--accent); }
        button.danger { background: transparent; color: var(--bad); border-color: var(--bad); }
        .row { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .actions { display: flex; gap: 12px; justify-content: flex-end; margin-top: 16px; }
        .actions button { min-width: 112px; }
        @media (max-width: 480px) { .actions { flex-direction: column-reverse; } .actions button { width: 100%; } }
        .choice { display: flex; gap: 10px; padding: 12px; border: 1px solid var(--line); border-radius: 8px; margin-bottom: 8px; cursor: pointer; font-weight: normal; }
        .choice input { margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { color: var(--muted); font-weight: 600; }
        .scroll { overflow-x: auto; }
        code, .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; }
        .pill { display: inline-block; padding: 1px 8px; border-radius: 99px; font-size: 12px; border: 1px solid currentColor; }
        .ok { color: var(--ok); } .preview, .replayed { color: var(--accent); } .denied { color: var(--warn); } .error { color: var(--bad); }
        .flash { border-left: 3px solid var(--ok); }
        .destination { padding: 14px 20px; } .destination p { margin: 0; }
        .destination .host { font-size: 17px; font-weight: 600; overflow-wrap: anywhere; margin-top: 2px; }
        .destination.unknown { border: 2px solid var(--bad); }
        .destination .warning { color: var(--bad); margin-top: 10px; }
        .errors { color: var(--bad); margin-top: 8px; }
        details summary { cursor: pointer; color: var(--accent); }
    </style>
</head>
<body>
<main class="@yield('width')">
    @yield('content')
</main>
</body>
</html>
