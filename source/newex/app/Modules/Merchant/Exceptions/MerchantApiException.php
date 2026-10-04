<?php

namespace App\Modules\Merchant\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class MerchantApiException extends Exception
{
    protected string $errorCode;
    protected int $statusCode;
    protected ?array $details;

    public function __construct(
        string $errorCode,
        string $message,
        int $statusCode = 400,
        ?array $details = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);

        $this->errorCode = $errorCode;
        $this->statusCode = $statusCode;
        $this->details = $details;
    }

    /**
     * Render the exception as an HTTP response.
     */
    public function render(): JsonResponse
    {
        $response = [
            'success' => false,
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
            ],
        ];

        if ($this->details) {
            $response['error']['details'] = $this->details;
        }

        return response()->json($response, $this->statusCode);
    }

    /**
     * Get the error code
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Get the HTTP status code
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Static factory methods for common errors
     */
    public static function notFound(string $resource = 'Resource'): self
    {
        return new self('NOT_FOUND', "{$resource} not found", 404);
    }

    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return new self('UNAUTHORIZED', $message, 401);
    }

    public static function forbidden(string $message = 'Access denied'): self
    {
        return new self('FORBIDDEN', $message, 403);
    }

    public static function validationFailed(array $errors): self
    {
        return new self('VALIDATION_FAILED', 'Validation failed', 422, ['errors' => $errors]);
    }

    public static function rateLimitExceeded(int $retryAfter = 60): self
    {
        return new self('RATE_LIMIT_EXCEEDED', 'Rate limit exceeded', 429, ['retry_after' => $retryAfter]);
    }

    public static function invoiceNotCancellable(string $status): self
    {
        return new self('INVOICE_NOT_CANCELLABLE', "Invoice cannot be cancelled in status: {$status}", 400);
    }

    public static function invoiceExpired(): self
    {
        return new self('INVOICE_EXPIRED', 'Invoice has expired', 400);
    }

    public static function currencyNotAvailable(string $currency): self
    {
        return new self('CURRENCY_NOT_AVAILABLE', "Currency {$currency} is not available", 400);
    }

    public static function amountOutOfRange(float $min, float $max): self
    {
        return new self('AMOUNT_OUT_OF_RANGE', "Amount must be between {$min} and {$max} USD", 400, [
            'min_amount' => $min,
            'max_amount' => $max,
        ]);
    }

    public static function dailyLimitExceeded(float $limit): self
    {
        return new self('DAILY_LIMIT_EXCEEDED', 'Daily volume limit exceeded', 400, [
            'daily_limit_usd' => $limit,
        ]);
    }

    public static function invalidSignature(): self
    {
        return new self('INVALID_SIGNATURE', 'Invalid request signature', 401);
    }

    public static function invalidApiKey(): self
    {
        return new self('INVALID_API_KEY', 'Invalid or expired API key', 401);
    }

    public static function merchantInactive(): self
    {
        return new self('MERCHANT_INACTIVE', 'Merchant account is not active', 403);
    }

    public static function webhookDeliveryFailed(string $reason): self
    {
        return new self('WEBHOOK_DELIVERY_FAILED', "Webhook delivery failed: {$reason}", 502);
    }

    public static function rateUnavailable(): self
    {
        return new self('RATE_UNAVAILABLE', 'Exchange rate is temporarily unavailable', 503);
    }

    public static function addressPoolExhausted(): self
    {
        return new self('ADDRESS_POOL_EXHAUSTED', 'No deposit addresses available', 503);
    }
}
