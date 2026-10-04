<?php

namespace App\Services\PaymentGateways\Fiat\Stripe\Model;

use App\Services\PaymentGateways\Fiat\Stripe\Model\Scopes\StripeModelScope;
use Illuminate\Database\Eloquent\Model;

class StripeModel extends Model
{
    use StripeModelScope;

    protected $guarded = [];

    protected $table = 'stripe_payment_intents';
}
