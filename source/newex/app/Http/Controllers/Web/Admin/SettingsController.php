<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Settings\SettingsFormRequest;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function index()
    {
        $settingsService = new SettingsService();

        $general = $settingsService->getSettings('general');
        $general = is_array($general) ? $general : [];

        /*
         * APP 下载链接强制从 settings 表读取。
         * 这样即使 SettingsService 没有正常读取新增 key，页面也能展示数据库里的值。
         */
        $general = array_merge($this->defaultGeneralDownloadSettings(), $general);
        $general['android_download_url'] = $this->getSettingValue(
            'general.android_download_url',
            $general['android_download_url'] ?? ''
        );
        $general['ios_download_url'] = $this->getSettingValue(
            'general.ios_download_url',
            $general['ios_download_url'] ?? ''
        );

        $trade = $settingsService->getSettings('trade');
        $trade = is_array($trade) ? $trade : [];

        /*
         * settings 表里没有这些 key 时，后台页面仍然显示默认值。
         * 保存交易设置后，会把这些 key 写入 settings 表。
         */
        $trade = array_merge($this->defaultLcVipBoostSettings(), $trade);

        /*
         * Unlimit 汇率和手续费。
         * settings 表里没有这些 key 时，后台页面仍然显示默认值。
         * 保存 Unlimit 设置后，会把这些 key 写入 settings 表。
         */
        $unlimit = $settingsService->getSettings('unlimit');
        $unlimit = is_array($unlimit) ? $unlimit : [];
        $unlimit = array_merge($this->defaultUnlimitExchangeSettings(), $unlimit);
        $unlimit['exchange_rate'] = $this->getSettingValue(
            'unlimit.exchange_rate',
            $unlimit['exchange_rate'] ?? '1'
        );
        $unlimit['processing_fee'] = $this->getSettingValue(
            'unlimit.processing_fee',
            $unlimit['processing_fee'] ?? '0'
        );

        return Inertia::render('Admin/Settings/Index', [
            'general' => $general,
            'trade' => $trade,
            'mail' => $settingsService->getSettings('mail'),
            'coinpayments' => $settingsService->getSettings('coinpayments'),
            'recaptcha' => $settingsService->getSettings('recaptcha'),
            'ethereum' => $settingsService->getSettings('ethereum'),
            'bnb' => $settingsService->getSettings('bnb'),
            'bitcoin' => $settingsService->getSettings('bitcoin'),
            'polygon' => $settingsService->getSettings('polygon'),
            'xlayer' => $settingsService->getSettings('xlayer'),
            'solana' => $settingsService->getSettings('solana'),
            'ripple' => $settingsService->getSettings('ripple'),
            'ton' => $settingsService->getSettings('ton'),
            'tron' => $settingsService->getSettings('tron'),
            'customtoken' => $settingsService->getSettings('customtoken'),
            'stripe' => $settingsService->getSettings('stripe'),
            'unlimit' => $unlimit,
            'notification' => $settingsService->getSettings('notification'),
            'social' => $settingsService->getSettings('social'),

            'lc30' => $settingsService->getSettings('lc30'),
            'lc90' => $settingsService->getSettings('lc90'),
            'lc180' => $settingsService->getSettings('lc180'),
            'lc_dq30' => $settingsService->getSettings('lc_dq30'),
            'lc365' => $settingsService->getSettings('lc365'),
            'lc_dq365' => $settingsService->getSettings('lc_dq365'),
            'lc_dq90' => $settingsService->getSettings('lc_dq90'),
            'lc_vip_1_boost_percent' => $settingsService->getSettings('lc_vip_1_boost_percent'),
            'lc_vip_2_boost_percent' => $settingsService->getSettings('lc_vip_2_boost_percent'),
            'lc_vip_3_boost_percent' => $settingsService->getSettings('lc_vip_3_boost_percent'),
            'lc_vip_4_boost_percent' => $settingsService->getSettings('lc_vip_4_boost_percent'),
            'lc_vip_5_boost_percent' => $settingsService->getSettings('lc_vip_5_boost_percent'),
            'lc_vip_6_boost_percent' => $settingsService->getSettings('lc_vip_6_boost_percent'),
            'lc_vip_7_boost_percent' => $settingsService->getSettings('lc_vip_7_boost_percent'),
            'lc_vip_8_boost_percent' => $settingsService->getSettings('lc_vip_8_boost_percent'),
            'lc_dq180' => $settingsService->getSettings('lc_dq180'),
        ]);
    }

    /**
     * Update resource.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(SettingsFormRequest $request)
    {
        $values = $request->validated();
        $key = array_key_first($values);
        if ($key !== null) (new SettingsService())->updateBatch($key, $values[$key]);

        return Redirect::route('admin.settings');
    }

    private function defaultGeneralDownloadSettings(): array
    {
        return [
            'android_download_url' => '',
            'ios_download_url' => '',
        ];
    }

    private function defaultUnlimitExchangeSettings(): array
    {
        return [
            /*
             * 汇率。
             * 例如 150 表示 1 个加密货币按 150 法币计算，具体怎么用看你的下单逻辑。
             */
            'exchange_rate' => '1',

            /*
             * 手续费百分比。
             * 例如 3 表示 3%。
             */
            'processing_fee' => '0',
        ];
    }

    private function getSettingValue(string $key, string $default = ''): string
    {
        $value = DB::table('settings')
            ->where('key', $key)
            ->value('value');

        if ($value === null) {
            return $default;
        }

        return (string) $value;
    }

    private function saveGeneralDownloadSettings(array $general): void
    {
        foreach ($this->defaultGeneralDownloadSettings() as $shortKey => $defaultValue) {
            $value = $general[$shortKey] ?? $defaultValue;

            if ($value === null) {
                $value = $defaultValue;
            }

            DB::table('settings')->updateOrInsert(
                ['key' => 'general.' . $shortKey],
                ['value' => (string) $value]
            );
        }
    }

    private function defaultLcVipBoostSettings(): array
    {
        return [
            'lc_vip_1_boost_percent' => '10',
            'lc_vip_2_boost_percent' => '20',
            'lc_vip_3_boost_percent' => '30',
            'lc_vip_4_boost_percent' => '40',
            'lc_vip_5_boost_percent' => '50',
            'lc_vip_6_boost_percent' => '60',
            'lc_vip_7_boost_percent' => '70',
            'lc_vip_8_boost_percent' => '80',
        ];
    }

    private function saveLcVipBoostSettings(array $trade): void
    {
        foreach ($this->defaultLcVipBoostSettings() as $shortKey => $defaultValue) {
            $value = $trade[$shortKey] ?? $defaultValue;

            if ($value === null || $value === '') {
                $value = $defaultValue;
            }

            DB::table('settings')->updateOrInsert(
                ['key' => 'trade.' . $shortKey],
                ['value' => (string) $value]
            );
        }
    }

    private function saveUnlimitExchangeSettings(array $unlimit): void
    {
        foreach ($this->defaultUnlimitExchangeSettings() as $shortKey => $defaultValue) {
            $value = $unlimit[$shortKey] ?? $defaultValue;

            if ($value === null || $value === '') {
                $value = $defaultValue;
            }

            DB::table('settings')->updateOrInsert(
                ['key' => 'unlimit.' . $shortKey],
                ['value' => (string) $value]
            );
        }
    }
}