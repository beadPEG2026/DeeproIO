<?php
// 应用公共文件
function curl($url){
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FAILONERROR, false);
    curl_setopt($ch, CURLOPT_HEADER,0);//设置为0、1控制是否返回请求头信息
    curl_setopt($ch, CURLOPT_NOBODY,0);
    //是否HTTPS
    if (1 == strpos("$".$url, "https://")){
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/111.0.0.0 Safari/537.36'));
    //获取结果
    $output = curl_exec($ch);
    curl_close($ch);  //关闭
    return $output;
}
function laravel_decrypt($payload, $appKey, $unserialize = false, $cipher = 'AES-256-CBC')
    {
        // 处理 APP_KEY=base64:xxxx
        if (strpos($appKey, 'base64:') === 0) {
            $key = base64_decode(substr($appKey, 7));
        } else {
            $key = $appKey;
        }

        if (!$key) {
            throw new Exception('APP_KEY 无效');
        }

        // Laravel 加密结果本身是 base64(json)
        $json = base64_decode($payload, true);

        if ($json === false) {
            throw new Exception('加密内容 base64 解析失败');
        }

        $data = json_decode($json, true);

        if (
            !is_array($data) ||
            !isset($data['iv']) ||
            !isset($data['value']) ||
            !isset($data['mac'])
        ) {
            throw new Exception('不是有效的 Laravel 加密格式');
        }

        $iv = base64_decode($data['iv']);

        if (!$iv) {
            throw new Exception('IV 解析失败');
        }

        // 校验 MAC，防止数据被篡改
        $calcMac = hash_hmac('sha256', $data['iv'] . $data['value'], $key);

        if (!hash_equals($calcMac, $data['mac'])) {
            throw new Exception('MAC 校验失败，APP_KEY 可能不对，或者数据被修改过');
        }

        $decrypted = openssl_decrypt(
            $data['value'],
            $cipher,
            $key,
            0,
            $iv
        );

        if ($decrypted === false) {
            throw new Exception('解密失败');
        }

        if ($unserialize) {
            return unserialize($decrypted);
        }

        return $decrypted;
    }