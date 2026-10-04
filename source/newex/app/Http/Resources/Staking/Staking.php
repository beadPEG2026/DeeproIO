<?php

namespace App\Http\Resources\Staking;

use Illuminate\Http\Resources\Json\JsonResource;

class Staking extends JsonResource
{
    public static $wrap = null;
    public $preserveKeys = true;

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $allowedDays = $this->parseCommaValue($this->allowed_days);
        $rewardsPercentage = $this->parseCommaValue($this->rewards_percentage);

        $ranges = [];
        $annualizedRanges = [];
        $periods = [];

        foreach ($allowedDays as $index => $days) {
            $days = trim($days);

            if ($days === '') {
                continue;
            }

            $reward = isset($rewardsPercentage[$index])
                ? trim($rewardsPercentage[$index])
                : '0';

            /*
             * 原始收益率，不年化。
             * 用于提交、预估收益、真实收益计算。
             */
            $ranges[$days] = $reward;

            /*
             * 年化收益率。
             * 公式：收益率 / 天数 * 365
             */
            $annualizedRanges[$days] = $this->calculateAnnualizedRate($reward, $days);
            $periods[] = ['days'=>(int)$days, 'period_rate'=>$reward, 'apr'=>$annualizedRanges[$days]];
        }

        $firstDays = isset($allowedDays[0]) ? trim($allowedDays[0]) : 0;
        $firstReward = isset($rewardsPercentage[0]) ? trim($rewardsPercentage[0]) : 0;

        return [
            'id' => $this->id,
            'currency_id' => $this->currency_id,
            'currency_idd' => $this->currency_idd,

            'currency' => $this->currency ? $this->currency->name : null,
            'currency_symbol' => $this->currency ? $this->currency->symbol : null,
            'currency_logo' => $this->currency ? url($this->currency->logo_path) : null,

            'currencyd' => $this->currencyd ? [
                'id' => $this->currencyd->id,
                'name' => $this->currencyd->name,
                'symbol' => $this->currencyd->symbol,
                'file' => ['url' => url($this->currencyd->logo_path)],
            ] : null,

            'allowed_days' => $this->allowed_days,
            'rewards_percentage' => $this->rewards_percentage,
            'min_amount' => $this->min_amount,
            'max_amount' => $this->max_amount,

            /*
             * 原始周期收益率：
             * 30 => 1.81
             * 60 => 3.61
             * 90 => 5.1
             */
            'ranges' => $ranges,
            'periods' => $periods,

            /*
             * 年化收益率：
             * 30 => 22.02
             * 60 => 21.96
             * 90 => 20.68
             */
            'annualized_ranges' => $annualizedRanges,

            /*
             * 列表页默认展示第一个周期的年化收益率。
             */
            'annualized_reward' => $this->calculateAnnualizedRate($firstReward, $firstDays),

            'subscription' => app(\App\Services\Staking\FundedTermProduct::class)->enabled($this->resource) ? app(\App\Services\Staking\FundedTermProduct::class)->availability($this->resource) : ['ready'=>$this->status==='active'],
            'status' => $this->status,
            'staking_type' => $this->staking_type,
            'rewards_percentage_t' => $this->rewards_percentage_t,
            'rewards_percentage_a' => $this->rewards_percentage_a,
            'Introduction' => $this->Introduction,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * 把 30,60,90 这种字符串转成数组。
     *
     * @param mixed $value
     * @return array
     */
    private function parseCommaValue($value)
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return [];
        }

        return explode(',', $value);
    }

    /**
     * 计算年化收益率。
     *
     * 示例：
     * 30天收益率 1.81%
     * 年化 = 1.81 / 30 * 365 = 22.02%
     *
     * @param mixed $reward
     * @param mixed $days
     * @return string
     */
    private function calculateAnnualizedRate($reward, $days)
    {
        $reward = (float) $reward;
        $days = (float) $days;

        if ($days <= 0) {
            return '0.00';
        }

        return number_format(($reward / $days) * 365, 2, '.', '');
    }
}
