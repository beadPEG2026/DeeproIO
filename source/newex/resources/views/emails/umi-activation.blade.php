<!doctype html><html lang="{{ app()->getLocale() }}"><body style="margin:0;background:#f3faf5;color:#122019;font-family:Arial,sans-serif">
<div style="max-width:520px;margin:32px auto;padding:32px;background:white;border-radius:20px;border-top:5px solid #04df65">
@isset($testReference)<p style="padding:12px;background:#fff6d5;color:#55451c;font-size:13px">本地投递验收 {{ $testReference }}。本邮件不绑定任何账户，无需输入验证码。</p>@endisset
<p style="font-weight:bold;letter-spacing:2px;color:#176438">UMI × Deepro</p>
<h1 style="font-size:24px">{{ $purpose === 'binding' ? __('Bind your UMI account') : __('Activate your UMI account') }}</h1>
<p>{{ __('Your verification code') }}</p>
<p style="font-size:32px;letter-spacing:6px;font-weight:bold;background:#eafff0;padding:20px;text-align:center">{{ $code }}</p>
<p>{{ __('This code expires in 10 minutes and can only be used once.') }}</p>
<p>{{ $purpose === 'binding' ? __('Return to the UMI account binding page to verify ownership. This code cannot reset your password.') : __('Return to the UMI activation page to verify ownership and set your password.') }}</p>
<p style="color:#59635d;font-size:13px">{{ __('If you did not request this code, ignore this email. Never share verification codes with anyone.') }}</p>
</div></body></html>
