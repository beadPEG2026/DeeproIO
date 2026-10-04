<?php

namespace app\service;

use Exception;
use Web3p\EthereumTx\Transaction;
use Elliptic\EC;
use kornrunner\Keccak;

class EthTransferService
{
    protected string $rpcUrl;
    protected int $chainId;
    protected string $fromAddress;
    protected string $privateKey;
    protected string $usdtContract;

    /**
     * @param string $rpcUrl RPC 链接
     * @param int $chainId 链ID，ETH主网=1，BSC=56
     * @param string $fromAddress 转出地址，可以传空。传空时自动从私钥解析
     * @param string $privateKey 私钥
     * @param string $usdtContract ERC20 合约地址，转 ETH 时可以传空
     * @param bool $strictAddressCheck 是否校验 fromAddress 必须和 privateKey 对应
     * @throws Exception
     */
    public function __construct(
        string $rpcUrl,
        int $chainId,
        string $fromAddress,
        string $privateKey,
        string $usdtContract = '',
        bool $strictAddressCheck = true
    ) {
        $this->rpcUrl = trim($rpcUrl);
        $this->chainId = $chainId;
        $this->privateKey = $this->cleanPrivateKey($privateKey);
        $this->usdtContract = trim($usdtContract);

        if (empty($this->rpcUrl)) {
            throw new Exception('RPC 链接不能为空');
        }

        if ($this->chainId <= 0) {
            throw new Exception('chain_id 错误');
        }

        /**
         * 通过私钥解析真实转出地址。
         */
        $privateKeyAddress = $this->privateKeyToAddress($this->privateKey);

        /**
         * 如果外部没有传 fromAddress，就自动使用私钥解析出来的地址。
         */
        if (trim($fromAddress) === '') {
            $this->fromAddress = $privateKeyAddress;
        } else {
            $this->fromAddress = $this->normalizeAddress($fromAddress);

            /**
             * 默认校验 fromAddress 必须和 privateKey 匹配。
             * 避免 nonce 用 A 地址，签名却是 B 私钥这种幽灵交易。
             */
            if (
                $strictAddressCheck &&
                strtolower($this->cleanHex($this->fromAddress)) !== strtolower($this->cleanHex($privateKeyAddress))
            ) {
                throw new Exception(
                    'fromAddress 和 privateKey 不匹配。fromAddress=' .
                    $this->fromAddress .
                    ', privateKeyAddress=' .
                    $privateKeyAddress
                );
            }
        }

        if (!empty($this->usdtContract)) {
            $this->usdtContract = $this->normalizeAddress($this->usdtContract);
        }
    }

    /**
     * 获取当前转出地址。
     */
    public function getFromAddress(): string
    {
        return $this->fromAddress;
    }

    /**
     * 通过私钥解析 EVM 地址。
     *
     * @throws Exception
     */
    public function privateKeyToAddress(string $privateKey): string
    {
        $privateKey = $this->cleanPrivateKey($privateKey);

        $ec = new EC('secp256k1');

        /**
         * 必须指定 hex。
         */
        $keyPair = $ec->keyFromPrivate($privateKey, 'hex');

        /**
         * false = 非压缩公钥
         * 格式通常是 04 + x + y。
         */
        $publicKey = $keyPair->getPublic(false, 'hex');

        /**
         * 去掉公钥开头的 04。
         */
        $publicKey = preg_replace('/^04/i', '', trim($publicKey));

        if (!preg_match('/^[a-fA-F0-9]{128}$/', $publicKey)) {
            throw new Exception('Invalid public key format: ' . $publicKey);
        }

        /**
         * Keccak256 公钥，取最后 20 字节，也就是最后 40 位 hex。
         */
        $hash = Keccak::hash(hex2bin($publicKey), 256);

        $address = '0x' . substr($hash, -40);

        return $this->toChecksumAddress($address);
    }

    /**
     * 转 ETH / BNB / MATIC 等原生币。
     *
     * @param string $to 接收地址
     * @param string $amountEth 原生币数量，例如 0.01
     * @return string
     * @throws Exception
     */
    public function sendEth(string $to, string $amountEth): string
    {
        $to = $this->normalizeAddress($to);

        $valueWei = $this->decimalToInteger($amountEth, 18);

        if (bccomp($valueWei, '0') <= 0) {
            throw new Exception('ETH 转账数量必须大于 0');
        }

        $tx = [
            'nonce'    => $this->getNonce(),
            'from'     => $this->fromAddress,
            'to'       => $to,
            'gas'      => $this->decToHex('21000'),
            'gasPrice' => $this->getGasPrice(),
            'value'    => $this->decToHex($valueWei),
            'chainId'  => $this->chainId,
            'data'     => '0x',
        ];

        return $this->signAndSend($tx);
    }

    /**
     * 转 ERC20 代币，例如 USDT。
     *
     * @param string $to 接收地址
     * @param string $amountUsdt 代币数量，例如 10.5
     * @param int $decimals 代币小数位，ETH 主网 USDT 一般是 6
     * @return string
     * @throws Exception
     */
    public function sendUsdt(string $to, string $amountUsdt, int $decimals = 6): string
    {
        $to = $this->normalizeAddress($to);

        if (empty($this->usdtContract)) {
            throw new Exception('USDT 合约地址不能为空');
        }

        $amountInteger = $this->decimalToInteger($amountUsdt, $decimals);

        if (bccomp($amountInteger, '0') <= 0) {
            throw new Exception('USDT 转账数量必须大于 0');
        }

        $data = $this->buildErc20TransferData($to, $amountInteger);

        $estimateTx = [
            'from'  => $this->fromAddress,
            'to'    => $this->usdtContract,
            'value' => '0x0',
            'data'  => $data,
        ];

        $gasLimit = $this->estimateGas($estimateTx, '100000');

        $tx = [
            'nonce'    => $this->getNonce(),
            'from'     => $this->fromAddress,
            'to'       => $this->usdtContract,
            'gas'      => $gasLimit,
            'gasPrice' => $this->getGasPrice(),
            'value'    => '0x0',
            'chainId'  => $this->chainId,
            'data'     => $data,
        ];

        return $this->signAndSend($tx);
    }

    /**
     * 查询原生币余额。
     * ETH 链查 ETH，BSC 链查 BNB，Polygon 查 MATIC。
     *
     * @param string $address 钱包地址
     * @return string
     * @throws Exception
     */
    public function getNativeBalance(string $address): string
    {
        $address = $this->normalizeAddress($address);

        $balanceHex = $this->rpc('eth_getBalance', [
            $address,
            'latest',
        ]);

        $balanceWei = $this->hexToDec($balanceHex);

        return $this->integerToDecimal($balanceWei, 18);
    }

    /**
     * 查询 ERC20 代币余额，例如 USDT。
     *
     * @param string $address 钱包地址
     * @param int $decimals 代币小数位
     * @return string
     * @throws Exception
     */
    public function getTokenBalance(string $address, int $decimals = 6): string
    {
        $address = $this->normalizeAddress($address);

        if (empty($this->usdtContract)) {
            throw new Exception('代币合约地址不能为空');
        }

        $data = $this->buildErc20BalanceOfData($address);

        $result = $this->rpc('eth_call', [
            [
                'to'   => $this->usdtContract,
                'data' => $data,
            ],
            'latest',
        ]);

        $balanceInteger = $this->hexToDec($result);

        return $this->integerToDecimal($balanceInteger, $decimals);
    }

    /**
     * 查询交易回执。
     *
     * status:
     * 0x1 = 成功
     * 0x0 = 失败
     * null = 还没打包
     *
     * @param string $txHash
     * @return array
     * @throws Exception
     */
    public function getTransactionReceipt(string $txHash): array
    {
        if (!preg_match('/^0x[a-fA-F0-9]{64}$/', $txHash)) {
            throw new Exception('交易 hash 格式错误');
        }

        $receipt = $this->rpc('eth_getTransactionReceipt', [
            $txHash,
        ]);

        if (empty($receipt)) {
            return [
                'confirmed' => false,
                'success'   => false,
                'status'    => null,
                'msg'       => '交易未打包或不存在',
                'raw'       => null,
            ];
        }

        $status = $receipt['status'] ?? null;

        return [
            'confirmed' => true,
            'success'   => $status === '0x1',
            'status'    => $status,
            'msg'       => $status === '0x1' ? '交易成功' : '交易失败',
            'raw'       => $receipt,
        ];
    }

    /**
     * ERC20 balanceOf(address)。
     */
    protected function buildErc20BalanceOfData(string $address): string
    {
        $address = $this->normalizeAddress($address);

        $methodId = '70a08231';

        $addressHex = strtolower($this->cleanHex($address));
        $addressParam = str_pad($addressHex, 64, '0', STR_PAD_LEFT);

        return '0x' . $methodId . $addressParam;
    }

    /**
     * ERC20 transfer(address,uint256)。
     */
    protected function buildErc20TransferData(string $to, string $amountInteger): string
    {
        $to = $this->normalizeAddress($to);

        $methodId = 'a9059cbb';

        $toHex = strtolower($this->cleanHex($to));
        $toParam = str_pad($toHex, 64, '0', STR_PAD_LEFT);

        $amountHex = $this->cleanHex($this->decToHex($amountInteger));
        $amountParam = str_pad($amountHex, 64, '0', STR_PAD_LEFT);

        return '0x' . $methodId . $toParam . $amountParam;
    }

    /**
     * 签名并广播交易。
     *
     * @throws Exception
     */
    protected function signAndSend(array $tx): string
    {
        $transaction = new Transaction($tx);

        $signedTx = $transaction->sign($this->privateKey);

        $rawTx = '0x' . $this->cleanHex($signedTx);

        return $this->rpc('eth_sendRawTransaction', [$rawTx]);
    }

    /**
     * 获取 nonce。
     *
     * @throws Exception
     */
    protected function getNonce(): string
    {
        return $this->rpc('eth_getTransactionCount', [
            $this->fromAddress,
            'pending',
        ]);
    }

    /**
     * 获取 gas price。
     *
     * @throws Exception
     */
    protected function getGasPrice(): string
    {
        return $this->rpc('eth_gasPrice', []);
    }

    /**
     * 估算 gas。
     */
    protected function estimateGas(array $tx, string $fallbackGas): string
    {
        try {
            $gasHex = $this->rpc('eth_estimateGas', [$tx]);
            $gasDec = $this->hexToDec($gasHex);

            /**
             * 加 20% buffer。
             */
            $gasDec = bcdiv(bcmul($gasDec, '12'), '10', 0);

            return $this->decToHex($gasDec);
        } catch (\Throwable $e) {
            return $this->decToHex($fallbackGas);
        }
    }

    /**
     * JSON-RPC 请求。
     *
     * @throws Exception
     */
    protected function rpc(string $method, array $params = [])
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id'      => time(),
            'method'  => $method,
            'params'  => $params,
        ];

        $ch = curl_init($this->rpcUrl);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception('RPC 请求失败：' . $error);
        }

        curl_close($ch);

        $json = json_decode($response, true);

        if (!is_array($json)) {
            throw new Exception('RPC 返回格式错误：' . $response);
        }

        if (isset($json['error'])) {
            $message = $json['error']['message'] ?? json_encode($json['error'], JSON_UNESCAPED_UNICODE);
            throw new Exception('RPC 错误：' . $message);
        }

        if (!array_key_exists('result', $json)) {
            throw new Exception('RPC 缺少 result：' . $response);
        }

        return $json['result'];
    }

    /**
     * 小数转最小单位整数。
     *
     * 例：
     * 1 ETH, 18 => 1000000000000000000
     * 1 USDT, 6 => 1000000
     *
     * @throws Exception
     */
    protected function decimalToInteger(string $amount, int $decimals): string
    {
        $amount = trim((string) $amount);

        if (!preg_match('/^\d+(\.\d+)?$/', $amount)) {
            throw new Exception('金额格式错误');
        }

        $parts = explode('.', $amount, 2);

        $whole = $parts[0] ?? '0';
        $fraction = $parts[1] ?? '';

        if (strlen($fraction) > $decimals) {
            throw new Exception('金额小数位不能超过 ' . $decimals . ' 位');
        }

        $fraction = str_pad($fraction, $decimals, '0', STR_PAD_RIGHT);

        $integer = ltrim($whole . $fraction, '0');

        return $integer === '' ? '0' : $integer;
    }

    /**
     * 最小单位整数转正常小数。
     *
     * 例：
     * 1000000000000000000, 18 => 1
     * 1000000, 6 => 1
     */
    protected function integerToDecimal(string $integer, int $decimals): string
    {
        $integer = ltrim($integer, '0');

        if ($integer === '') {
            return '0';
        }

        if ($decimals <= 0) {
            return $integer;
        }

        if (strlen($integer) <= $decimals) {
            $integer = str_pad($integer, $decimals + 1, '0', STR_PAD_LEFT);
        }

        $whole = substr($integer, 0, strlen($integer) - $decimals);
        $fraction = substr($integer, -$decimals);

        $fraction = rtrim($fraction, '0');

        if ($fraction === '') {
            return $whole;
        }

        return $whole . '.' . $fraction;
    }

    /**
     * 十进制转 16 进制。
     */
    protected function decToHex(string $dec): string
    {
        $dec = ltrim($dec, '0');

        if ($dec === '') {
            return '0x0';
        }

        $hex = '';

        while (bccomp($dec, '0') > 0) {
            $mod = bcmod($dec, '16');
            $hex = dechex((int) $mod) . $hex;
            $dec = bcdiv($dec, '16', 0);
        }

        return '0x' . $hex;
    }

    /**
     * 16 进制转十进制。
     */
    protected function hexToDec(string $hex): string
    {
        $hex = strtolower($this->cleanHex($hex));

        if ($hex === '') {
            return '0';
        }

        $dec = '0';

        for ($i = 0; $i < strlen($hex); $i++) {
            $dec = bcadd(bcmul($dec, '16'), (string) hexdec($hex[$i]));
        }

        return $dec;
    }

    /**
     * 去掉开头 0x / 0X。
     */
    protected function cleanHex(string $hex): string
    {
        return preg_replace('/^0x/i', '', trim((string) $hex));
    }

    /**
     * 清理私钥：
     * 支持 0x 开头，自动去掉空格、换行、引号等。
     *
     * @throws Exception
     */
    protected function cleanPrivateKey(string $privateKey): string
    {
        $privateKey = trim((string) $privateKey);
        $privateKey = trim($privateKey, "\"'");
        $privateKey = preg_replace('/\s+/', '', $privateKey);
        $privateKey = str_replace(["\0", "\r", "\n", "\t"], '', $privateKey);
        $privateKey = $this->cleanHex($privateKey);

        if (!preg_match('/^[a-fA-F0-9]{64}$/', $privateKey)) {
            throw new Exception('私钥格式错误，长度=' . strlen($privateKey));
        }

        return strtolower($privateKey);
    }

    /**
     * 地址标准化：
     * 支持传入 0x 开头或不带 0x 的地址。
     * 最后统一返回 EIP-55 checksum 地址。
     *
     * @throws Exception
     */
    protected function normalizeAddress(string $address): string
    {
        $address = trim((string) $address);

        if ($address === '') {
            throw new Exception('钱包地址不能为空');
        }

        $addressHex = $this->cleanHex($address);

        if (!preg_match('/^[a-fA-F0-9]{40}$/', $addressHex)) {
            throw new Exception('钱包地址格式错误：' . $address);
        }

        return $this->toChecksumAddress('0x' . $addressHex);
    }

    /**
     * 校验地址。
     *
     * @throws Exception
     */
    protected function validateAddress(string $address): void
    {
        $this->normalizeAddress($address);
    }

    /**
     * 转成 EIP-55 checksum 地址。
     *
     * @throws Exception
     */
    protected function toChecksumAddress(string $address): string
    {
        $address = strtolower($this->cleanHex($address));

        if (!preg_match('/^[a-f0-9]{40}$/', $address)) {
            throw new Exception('地址格式错误');
        }

        $hash = Keccak::hash($address, 256);

        $checksumAddress = '';

        for ($i = 0; $i < 40; $i++) {
            if (hexdec($hash[$i]) >= 8) {
                $checksumAddress .= strtoupper($address[$i]);
            } else {
                $checksumAddress .= $address[$i];
            }
        }

        return '0x' . $checksumAddress;
    }
}