<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Mail\EmailVerificationCodeMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class EmailVerificationCodeController extends Controller
{
    private const CODE_TTL_MINUTES = 5;
    private const SEND_LOCK_SECONDS = 60;

    private const ALLOWED_PHONE_COUNTRY_CODES = [
        '+58',
        '+55',
        '+60',
        '+1',
        '+66',
        '+886',
        '+355',
        '+376',
        '+374',
        '+43',
        '+994',
        '+375',
        '+32',
        '+387',
        '+359',
        '+385',
        '+357',
        '+420',
        '+45',
        '+372',
        '+298',
        '+358',
        '+33',
        '+995',
        '+49',
        '+350',
        '+30',
        '+299',
        '+36',
        '+354',
        '+353',
        '+39',
        '+383',
        '+371',
        '+423',
        '+370',
        '+352',
        '+356',
        '+373',
        '+377',
        '+382',
        '+31',
        '+389',
        '+47',
        '+48',
        '+351',
        '+40',
        '+7',
        '+378',
        '+381',
        '+421',
        '+386',
        '+34',
        '+46',
        '+41',
        '+90',
        '+380',
        '+44',
    ];

    /**
     * 发送验证码
     * type=email 发送邮箱验证码
     * type=phone 发送短信验证码
     */
    public function send(Request $request)
    {
        $type = strtolower(trim((string) $request->input('type')));

        if (!$type) {
            if ($request->filled('email')) {
                $type = 'email';
            } elseif ($request->filled('phone')) {
                $type = 'phone';
            }
        }

        if ($type === 'email') {
            return $this->sendEmailCode((string) $request->input('email'));
        }

        if ($type === 'phone') {
            // SMS remains available to signed-in KYC users, never as a registration method.
            if (!$request->user('sanctum')) {
                return response()->json(['message' => __('Please enter a valid email address')], 422);
            }
            return $this->sendSmsCode((string) $request->input('phone'));
        }

        return response()->json([
            'message' => 'Verification type is required',
        ], 422);
    }

    protected function sendEmailCode(string $email)
    {
        $email = strtolower(trim($email));

        $validator = Validator::make([
            'email' => $email,
        ], [
            'email' => ['required', 'email:rfc,dns'],
        ], [
            'email.required' => 'Email is required',
            'email.email' => 'Please enter a valid email address',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $sendLockKey = 'register_email_code_send_lock:' . md5($email);
        $codeKey = 'register_email_code:' . md5($email);

        if (!Cache::add($sendLockKey, true, now()->addSeconds(self::SEND_LOCK_SECONDS))) {
            return response()->json([
                'message' => 'Please wait 60 seconds before requesting again.',
            ], 429);
        }

        $code = (string) random_int(100000, 999999);

        $now = now();
        $expiresAt = $now->copy()->addMinutes(self::CODE_TTL_MINUTES);

        Cache::put($codeKey, [
            'code' => $code,
            'email' => $email,
            'type' => 'email',
            'token' => Str::random(16),
            'created_at' => $now->toDateTimeString(),
            'expires_at' => $expiresAt->toIso8601String(),
            'attempts' => 0,
            'purpose' => 'identity_verification',
        ], $expiresAt);


        try {
            // SMTP acceptance is checked here; queue acceptance does not mean a mail was sent.
            $receipt = Mail::to($email)->send(new EmailVerificationCodeMail($code));
            $delivery = ['status' => 'smtp_accepted', 'at' => now()->toIso8601String(),
                'message_id' => $receipt?->getMessageId()];
            Cache::put('verification_mail_delivery:'.md5($email), $delivery, now()->addDays(7));
            Log::info('Deepro verification SMTP accepted', ['recipient_hash' => hash('sha256', $email)] + $delivery);

            return response()->json([
                'message' => __('Verification email submitted. Please check your inbox and spam folder.'),
                'type' => 'email',
                'delivery_status' => 'smtp_accepted',
            ]);
        } catch (\Throwable $e) {
            Cache::forget($codeKey);
            Cache::forget($sendLockKey);

            Cache::put('verification_mail_delivery:'.md5($email), ['status'=>'failed','at'=>now()->toIso8601String()], now()->addDays(7));
            Log::warning('Deepro verification SMTP failed', ['recipient_hash'=>hash('sha256',$email),'exception'=>get_class($e)]);

            return response()->json([
                'message' => __('Unable to send verification email. Please try again shortly.')
            ], 500);
        }
    }

    protected function sendSmsCode(string $rawPhone)
    {
        $phone = self::normalizePhoneForCache($rawPhone);

        if (!$phone) {
            return response()->json([
                'message' => 'Please enter a valid mobile phone number',
            ], 422);
        }

        if (!self::hasAllowedPhoneCountryCode($phone)) {
            return response()->json([
                'message' => 'Please select an allowed country code',
            ], 422);
        }

        if (preg_match('/^\+?86/', $phone)) {
            return response()->json([
                'message' => 'Phone numbers starting with +86 are not allowed',
            ], 422);
        }

        $sendPhone = ltrim($phone, '+');

        $sendLockKey = 'register_sms_code_send_lock:' . md5($sendPhone);
        $codeKey = 'register_sms_code:' . md5($sendPhone);

        if (!Cache::add($sendLockKey, true, now()->addSeconds(self::SEND_LOCK_SECONDS))) {
            return response()->json([
                'message' => 'Please wait 60 seconds before requesting again.',
            ], 429);
        }

        $code = (string) random_int(100000, 999999);

        $now = now();
        $expiresAt = $now->copy()->addMinutes(self::CODE_TTL_MINUTES);

        Cache::put($codeKey, [
            'code' => $code,
            'phone' => $sendPhone,
            'type' => 'phone',
            'token' => Str::random(16),
            'created_at' => $now->toDateTimeString(),
            'expires_at' => $expiresAt->toIso8601String(),
            'attempts' => 0,
            'purpose' => 'identity_verification',
        ], $expiresAt);


        try {
            $content = $this->buildSmsContent($code);
            $result = $this->sendInternationalSms($this->formatPhoneForSubmail($phone), $content);

            return response()->json([
                'message' => 'Verification code sent successfully',
                'type' => 'phone',
                'sms_id' => $result['send_id'],
            ]);
        } catch (\Throwable $e) {
            Cache::forget($codeKey);
            Cache::forget($sendLockKey);

            Log::warning('SUBMAIL international SMS send failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to send verification SMS',
            ], 500);
        }
    }

    protected function buildSmsContent(string $code): string
    {
        $signature = trim((string) config('services.submail.sms_signature', ''));

        return ($signature ? '[' . $signature . '] ' : '')
            . "Your verification code is {$code}, valid for 5 minutes.";
    }

    protected function formatPhoneForSubmail(string $phone): string
    {
        return '+' . ltrim($phone, '+');
    }

    protected function sendInternationalSms(string $to, string $content): array
    {
        $endpoint = (string) config('services.submail.international_sms_url');
        $payload = $this->buildSubmailPayload($to, $content);

        $response = Http::asForm()
            ->timeout(10)
            ->post($endpoint, $payload);

        if (!$response->successful()) {
            throw new \Exception('SUBMAIL request failed with HTTP status ' . $response->status());
        }

        $data = $response->json();

        if (!is_array($data)) {
            throw new \Exception('SUBMAIL returned an invalid response');
        }

        if (($data['status'] ?? null) !== 'success' || empty($data['send_id'])) {
            $message = $data['msg'] ?? $data['code'] ?? 'unknown error';

            throw new \Exception('SUBMAIL send failed: ' . $message);
        }

        return $data;
    }

    protected function buildSubmailPayload(string $to, string $content): array
    {
        $appid = trim((string) config('services.submail.app_id'));
        $appkey = trim((string) config('services.submail.app_key'));

        if ($appid === '' || $appkey === '') {
            throw new \Exception('SUBMAIL credentials are not configured');
        }

        $payload = [
            'appid' => $appid,
            'to' => $to,
            'content' => $content,
        ];

        $sender = trim((string) config('services.submail.sender', ''));

        if ($sender !== '') {
            $payload['sender'] = $sender;
        }

        $signType = strtolower(trim((string) config('services.submail.sign_type', 'normal')));

        if (in_array($signType, ['md5', 'sha1'], true)) {
            $payload['timestamp'] = time();
            $payload['sign_type'] = $signType;
            $payload['signature'] = $this->makeSubmailSignature($payload, $appid, $appkey, $signType);

            return $payload;
        }

        $payload['signature'] = $appkey;

        return $payload;
    }

    protected function makeSubmailSignature(array $payload, string $appid, string $appkey, string $signType): string
    {
        unset($payload['signature']);

        ksort($payload);

        $signatureString = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);

        return hash($signType, $appid . $appkey . $signatureString . $appid . $appkey);
    }

    protected static function normalizePhoneForCache(string $rawPhone): ?string
    {
        $phone = trim($rawPhone);
        $phone = preg_replace('/[^\d+]/', '', $phone);

        if (!preg_match('/^\+?[1-9]\d{7,14}$/', $phone)) {
            return null;
        }

        return $phone;
    }

    public static function hasAllowedPhoneCountryCode(string $phone): bool
    {
        $normalizedPhone = self::normalizePhoneForCache($phone);

        if (!$normalizedPhone) {
            return false;
        }

        $digits = ltrim($normalizedPhone, '+');

        $codes = self::ALLOWED_PHONE_COUNTRY_CODES;

        usort($codes, function ($a, $b) {
            return strlen($b) <=> strlen($a);
        });

        foreach ($codes as $code) {
            $codeDigits = ltrim($code, '+');

            if (strpos($digits, $codeDigits) === 0 && strlen($digits) > strlen($codeDigits)) {
                return true;
            }
        }

        return false;
    }

    public static function verifyEmailCode(string $email, string $code): bool
    {
        $email = strtolower(trim($email));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return self::consumeCode('register_email_code:' . md5($email), $code, 'email', $email);
    }

    public static function verifyPhoneCode(string $phone, string $code): bool
    {
        $phone = self::normalizePhoneForCache($phone);

        if (!$phone) {
            return false;
        }

        if (!self::hasAllowedPhoneCountryCode($phone)) {
            return false;
        }

        $sendPhone = ltrim($phone, '+');

        return self::consumeCode('register_sms_code:' . md5($sendPhone), $code, 'phone', $sendPhone);
    }

    private static function consumeCode(string $key, string $code, string $type, string $account): bool
    {
        if (!preg_match('/^\d{6}$/D', $code)) return false;
        return (bool) Cache::lock($key . ':verify', 10)->block(5, function () use ($key, $code, $type, $account) {
            $cached = Cache::get($key);
            if (!is_array($cached) || ($cached['type'] ?? null) !== $type || ($cached[$type] ?? null) !== $account) return false;
            try { $expires = \Carbon\CarbonImmutable::parse($cached['expires_at'] ?? '1970-01-01'); }
            catch (\Throwable $e) { Cache::forget($key); return false; }
            if ($expires->isPast() || (int)($cached['attempts'] ?? 0) >= 5) { Cache::forget($key); return false; }
            if (!hash_equals((string)($cached['code'] ?? ''), $code)) {
                $cached['attempts'] = (int)($cached['attempts'] ?? 0) + 1;
                if ($cached['attempts'] >= 5) Cache::forget($key);
                else Cache::put($key, $cached, $expires);
                return false;
            }
            Cache::forget($key);
            return true;
        });
    }

    public static function verifyCode(string $account, string $code): bool
    {
        $account = trim($account);

        if ($account === '') {
            return false;
        }

        if (filter_var($account, FILTER_VALIDATE_EMAIL)) {
            return self::verifyEmailCode($account, $code);
        }

        return self::verifyPhoneCode($account, $code);
    }

    public static function lookupCachedCode(string $account): array
    {
        $account = trim($account);

        if ($account === '') {
            return [
                'found' => false,
                'type' => null,
                'account' => '',
                'code' => null,
                'created_at' => null,
                'expires_at' => null,
            ];
        }

        if (filter_var($account, FILTER_VALIDATE_EMAIL)) {
            $email = strtolower($account);
            $cached = Cache::get('register_email_code:' . md5($email));

            return self::formatCachedCodeResult($cached, 'email', $email);
        }

        $phone = self::normalizePhoneForCache($account);

        if (!$phone) {
            return [
                'found' => false,
                'type' => 'phone',
                'account' => $account,
                'code' => null,
                'created_at' => null,
                'expires_at' => null,
            ];
        }

        $sendPhone = ltrim($phone, '+');
        $cached = Cache::get('register_sms_code:' . md5($sendPhone));

        return self::formatCachedCodeResult($cached, 'phone', $sendPhone);
    }

    protected static function formatCachedCodeResult($cached, string $type, string $account): array
    {
        if (!$cached || empty($cached['code'])) {
            return [
                'found' => false,
                'type' => $type,
                'account' => $account,
                'code' => null,
                'created_at' => null,
                'expires_at' => null,
            ];
        }

        return [
            'found' => true,
            'type' => $cached['type'] ?? $type,
            'account' => $cached[$type] ?? $account,
            'code' => (string) $cached['code'],
            'created_at' => $cached['created_at'] ?? null,
            'expires_at' => $cached['expires_at'] ?? null,
            'delivery' => $type === 'email' ? Cache::get('verification_mail_delivery:'.md5($account)) : null,
        ];
    }

    public static function forgetEmailCode(string $email): void
    {
        $email = strtolower(trim($email));

        if ($email === '') {
            return;
        }

        Cache::forget('register_email_code:' . md5($email));
        Cache::forget('register_email_code_send_lock:' . md5($email));
    }

    public static function forgetPhoneCode(string $phone): void
    {
        $phone = self::normalizePhoneForCache($phone);

        if (!$phone) {
            return;
        }

        $sendPhone = ltrim($phone, '+');

        Cache::forget('register_sms_code:' . md5($sendPhone));
        Cache::forget('register_sms_code_send_lock:' . md5($sendPhone));
    }

    public static function forgetCode(string $account): void
    {
        $account = trim($account);

        if ($account === '') {
            return;
        }

        if (filter_var($account, FILTER_VALIDATE_EMAIL)) {
            self::forgetEmailCode($account);
            return;
        }

        self::forgetPhoneCode($account);
    }
}
