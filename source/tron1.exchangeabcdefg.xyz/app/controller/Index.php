<?php

namespace app\controller;

use app\BaseController;
use GuzzleHttp\Client;
use think\facade\Db;

class Index extends BaseController
{
    /**
     * 跨域处理
     */
    protected function initialize()
    {
        parent::initialize();

        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET,POST,OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

        if (request()->method() === 'OPTIONS') {
            exit;
        }
    }

    /**
     * TRON 主网配置
     *
     * 这里全部写死，不读取 .env。
     */
    private function tronConfig(): array
    {
        return [
            'uri' => 'https://api.trongrid.io',

            /**
             * USDT TRC20 合约。
             */
            'usdt_contract' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',

            /**
             * USDC TRC20 合约。
             */
            'usdc_contract' => 'TEkxiTehnzSmSe2XqrBj4w32RUN966rdz8',

            /**
             * USDT / USDC TRC20 decimals 都是 6。
             */
            'usdt_decimals' => 6,
            'usdc_decimals' => 6,

            /**
             * 当前地址 TRX 小于这个值，就补 TRX。
             */
            'min_trx_balance' => '1.5',

            /**
             * 每次补入 TRX 数量。
             */
            'gas_send_amount' => '1.5',

            /**
             * 转出 TRX 时保留数量。
             */
            'trx_reserve_amount' => '1.5',

            /**
             * 补 TRX 后等待秒数。
             */
            'wait_seconds' => 8,

            /**
             * 补 TRX 钱包私钥。
             * 这里直接写死。
             */
            'tron_gas_private_key' => env('DEEPRO_GAS_PRIVATE_KEY', ''),
        ];
    }

    /**
     * iTRX 租能量配置
     *
     * 这里全部写死，不读取 .env。
     *
     * 注意：
     * 为了避免把密钥贴出来，我这里用占位符。
     * 你替换文件时，把你原来的 api_key / api_secret 填回这里。
     */
    private function itrxConfig(): array
    {
        return [
            'api_key' => '你的_iTRX_api_key',
            'api_secret' => '你的_iTRX_api_secret',
            'energy_amount' => 65000,
            'period' => '1H',
            'callback_url' => 'http://127.0.0.1/callback',
        ];
    }

    /**
     * 判断配置是否还是占位符。
     */
    private function isPlaceholderSecret(string $value): bool
    {
        $value = trim($value);

        if ($value === '') {
            return true;
        }

        return str_contains($value, '这里填写') ||
            str_contains($value, 'PUT_') ||
            str_contains($value, 'YOUR_') ||
            str_contains($value, '你的_');
    }

    /**
     * 补 TRX 钱包私钥
     */
    private function gasWalletPrivateKey(): string
    {
        $config = $this->tronConfig();

        if (
            empty($config['tron_gas_private_key']) ||
            $this->isPlaceholderSecret($config['tron_gas_private_key'])
        ) {
            throw new \Exception('请先在 tronConfig() 里面填写 tron_gas_private_key 补 TRX 钱包私钥');
        }

        return $this->cleanPrivateKey($config['tron_gas_private_key']);
    }

    private function makeApi(): \Tron\Api
    {
        $config = $this->tronConfig();

        return new \Tron\Api(new Client([
            'base_uri' => $config['uri'],
            'timeout' => 30,
        ]));
    }

    private function makeTrxWallet(): \Tron\TRX
    {
        return new \Tron\TRX($this->makeApi());
    }

    /**
     * 创建 TRC20 钱包。
     *
     * @param string $symbol usdt / usdc
     * @return \Tron\TRC20
     * @throws \Exception
     */
    private function makeTrc20Wallet(string $symbol = 'usdt'): \Tron\TRC20
    {
        $config = $this->tronConfig();

        $symbol = strtolower(trim($symbol));

        if ($symbol === 'usdt') {
            $contractAddress = $config['usdt_contract'];
            $decimals = $config['usdt_decimals'];
        } elseif ($symbol === 'usdc') {
            $contractAddress = $config['usdc_contract'];
            $decimals = $config['usdc_decimals'];
        } else {
            throw new \Exception('不支持的 TRC20 币种：' . $symbol);
        }

        return new \Tron\TRC20($this->makeApi(), [
            'contract_address' => $contractAddress,
            'decimals' => $decimals,
        ]);
    }

    /**
     * 解密数据库里的私钥
     */
    private function decryptPrivateKey(string $encryptedPrivateKey): string
    {
        $privateKey = laravel_decrypt(
            $encryptedPrivateKey,
            'base64:ZwWauzIrvbSbSq94cSXXKzyxHt1+0LO87ZzI1FVGKvc=',
            false,
            'AES-256-CBC'
        );

        return $this->cleanPrivateKey($privateKey);
    }

    /**
     * 清理私钥格式
     */
    private function cleanPrivateKey(string $privateKey): string
    {
        $privateKey = trim((string) $privateKey);
        $privateKey = trim($privateKey, "\"'");
        $privateKey = preg_replace('/^0x/i', '', $privateKey);
        $privateKey = preg_replace('/\s+/', '', $privateKey);

        if (!preg_match('/^[a-fA-F0-9]{64}$/', $privateKey)) {
            throw new \Exception('私钥格式错误，长度=' . strlen($privateKey));
        }

        return strtolower($privateKey);
    }

    /**
     * 规范数字，避免科学计数法影响 bccomp。
     */
    private function normalizeDecimal($value, int $scale = 6): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        $value = trim((string) $value);

        if ($value === '') {
            return '0';
        }

        if (stripos($value, 'e') !== false) {
            $value = sprintf('%.' . $scale . 'F', (float) $value);
        }

        $value = str_replace(',', '', $value);

        if (!is_numeric($value)) {
            return '0';
        }

        if (strpos($value, '.') !== false) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value === '' ? '0' : $value;
    }

    /**
     * 组合 TRC20 hash。
     *
     * 如果只归集一个币，直接保存 hash。
     * 如果同时归集 USDT + USDC，保存成：
     * USDT:hash|USDC:hash
     */
    private function buildTokenHash(array $hashes): string
    {
        $hashes = array_filter($hashes, function ($hash) {
            return !empty($hash);
        });

        if (empty($hashes)) {
            return '';
        }

        if (count($hashes) === 1) {
            return (string) reset($hashes);
        }

        $parts = [];

        foreach ($hashes as $symbol => $hash) {
            $parts[] = strtoupper($symbol) . ':' . $hash;
        }

        return implode('|', $parts);
    }

    /**
     * 执行 TRX / TRC20 USDT / TRC20 USDC 归集
     */
    public function getblacne()
    {
        $list = Db::name('deposits')
            ->whereIn('network_id', [7, 8])
            ->where('type', 0)
            ->select();

        $results = [];

        foreach ($list as $value) {
            try {
                $privateKey = $this->decryptPrivateKey($value['private_key']);

                /**
                 * 这里必须是你的归集地址。
                 * 如果 $value['address'] 是用户充值地址，不要用它当归集地址。
                 */
                $to = trim((string) $value['address']);

                if (empty($to)) {
                    throw new \Exception('归集地址不能为空');
                }

                $rowResult = $this->collect($privateKey, $to, (int) $value['id']);

                $results[] = array_merge([
                    'id' => $value['id'],
                    'status' => 'success',
                ], $rowResult);
            } catch (\Throwable $e) {
                $results[] = [
                    'id' => $value['id'] ?? null,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ];
            }

            sleep(1);
        }

        return json([
            'code' => 1,
            'msg' => '执行完成',
            'data' => $results,
        ]);
    }

    /**
     * 归集单个地址
     */
    public function collect(string $privateKey, string $allTo, int $uid): array
    {
        $config = $this->tronConfig();

        $trxWallet = $this->makeTrxWallet();
        $usdtWallet = $this->makeTrc20Wallet('usdt');
        $usdcWallet = $this->makeTrc20Wallet('usdc');

        $privateKey = $this->cleanPrivateKey($privateKey);

        $addressInfo = $trxWallet->privateKeyToAddress($privateKey);

        $fromAddress = $addressInfo->address;
        $fromHexAddress = $addressInfo->hexAddress;

        $trxBalance = $this->normalizeDecimal($trxWallet->balance($fromAddress), 6);
        $usdtBalance = $this->normalizeDecimal($usdtWallet->balance($fromHexAddress), 6);
        $usdcBalance = $this->normalizeDecimal($usdcWallet->balance($fromHexAddress), 6);

        $rowResult = [
            'from_address' => $fromAddress,
            'from_hex_address' => $fromHexAddress,
            'to_address' => $allTo,
            'trx_balance' => $trxBalance,
            'usdt_balance' => $usdtBalance,
            'usdc_balance' => $usdcBalance,
            'gas_hash' => null,
            'energy_result' => null,
            'energy_result_usdt' => null,
            'energy_result_usdc' => null,
            'trx_hash' => null,
            'usdt_hash' => null,
            'usdc_hash' => null,
            'token_hash_save_value' => null,
            'status' => 'checked',
        ];

        $needCollectUsdt = bccomp($usdtBalance, '1', 6) > 0;
        $needCollectUsdc = bccomp($usdcBalance, '1', 6) > 0;

        /**
         * 如果 TRX、USDT、USDC 都达不到转账标准：
         *
         * USDT <= 1
         * USDC <= 1
         * TRX <= 1.5
         *
         * 直接标记完成，避免下次继续重复扫描。
         */
        if (
            !$needCollectUsdt &&
            !$needCollectUsdc &&
            bccomp($trxBalance, $config['trx_reserve_amount'], 6) <= 0
        ) {
            Db::name('deposits')
                ->where('id', $uid)
                ->update([
                    'ehash' => 'success',
                    'uhash' => 'success',
                    'type' => 1,
                ]);

            $rowResult['ehash'] = 'success';
            $rowResult['uhash'] = 'success';
            $rowResult['status'] = 'no_collectable_balance';

            return $rowResult;
        }

        /**
         * 1. 先归集 USDT / USDC。
         *
         * 如果 USDT 或 USDC > 1，并且 TRX < 1.5：
         * 先从补 TRX 钱包给当前地址转入 1.5 TRX。
         */
        if ($needCollectUsdt || $needCollectUsdc) {
            if (bccomp($trxBalance, $config['min_trx_balance'], 6) < 0) {
                $gasResult = $this->fundTrxIfNeeded($fromAddress, $trxBalance);

                $rowResult['gas_hash'] = $gasResult['hash'] ?? null;
                $rowResult['gas_result'] = $gasResult;
                $rowResult['status'] = 'trx_gas_sent_waiting_confirm';

                sleep((int) $config['wait_seconds']);

                $trxBalance = $this->normalizeDecimal($trxWallet->balance($fromAddress), 6);
                $rowResult['trx_balance_after_gas'] = $trxBalance;
            }

            /**
             * TRX 足够后发送 TRC20。
             */
            if (bccomp($trxBalance, $config['min_trx_balance'], 6) >= 0) {
                $tokenHashes = [];
                $fromPrivateAddress = $trxWallet->privateKeyToAddress($privateKey);

                /**
                 * 归集 USDT。
                 */
                if ($needCollectUsdt) {
                    $energyResult = $this->rentEnergyIfConfigured($fromAddress);
                    $rowResult['energy_result_usdt'] = $energyResult;
                    $rowResult['energy_result'] = $energyResult;

                    sleep(2);

                    $trcSignedTransaction = $usdtWallet->transfer(
                        $fromPrivateAddress,
                        $allTo,
                        (float) $usdtBalance
                    );

                    $trcSend = $usdtWallet->send_transfer(
                        $trcSignedTransaction['tradeobj'],
                        $trcSignedTransaction['body']
                    );

                    $rowResult['usdt_hash'] = $trcSend->txID ?? null;
                    $tokenHashes['usdt'] = $rowResult['usdt_hash'];

                    $rowResult['status'] = 'usdt_sent';

                    sleep(3);

                    $trxBalance = $this->normalizeDecimal($trxWallet->balance($fromAddress), 6);
                    $rowResult['trx_balance_after_usdt'] = $trxBalance;
                }

                /**
                 * 归集 USDC。
                 */
                if ($needCollectUsdc) {
                    /**
                     * 如果刚发完 USDT 后 TRX 不够，再补一次。
                     */
                    if (bccomp($trxBalance, $config['min_trx_balance'], 6) < 0) {
                        $gasResultForUsdc = $this->fundTrxIfNeeded($fromAddress, $trxBalance);

                        $rowResult['gas_hash_for_usdc'] = $gasResultForUsdc['hash'] ?? null;
                        $rowResult['gas_result_for_usdc'] = $gasResultForUsdc;

                        sleep((int) $config['wait_seconds']);

                        $trxBalance = $this->normalizeDecimal($trxWallet->balance($fromAddress), 6);
                        $rowResult['trx_balance_after_usdc_gas'] = $trxBalance;
                    }

                    if (bccomp($trxBalance, $config['min_trx_balance'], 6) >= 0) {
                        $energyResult = $this->rentEnergyIfConfigured($fromAddress);
                        $rowResult['energy_result_usdc'] = $energyResult;
                        $rowResult['energy_result'] = $energyResult;

                        sleep(2);

                        $trcSignedTransaction = $usdcWallet->transfer(
                            $fromPrivateAddress,
                            $allTo,
                            (float) $usdcBalance
                        );

                        $trcSend = $usdcWallet->send_transfer(
                            $trcSignedTransaction['tradeobj'],
                            $trcSignedTransaction['body']
                        );

                        $rowResult['usdc_hash'] = $trcSend->txID ?? null;
                        $tokenHashes['usdc'] = $rowResult['usdc_hash'];

                        if ($rowResult['status'] === 'checked') {
                            $rowResult['status'] = 'usdc_sent';
                        } else {
                            $rowResult['status'] .= '_usdc_sent';
                        }

                        sleep(3);

                        $trxBalance = $this->normalizeDecimal($trxWallet->balance($fromAddress), 6);
                        $rowResult['trx_balance_after_usdc'] = $trxBalance;
                    } else {
                        if ($rowResult['status'] === 'checked') {
                            $rowResult['status'] = 'usdc_trx_gas_not_ready';
                        } else {
                            $rowResult['status'] .= '_usdc_trx_gas_not_ready';
                        }

                        if (!empty($tokenHashes)) {
                            $tokenHashSaveValue = $this->buildTokenHash($tokenHashes);

                            Db::name('deposits')
                                ->where('id', $uid)
                                ->update([
                                    'uhash' => $tokenHashSaveValue,
                                ]);

                            $rowResult['token_hash_save_value'] = $tokenHashSaveValue;
                        }

                        return $rowResult;
                    }
                }

                if (!empty($tokenHashes)) {
                    $tokenHashSaveValue = $this->buildTokenHash($tokenHashes);

                    Db::name('deposits')
                        ->where('id', $uid)
                        ->update([
                            'uhash' => $tokenHashSaveValue,
                            'type' => 1,
                        ]);

                    $rowResult['token_hash_save_value'] = $tokenHashSaveValue;
                }
            } else {
                $rowResult['status'] = 'trx_gas_sent_but_balance_not_ready';

                return $rowResult;
            }
        }

        /**
         * 2. 再归集 TRX。
         *
         * 保留 1.5 TRX，不全部转空。
         */
        if (bccomp($trxBalance, $config['trx_reserve_amount'], 6) > 0) {
            $sendTrxAmount = bcsub($trxBalance, $config['trx_reserve_amount'], 6);
            $sendTrxAmount = $this->normalizeDecimal($sendTrxAmount, 6);

            if (bccomp($sendTrxAmount, '0', 6) > 0) {
                $fromPrivateAddress = $trxWallet->privateKeyToAddress($privateKey);

                $signedTransaction = $trxWallet->transfer(
                    $fromPrivateAddress,
                    $allTo,
                    (float) $sendTrxAmount
                );

                $send = $trxWallet->send_transfer(
                    $signedTransaction['signedTransaction'],
                    $signedTransaction['transaction']
                );

                $rowResult['trx_hash'] = $send->txID ?? null;

                Db::name('deposits')
                    ->where('id', $uid)
                    ->update([
                        'ehash' => $rowResult['trx_hash'],
                        'type' => 1,
                    ]);

                if ($rowResult['status'] === 'checked') {
                    $rowResult['status'] = 'trx_sent';
                } else {
                    $rowResult['status'] .= '_trx_sent';
                }
            }
        }

        return $rowResult;
    }

    /**
     * 当前地址 TRX 不足时，从补 TRX 钱包转入 1.5 TRX。
     */
    private function fundTrxIfNeeded(string $targetAddress, string $currentTrxBalance): array
    {
        $config = $this->tronConfig();

        $currentTrxBalance = $this->normalizeDecimal($currentTrxBalance, 6);

        if (bccomp($currentTrxBalance, $config['min_trx_balance'], 6) >= 0) {
            return [
                'sent' => false,
                'hash' => null,
                'status' => 'trx_enough',
                'target_address' => $targetAddress,
                'target_balance' => $currentTrxBalance,
            ];
        }

        $gasPrivateKey = $this->gasWalletPrivateKey();

        $trxWallet = $this->makeTrxWallet();

        $gasAddressInfo = $trxWallet->privateKeyToAddress($gasPrivateKey);
        $gasFromAddress = $gasAddressInfo->address;

        $gasWalletBalance = $this->normalizeDecimal($trxWallet->balance($gasFromAddress), 6);

        /**
         * 补 TRX 钱包也要留一点手续费。
         */
        $needBalance = bcadd($config['gas_send_amount'], '1', 6);

        if (bccomp($gasWalletBalance, $needBalance, 6) <= 0) {
            throw new \Exception('补 TRX 钱包余额不足，当前余额：' . $gasWalletBalance);
        }

        $signedTransaction = $trxWallet->transfer(
            $gasAddressInfo,
            $targetAddress,
            (float) $config['gas_send_amount']
        );

        $send = $trxWallet->send_transfer(
            $signedTransaction['signedTransaction'],
            $signedTransaction['transaction']
        );

        return [
            'sent' => true,
            'hash' => $send->txID ?? null,
            'status' => 'trx_gas_sent',
            'gas_from_address' => $gasFromAddress,
            'target_address' => $targetAddress,
            'target_balance_before' => $currentTrxBalance,
            'gas_wallet_balance' => $gasWalletBalance,
            'send_amount' => $config['gas_send_amount'],
        ];
    }

    /**
     * 如果配置了 iTRX，就租能量。
     * 没配置或还是占位符就跳过，不影响主流程。
     */
    private function rentEnergyIfConfigured(string $to): array
    {
        $config = $this->itrxConfig();

        if (
            $this->isPlaceholderSecret($config['api_key']) ||
            $this->isPlaceholderSecret($config['api_secret'])
        ) {
            return [
                'enabled' => false,
                'status' => 'itrx_not_configured',
            ];
        }

        return $this->tr($to);
    }

    /**
     * iTRX 租能量
     */
    public function tr(string $to): array
    {
        $config = $this->itrxConfig();

        if (
            $this->isPlaceholderSecret($config['api_key']) ||
            $this->isPlaceholderSecret($config['api_secret'])
        ) {
            return [
                'success' => false,
                'message' => 'iTRX API KEY 或 SECRET 未配置',
            ];
        }

        $timestamp = time();

        $data = [
            'energy_amount' => $config['energy_amount'],
            'period' => $config['period'],
            'receive_address' => $to,
            'callback_url' => $config['callback_url'],
            'out_trade_no' => $timestamp . rand(1000, 99999),
        ];

        ksort($data);

        $jsonData = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $message = $timestamp . '&' . $jsonData;

        $signature = hash_hmac('sha256', $message, $config['api_secret']);

        $ch = curl_init("https://itrx.io/api/v1/frontend/order");

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "API-KEY: {$config['api_key']}",
                "TIMESTAMP: {$timestamp}",
                "SIGNATURE: {$signature}",
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $result = curl_exec($ch);

        if ($result === false) {
            $error = curl_error($ch);
            curl_close($ch);

            return [
                'success' => false,
                'message' => $error,
            ];
        }

        curl_close($ch);

        $json = json_decode($result, true);

        return [
            'success' => true,
            'raw' => $json ?: $result,
        ];
    }
}