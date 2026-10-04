<?php

namespace App\Models\Currency\Traits\Relations;

use App\Models\BankAccount\BankAccount;
use App\Models\FileUpload\FileUpload;
use App\Models\Network\Network;

trait CurrencyRelation
{
    public function networks()
    {
        return $this->belongsToMany(Network::class, 'currency_networks');
    }

    public function file()
    {
        return $this->belongsTo(FileUpload::class, 'file_id');
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class, 'bank_account');
    }
}


