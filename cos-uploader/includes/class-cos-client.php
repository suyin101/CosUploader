<?php
/**
 * 腾讯云 COS XML API 的最小实现：上传、查询、删除，使用 V5 请求签名。
 * 签名算法：https://cloud.tencent.com/document/product/436/7778
 */

if (!defined('ABSPATH')) {
    exit;
}

class Cos_Uploader_Client
{
    private $secret_id;
    private $secret_key;
    private $region;
    private $bucket;

    public function __construct($secret_id, $secret_key, $region, $bucket)
    {
        $this->secret_id = (string) $secret_id;
        $this->secret_key = (string) $secret_key;
        $this->region = (string) $region;
        $this->bucket = (string) $bucket;
    }

    public function host()
    {
        return $this->bucket . '.cos.' . $this->region . '.myqcloud.com';
    }

    /* ---------------- 签名 ---------------- */

    private function sign($method, $path, array $headers, array $params = [], $key_time = null)
    {
        if ($key_time === null) {
            $now = time() - 60; // 容忍服务器时间略有偏差
            $key_time = $now . ';' . ($now + 3600);
        }
        $sign_key = hash_hmac('sha1', $key_time, $this->secret_key);

        list($param_list, $param_str) = $this->sign_pairs($params);
        list($header_list, $header_str) = $this->sign_pairs($headers);

        $http_string = strtolower($method) . "\n" . $path . "\n" . $param_str . "\n" . $header_str . "\n";
        $string_to_sign = "sha1\n" . $key_time . "\n" . sha1($http_string) . "\n";
        $signature = hash_hmac('sha1', $string_to_sign, $sign_key);

        return 'q-sign-algorithm=sha1'
            . '&q-ak=' . $this->secret_id
            . '&q-sign-time=' . $key_time
            . '&q-key-time=' . $key_time
            . '&q-header-list=' . $header_list
            . '&q-url-param-list=' . $param_list
            . '&q-signature=' . $signature;
    }

    private function sign_pairs(array $pairs)
    {
        $encoded = [];
        foreach ($pairs as $k => $v) {
            $encoded[strtolower(rawurlencode($k))] = rawurlencode((string) $v);
        }
        ksort($encoded, SORT_STRING);
        $kv = [];
        foreach ($encoded as $k => $v) {
            $kv[] = $k . '=' . $v;
        }
        return [implode(';', array_keys($encoded)), implode('&', $kv)];
    }

    /** 生成请求地址和带签名的请求头 */
    private function prepare($method, $key, $content_type = '')
    {
        $path = '/' . ltrim($key, '/');
        $headers = ['Host' => $this->host()];
        if ($content_type !== '') {
            $headers['Content-Type'] = $content_type;
        }
        $headers['Authorization'] = $this->sign($method, $path, $headers);
        unset($headers['Host']); // Host 只参与签名，实际请求时自动带上

        $encoded_path = implode('/', array_map('rawurlencode', explode('/', $path)));
        // 开发测试用：可以把请求指向本地模拟服务器
        $endpoint = apply_filters('cos_uploader_endpoint', 'https://' . $this->host());
        return [$endpoint . $encoded_path, $headers];
    }

    private function request($method, $key, $body = null, $content_type = '')
    {
        list($url, $headers) = $this->prepare($method, $key, $content_type);
        $res = wp_remote_request($url, [
            'method'      => $method,
            'headers'     => $headers,
            'body'        => $body,
            'timeout'     => 60,
            'redirection' => 0,
        ]);
        if (is_wp_error($res)) {
            return new WP_Error('cos_network', '连不上 COS：' . $res->get_error_message());
        }
        return [
            'code' => (int) wp_remote_retrieve_response_code($res),
            'body' => (string) wp_remote_retrieve_body($res),
            'size' => wp_remote_retrieve_header($res, 'content-length'),
        ];
    }

    /** 边读边传，大文件（视频等）也不会占满内存 */
    private function put_stream($local, $key, $mime)
    {
        list($url, $headers) = $this->prepare('PUT', $key, $mime);
        $lines = ['Expect:'];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        $fh = fopen($local, 'rb');
        if (!$fh) {
            return new WP_Error('cos_read', '无法读取本地文件：' . $local);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_PUT            => true,
            CURLOPT_INFILE         => $fh,
            CURLOPT_INFILESIZE     => filesize($local),
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch); // PHP 8 起会自动释放，新版本里调用会报弃用提示
        }
        fclose($fh);
        if ($body === false) {
            return new WP_Error('cos_network', '连不上 COS：' . $err);
        }
        return ['code' => $code, 'body' => (string) $body, 'size' => ''];
    }

    /* ---------------- 对外方法 ---------------- */

    /** @return true|WP_Error */
    public function put_file($local, $key)
    {
        if (!is_readable($local)) {
            return new WP_Error('cos_missing', '本地文件不存在：' . $local);
        }
        $type = wp_check_filetype($local);
        $mime = $type['type'] ? $type['type'] : 'application/octet-stream';
        $res = function_exists('curl_init')
            ? $this->put_stream($local, $key, $mime)
            : $this->request('PUT', $key, file_get_contents($local), $mime);
        if (is_wp_error($res)) {
            return $res;
        }
        if ($res['code'] !== 200) {
            return new WP_Error('cos_put', self::explain($res));
        }
        return true;
    }

    /**
     * 存储桶里这个文件的大小。
     * @return int|false|null|WP_Error 有：文件大小；没有：false；没权限查询：null；网络错误：WP_Error
     */
    public function size($key)
    {
        $res = $this->request('HEAD', $key);
        if (is_wp_error($res)) {
            return $res;
        }
        if ($res['code'] === 200 && is_numeric($res['size'])) {
            return (int) $res['size'];
        }
        if ($res['code'] === 404) {
            return false;
        }
        return null;
    }

    /** @return true|WP_Error */
    public function delete($key)
    {
        $res = $this->request('DELETE', $key);
        if (is_wp_error($res)) {
            return $res;
        }
        if ($res['code'] === 204 || $res['code'] === 200 || $res['code'] === 404) {
            return true;
        }
        return new WP_Error('cos_delete', self::explain($res));
    }

    /** 把 COS 的错误码翻译成看得懂的话 */
    public static function explain($res)
    {
        $code = '';
        $message = '';
        if (preg_match('#<Code>(.*?)</Code>#s', $res['body'], $m)) {
            $code = $m[1];
        }
        if (preg_match('#<Message>(.*?)</Message>#s', $res['body'], $m)) {
            $message = $m[1];
        }
        $map = [
            'InvalidAccessKeyId'    => 'SecretId 不对或已被删除',
            'SignatureDoesNotMatch' => 'SecretKey 不对（注意不要多复制空格）',
            'NoSuchBucket'          => '存储桶不存在，检查存储桶名称和地域是否对应',
            'AccessDenied'          => '这个密钥没有该存储桶的权限',
            'RequestTimeTooSkewed'  => '服务器时间不准，请校准服务器时间',
            'EntityTooLarge'        => '文件太大，超过了 5GB',
        ];
        $text = isset($map[$code]) ? $map[$code] : ($message !== '' ? $message : '请求失败');
        return $text . '（HTTP ' . $res['code'] . ($code !== '' ? ' ' . $code : '') . '）';
    }
}
