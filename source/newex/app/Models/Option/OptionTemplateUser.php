<?php

namespace App\Models\Option;

use Illuminate\Database\Eloquent\Model;

class OptionTemplateUser extends Model
{
    protected $table = 'option_template_users';

    public $fillable = [
        'user_id',
        'template_id',
    ];
}
