<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
 <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
 <title>{{ $status }} · Deepro</title>
 <link rel="stylesheet" href="/css/deepro-experience.css?v={{ filemtime(public_path('css/deepro-experience.css')) }}">
 <style>body{margin:0;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.dp-error{min-height:100vh;display:grid;place-items:center;background:var(--xp-soft);color:var(--xp-ink);padding:24px;box-sizing:border-box}.dp-error article{max-width:540px;width:100%;box-sizing:border-box;padding:48px 32px;border-radius:24px;background:var(--xp-surface);border:1px solid var(--xp-line);text-align:center}.dp-error .brand{width:150px;max-width:100%;margin-bottom:36px}.dp-error h1{font-size:64px;margin:0 0 24px;letter-spacing:-.06em}.dp-error p{font-size:16px;line-height:1.8;color:var(--xp-muted);margin:0 0 32px}.dp-error a{display:inline-flex;align-items:center;min-height:46px;padding:0 26px;background:var(--xp-yellow);color:#17191c;text-decoration:none;font-weight:700;border-radius:10px}.dp-error small{display:block;margin-top:32px;color:var(--xp-muted)}@media(prefers-color-scheme:dark){body{--xp-soft:#14161a;--xp-surface:#191b20;--xp-line:#303238;--xp-ink:#f3f4f6;--xp-muted:#a6aab3}.brand{background:#fff;border-radius:8px;padding:10px}}</style>
</head>
<body><main class="dp-error"><article><img class="brand" src="/images/deepro-logo.svg" alt="Deepro"><h1>{{ $status }}</h1><p>{{ __($messageKey) }}</p><a href="/">{{ __('Home') }} ↗</a><small>Deepro © {{ date('Y') }}</small></article></main></body>
</html>
