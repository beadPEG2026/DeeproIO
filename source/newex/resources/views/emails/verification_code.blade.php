<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}"><head><meta charset="UTF-8"><title>{{ __('Deepro email verification') }}</title></head>
<body style="font-family:Arial,sans-serif;background:#f2faf5;padding:24px;color:#193b2b">
<div style="max-width:560px;margin:auto;background:#fff;padding:32px;border:1px solid #d7eadd;border-radius:16px">
<h2 style="margin-top:0">Deepro</h2><p>{{ __('Your verification code') }}</p>
<div style="font-size:32px;font-weight:bold;letter-spacing:6px;padding:20px;background:#e8f5ed;border-radius:10px;text-align:center">{{ $code }}</div>
<p>{{ __('This code expires in 5 minutes. Do not share it.') }}</p>
<p style="font-size:13px;color:#526b5e">{{ __('If you did not request this code, you can ignore this email.') }}</p>
</div></body></html>