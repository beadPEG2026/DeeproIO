<?php

namespace App\Http\Requests\Web\SystemMonitor;

use App\Http\Requests\Web\SystemMonitor\Rules\PingRule;
use Illuminate\Foundation\Http\FormRequest;
use Auth;

class SystemMonitorFormRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'ping' => ['required', 'max:100', new PingRule()],
        ];
    }

    public function attributes()
    {
        return [
            'ping' => __("License Key"),
        ];
    }
}
