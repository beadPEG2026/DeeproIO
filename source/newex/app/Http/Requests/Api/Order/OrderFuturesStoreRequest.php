<?php

namespace App\Http\Requests\Api\Order;

use App\Http\Requests\Api\Order\Rules\OrderFuturesMarketRule;
use App\Http\Requests\Api\Order\Rules\OrderQuantityRule;
use App\Http\Requests\Api\Order\Rules\OrderMarketRule;
use App\Http\Requests\Api\Order\Rules\OrderPriceRule;
use App\Http\Requests\Api\Order\Rules\OrderQuoteQuantityRule;
use App\Http\Requests\Api\Order\Rules\OrderSideRule;
use App\Http\Requests\Api\Order\Rules\OrderTickerRule;
use App\Http\Requests\Api\Order\Rules\OrderTriggerConditionRule;
use App\Http\Requests\Api\Order\Rules\OrderTriggerPriceRule;
use App\Http\Requests\Api\Order\Rules\OrderTypeRule;
use App\Services\Market\MarketService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rules\RequiredIf;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use Setting;
use Auth;

class OrderFuturesStoreRequest extends FormRequest
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
        $isMarket = order_is_market($this->get('type'));
        $isBuyMarket = order_is_buy_market($this->get('type'), $this->get('side'));

        $hasTPSL = request()->get('enable_tp_sl', false);
        
        return [
            'client_order_id' => ['nullable','string','max:128','regex:/^[A-Za-z0-9_.:-]+$/D'],
            'leverage' => ['bail', 'required', 'numeric', 'integer', 'max:125', 'min:1'],
            'market' => ['bail', 'required', new OrderFuturesMarketRule()],
            'type' => ['bail', 'required', new OrderTypeRule()],
            'side' => ['bail', 'required', new OrderSideRule()],
            'quantity' => ['bail', new RequiredIf(!$isBuyMarket), new OrderTickerRule($isBuyMarket), new OrderQuantityRule()],
            'price' => ['bail', new RequiredIf(!$isMarket), new OrderTickerRule($isBuyMarket), new OrderPriceRule()],
            'quoteQuantity' => ['bail', new RequiredIf($isBuyMarket), new OrderTickerRule($isBuyMarket), new OrderQuoteQuantityRule()],
            'enable_tp_sl' => ['bail', 'sometimes', 'boolean'],
            'take_profit_price' => ['bail', new RequiredIf($hasTPSL), 'nullable', 'numeric', 'min:0'],
            'stop_loss_price' => ['bail', new RequiredIf($hasTPSL), 'nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Only enforce when futures timeframe feature is enabled
            $timeframeEnabled = Setting::get('futures.timeframe_enabled', false);
            $startAt = $this->input('startAt');

            if ($timeframeEnabled && !is_null($startAt) && $startAt !== '') {
                // Client sends milliseconds timestamp
                if (!is_numeric($startAt)) {
                    $validator->errors()->add('startAt', __('Invalid start time'));
                    return;
                }
                $startAtMs = (int) $startAt;
                $nowMs = Carbon::now()->getTimestampMs();
                if ($startAtMs < $nowMs) {
                    $validator->errors()->add('startAt', __('Start time must be in the future'));
                }
            }
            
            // Validate TP/SL prices
            $enableTPSL = $this->input('enable_tp_sl', false);
            $takeProfitPrice = $this->input('take_profit_price');
            $stopLossPrice = $this->input('stop_loss_price');
            $side = $this->input('side');
            $isLong = $side === 'buy';
            $orderType = $this->input('type');
            $isMarket = order_is_market($orderType);
            
            if ($enableTPSL) {
                // At least one TP or SL must be provided
                if ((!$takeProfitPrice || floatval($takeProfitPrice) <= 0) && 
                    (!$stopLossPrice || floatval($stopLossPrice) <= 0)) {
                    $validator->errors()->add('take_profit_price', __('Please provide at least Take Profit or Stop Loss price'));
                    return;
                }
                
                // Get entry price for validation
                $marketRepository = new \App\Repositories\Market\MarketRepository();
                $market = $marketRepository->get($this->input('market'));
                
                if ($market) {
                    $entryPrice = null;
                    
                    if ($isMarket) {
                        // For market orders, use current market price as entry price reference
                        try { $entryPrice = app(\App\Services\Market\VerifiedDerivativePrice::class)->forUser($market, auth()->user())['price']; } catch (\Illuminate\Validation\ValidationException $e) { $validator->errors()->add('price', __('A verified current price is unavailable.')); return; }
                    } else {
                        // For limit orders, use limit price
                        $entryPrice = $this->input('price');
                    }
                    
                    // Only validate if we have a valid entry price
                    if ($entryPrice && floatval($entryPrice) > 0) {
                        // Validate Take Profit
                        if ($takeProfitPrice && floatval($takeProfitPrice) > 0) {
                            if ($isLong) {
                                // Long position: TP should be above entry price
                                if (math_compare($takeProfitPrice, $entryPrice) <= 0) {
                                    if ($isMarket) {
                                        $validator->errors()->add('take_profit_price', __('Take Profit price must be above current market price for Long positions'));
                                    } else {
                                        $validator->errors()->add('take_profit_price', __('Take Profit price must be above entry price for Long positions'));
                                    }
                                }
                            } else {
                                // Short position: TP should be below entry price
                                if (math_compare($takeProfitPrice, $entryPrice) >= 0) {
                                    if ($isMarket) {
                                        $validator->errors()->add('take_profit_price', __('Take Profit price must be below current market price for Short positions'));
                                    } else {
                                        $validator->errors()->add('take_profit_price', __('Take Profit price must be below entry price for Short positions'));
                                    }
                                }
                            }
                        }
                        
                        // Validate Stop Loss
                        if ($stopLossPrice && floatval($stopLossPrice) > 0) {
                            if ($isLong) {
                                // Long position: SL should be below entry price
                                if (math_compare($stopLossPrice, $entryPrice) >= 0) {
                                    if ($isMarket) {
                                        $validator->errors()->add('stop_loss_price', __('Stop Loss price must be below current market price for Long positions'));
                                    } else {
                                        $validator->errors()->add('stop_loss_price', __('Stop Loss price must be below entry price for Long positions'));
                                    }
                                }
                            } else {
                                // Short position: SL should be above entry price
                                if (math_compare($stopLossPrice, $entryPrice) <= 0) {
                                    if ($isMarket) {
                                        $validator->errors()->add('stop_loss_price', __('Stop Loss price must be above current market price for Short positions'));
                                    } else {
                                        $validator->errors()->add('stop_loss_price', __('Stop Loss price must be above entry price for Short positions'));
                                    }
                                }
                            }
                        }
                        
                        // Validate TP and SL don't conflict
                        if ($takeProfitPrice && $stopLossPrice && 
                            floatval($takeProfitPrice) > 0 && floatval($stopLossPrice) > 0) {
                            if ($isLong) {
                                // Long: TP > entry > SL
                                if (math_compare($takeProfitPrice, $stopLossPrice) <= 0) {
                                    $validator->errors()->add('take_profit_price', __('Take Profit price must be above Stop Loss price'));
                                }
                            } else {
                                // Short: SL > entry > TP
                                if (math_compare($stopLossPrice, $takeProfitPrice) <= 0) {
                                    $validator->errors()->add('stop_loss_price', __('Stop Loss price must be above Take Profit price'));
                                }
                            }
                        }
                    }
                }
            }
        });
    }
}
