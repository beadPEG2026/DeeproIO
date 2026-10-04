<?php

namespace app\controller;

use app\BaseController;
use think\facade\Db;
use think\facade\Env;
use app\service\EthTransferService;

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
     * ETH 主网配置
     */
    private function ethMainnetConfig(): array
    {
        return [
            'rpc_url' => 'https://mainnet.infura.io/v3/9d115a630b214d2bb7c4461d0feda8cd',
            'chain_id' => 1,

            // ETH 主网 USDT
            'usdt_contract' => '0xdAC17F958D2ee523a2206206994597C13D831ec7',

            // ETH 主网 USDC
            'usdc_contract' => '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48',
        ];
    }

    /**
     * 补 gas 钱包配置
     *
     * private_key 这里直接填写补 ETH 钱包私钥。
     */
    private function gasWalletConfig(): array
    {
        return [
            'private_key' => env('DEEPRO_GAS_PRIVATE_KEY', ''),
            'min_balance' => '0.001',
            'send_amount' => '0.001',
            'reserve_amount' => '0.001',
            'wait_seconds' => 10,
        ];
    }

    /**
     * 兼容 ThinkPHP env / Env / getenv
     */
    private function readEnv(string $key, string $default = ''): string
    {
        $value = '';

        if (function_exists('env')) {
            $value = env($key, $default);
        }

        if (empty($value)) {
            $value = Env::get($key, $default);
        }

        if (empty($value)) {
            $value = getenv($key) ?: $default;
        }

        return trim((string) $value);
    }

    /**
     * 创建 ETH / ERC20 服务
     *
     * @param string $type eth / usdt / usdc
     * @param string $privateKey 私钥
     * @param string $fromAddress 转出地址，可以传空，传空会自动从私钥解析
     * @return EthTransferService
     * @throws \Exception
     */
    private function makeService(string $type, string $privateKey, string $fromAddress = ''): EthTransferService
    {
        $config = $this->ethMainnetConfig();

        $rpcUrl = $config['rpc_url'];
        $chainId = (int) $config['chain_id'];
        $tokenContract = '';

        if ($type === 'usdt') {
            $tokenContract = $config['usdt_contract'];
        }

        if ($type === 'usdc') {
            $tokenContract = $config['usdc_contract'];
        }

        if (empty($rpcUrl)) {
            throw new \Exception('RPC 链接不能为空');
        }

        if (in_array($type, ['usdt', 'usdc'], true) && empty($tokenContract)) {
            throw new \Exception(strtoupper($type) . ' 合约地址不能为空');
        }

        return new EthTransferService(
            $rpcUrl,
            $chainId,
            $fromAddress,
            $privateKey,
            $tokenContract
        );
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
     * 清理私钥
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
     * 规范小数字符串，避免科学计数法影响 bccomp。
     */
    private function normalizeDecimal($value, int $scale = 18): string
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
     * 组合 ERC20 hash。
     *
     * 如果只归集了一个币，直接保存 hash，兼容你原来的 uhash。
     * 如果同时归集 USDT + USDC，保存为：
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
     * 当前地址 gas 不足时，从补 gas 钱包转入 0.001 ETH。
     */
    private function fundGasIfNeeded(string $targetAddress, string $currentNativeBalance): array
    {
        $config = $this->gasWalletConfig();

        $minBalance = $config['min_balance'];
        $sendAmount = $config['send_amount'];

        $currentNativeBalance = $this->normalizeDecimal($currentNativeBalance, 18);

        if (bccomp($currentNativeBalance, $minBalance, 18) >= 0) {
            return [
                'sent' => false,
                'hash' => null,
                'status' => 'gas_enough',
                'target_address' => $targetAddress,
                'target_balance' => $currentNativeBalance,
            ];
        }

        if (empty($config['private_key'])) {
            throw new \Exception('补 gas 私钥不能为空，请在 gasWalletConfig() 里面填写 private_key');
        }

        $gasPrivateKey = $this->cleanPrivateKey($config['private_key']);

        $gasService = $this->makeService('eth', $gasPrivateKey, '');

        $gasFromAddress = $gasService->getFromAddress();
        $gasWalletBalance = $this->normalizeDecimal($gasService->getNativeBalance($gasFromAddress), 18);

        /**
         * 补 gas 钱包自己也要留一点 gas，所以这里判断要大于 send_amount。
         */
        if (bccomp($gasWalletBalance, $sendAmount, 18) <= 0) {
            throw new \Exception('补 gas 钱包 ETH 余额不足，当前余额：' . $gasWalletBalance);
        }

        $gasHash = $gasService->sendEth($targetAddress, $sendAmount);

        return [
            'sent' => true,
            'hash' => $gasHash,
            'status' => 'gas_sent',
            'gas_from_address' => $gasFromAddress,
            'target_address' => $targetAddress,
            'target_balance_before' => $currentNativeBalance,
            'gas_wallet_balance' => $gasWalletBalance,
            'send_amount' => $sendAmount,
        ];
    }

    /**
     * ETH / ERC20 归集
     *
     * network_id 2,3 这里是 ETH / ERC20。
     *
     * 当前会归集：
     * 1. USDT ERC20
     * 2. USDC ERC20
     * 3. ETH
     *
     * 重要：
     * $to 必须是你的归集地址。
     * 如果 deposits.address 是用户充值地址，不要用它当 $to。
     */
    public function sendEth()
    {
        $list = Db::name('deposits')
            ->whereIn('network_id', [2, 3])
            ->where('type', 0)
            ->select();

        $results = [];

        foreach ($list as $value) {
            try {
                $id = $value['id'];

                /**
                 * 解密用户充值地址私钥
                 */
                $privateKey = $this->decryptPrivateKey($value['private_key']);

                /**
                 * 这里要改成你的平台归集地址。
                 *
                 * 如果 $value['address'] 是用户充值地址，这里不能用它。
                 * 可以直接写死：
                 * $to = '0x你的归集钱包地址';
                 */
                $to = trim((string) $value['address']);

                if (empty($to)) {
                    throw new \Exception('归集地址不能为空');
                }

                /**
                 * 创建服务，fromAddress 传空，自动从私钥解析地址。
                 */
                $ethService = $this->makeService('eth', $privateKey, '');
                $usdtService = $this->makeService('usdt', $privateKey, '');
                $usdcService = $this->makeService('usdc', $privateKey, '');

                /**
                 * 从私钥解析出来的真实转出地址
                 */
                $fromAddress = $ethService->getFromAddress();

                /**
                 * ETH 主网 USDT decimals = 6
                 * ETH 主网 USDC decimals = 6
                 */
                $tokenDecimals = 6;

                /**
                 * gas 配置
                 */
                $gasConfig = $this->gasWalletConfig();
                $minGasBalance = $gasConfig['min_balance'];
                $nativeReserve = $gasConfig['reserve_amount'];

                /**
                 * 查询余额
                 */
                $ethBalance = $this->normalizeDecimal($ethService->getNativeBalance($fromAddress), 18);
                $usdtBalance = $this->normalizeDecimal($usdtService->getTokenBalance($fromAddress, $tokenDecimals), $tokenDecimals);
                $usdcBalance = $this->normalizeDecimal($usdcService->getTokenBalance($fromAddress, $tokenDecimals), $tokenDecimals);

                $rowResult = [
                    'id' => $id,
                    'from_address' => $fromAddress,
                    'to_address' => $to,
                    'eth_balance' => $ethBalance,
                    'usdt_balance' => $usdtBalance,
                    'usdc_balance' => $usdcBalance,
                    'gas_hash' => null,
                    'eth_hash' => null,
                    'usdt_hash' => null,
                    'usdc_hash' => null,
                    'token_hash_save_value' => null,
                    'status' => 'checked',
                ];

                /**
                 * 如果 ETH、USDT、USDC 都达不到转账标准：
                 *
                 * USDT <= 1
                 * USDC <= 1
                 * ETH <= 0.001
                 *
                 * 直接标记完成，避免下次继续重复扫描。
                 */
                if (
                    bccomp($usdtBalance, '1', $tokenDecimals) <= 0 &&
                    bccomp($usdcBalance, '1', $tokenDecimals) <= 0 &&
                    bccomp($ethBalance, $nativeReserve, 18) <= 0
                ) {
                    Db::name('deposits')
                        ->where('id', $id)
                        ->update([
                            'uhash' => 'success',
                            'ehash' => 'success',
                            'type' => 1,
                        ]);

                    $rowResult['uhash'] = 'success';
                    $rowResult['ehash'] = 'success';
                    $rowResult['status'] = 'no_collectable_balance_marked_success';

                    $results[] = $rowResult;

                    sleep(1);
                    continue;
                }

                /**
                 * 1. 先归集 ERC20：USDT / USDC。
                 *
                 * 如果 USDT 或 USDC > 1，并且 ETH < 0.001：
                 * 先从补 gas 钱包给当前地址转入 0.001 ETH。
                 */
                $needCollectUsdt = bccomp($usdtBalance, '1', $tokenDecimals) > 0;
                $needCollectUsdc = bccomp($usdcBalance, '1', $tokenDecimals) > 0;

                if ($needCollectUsdt || $needCollectUsdc) {
                    if (bccomp($ethBalance, $minGasBalance, 18) < 0) {
                        $gasResult = $this->fundGasIfNeeded($fromAddress, $ethBalance);

                        $rowResult['gas_hash'] = $gasResult['hash'] ?? null;
                        $rowResult['gas_result'] = $gasResult;
                        $rowResult['status'] = 'gas_sent_waiting_confirm';

                        /**
                         * 等待补 gas 交易确认几秒，再重新查询 ETH 余额。
                         */
                        sleep((int) $gasConfig['wait_seconds']);

                        $ethBalance = $this->normalizeDecimal($ethService->getNativeBalance($fromAddress), 18);
                        $rowResult['eth_balance_after_gas'] = $ethBalance;
                    }

                    /**
                     * gas 足够后再发送 ERC20。
                     */
                    if (bccomp($ethBalance, $minGasBalance, 18) >= 0) {
                        $tokenHashes = [];

                        if ($needCollectUsdt) {
                            $usdtHash = $usdtService->sendUsdt($to, $usdtBalance, $tokenDecimals);

                            $tokenHashes['usdt'] = $usdtHash;
                            $rowResult['usdt_hash'] = $usdtHash;
                            $rowResult['status'] = 'usdt_sent';

                            /**
                             * USDT 发出后，稍微等一下，避免连续 nonce 太快。
                             */
                            sleep(3);

                            $ethBalance = $this->normalizeDecimal($ethService->getNativeBalance($fromAddress), 18);
                            $rowResult['eth_balance_after_usdt'] = $ethBalance;
                        }

                        if ($needCollectUsdc) {
                            /**
                             * 如果刚发完 USDT 后 ETH gas 已经不够，再补一次 gas。
                             */
                            if (bccomp($ethBalance, $minGasBalance, 18) < 0) {
                                $gasResultForUsdc = $this->fundGasIfNeeded($fromAddress, $ethBalance);

                                $rowResult['gas_hash_for_usdc'] = $gasResultForUsdc['hash'] ?? null;
                                $rowResult['gas_result_for_usdc'] = $gasResultForUsdc;

                                sleep((int) $gasConfig['wait_seconds']);

                                $ethBalance = $this->normalizeDecimal($ethService->getNativeBalance($fromAddress), 18);
                                $rowResult['eth_balance_after_usdc_gas'] = $ethBalance;
                            }

                            if (bccomp($ethBalance, $minGasBalance, 18) >= 0) {
                                /**
                                 * EthTransferService 里面的方法虽然叫 sendUsdt，
                                 * 但只要 service 初始化传入的是 USDC 合约，
                                 * 这里实际发送的就是 USDC。
                                 */
                                $usdcHash = $usdcService->sendUsdt($to, $usdcBalance, $tokenDecimals);

                                $tokenHashes['usdc'] = $usdcHash;
                                $rowResult['usdc_hash'] = $usdcHash;

                                if ($rowResult['status'] === 'checked') {
                                    $rowResult['status'] = 'usdc_sent';
                                } else {
                                    $rowResult['status'] .= '_usdc_sent';
                                }

                                sleep(3);

                                $ethBalance = $this->normalizeDecimal($ethService->getNativeBalance($fromAddress), 18);
                                $rowResult['eth_balance_after_usdc'] = $ethBalance;
                            } else {
                                $rowResult['status'] .= '_usdc_gas_not_ready';
                            }
                        }

                        if (!empty($tokenHashes)) {
                            $tokenHashSaveValue = $this->buildTokenHash($tokenHashes);

                            Db::name('deposits')
                                ->where('id', $id)
                                ->update([
                                    'uhash' => $tokenHashSaveValue,
                                    'type' => 1,
                                ]);

                            $rowResult['token_hash_save_value'] = $tokenHashSaveValue;
                        }
                    } else {
                        /**
                         * 如果补 gas 交易还没确认，本轮不归集 ERC20。
                         * 下次再次执行 sendEth 会继续检测。
                         */
                        $rowResult['status'] = 'gas_sent_but_balance_not_ready';
                        $results[] = $rowResult;

                        sleep(1);
                        continue;
                    }
                }

                /**
                 * 2. 再归集 ETH。
                 *
                 * 保留 0.001 ETH，不会全部转空。
                 */
                if (bccomp($ethBalance, $nativeReserve, 18) > 0) {
                    $sendNativeAmount = bcsub($ethBalance, $nativeReserve, 18);
                    $sendNativeAmount = $this->normalizeDecimal($sendNativeAmount, 18);

                    if (bccomp($sendNativeAmount, '0', 18) > 0) {
                        $ethHash = $ethService->sendEth($to, $sendNativeAmount);

                        Db::name('deposits')
                            ->where('id', $id)
                            ->update([
                                'ehash' => $ethHash,
                                'type' => 1,
                            ]);

                        $rowResult['eth_hash'] = $ethHash;

                        if ($rowResult['status'] === 'checked') {
                            $rowResult['status'] = 'native_sent';
                        } else {
                            $rowResult['status'] .= '_native_sent';
                        }
                    }
                }

                $results[] = $rowResult;
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
     * 只测试私钥解析地址
     */
    public function testPrivateKeyAddress()
    {
        $row = Db::name('deposits')
            ->whereIn('network_id', [2, 3])
            ->where('type', 0)
            ->find();

        if (!$row) {
            return json([
                'code' => 0,
                'msg' => '没有找到数据',
            ]);
        }

        try {
            $privateKey = $this->decryptPrivateKey($row['private_key']);

            $service = $this->makeService('eth', $privateKey, '');

            return json([
                'code' => 1,
                'msg' => '解析成功',
                'private_key_length' => strlen($privateKey),
                'from_address' => $service->getFromAddress(),
            ]);
        } catch (\Throwable $e) {
            return json([
                'code' => 0,
                'msg' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }
}