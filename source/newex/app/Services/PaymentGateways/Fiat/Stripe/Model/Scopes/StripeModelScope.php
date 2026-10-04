<?php

namespace App\Services\PaymentGateways\Fiat\Stripe\Model\Scopes;

trait StripeModelScope
{
    public function scopePending($query)
    {
        return $query->whereStatus('pending');
    }

    public function scopeConfirmed($query)
    {
        return $query->whereStatus('confirmed');
    }
}

