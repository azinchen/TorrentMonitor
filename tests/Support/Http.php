<?php
// Minimal HTTP client for tests.
class Http
{
    // returns array('status' => int, 'body' => string, 'headers' => string, 'json' => mixed)
    public static function request($method, $url, $options = array())
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => isset($options['timeout']) ? $options['timeout'] : 60,
        ));
        if (isset($options['body']))
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($options['body']) ? http_build_query($options['body']) : $options['body']);
        if (isset($options['headers']))
            curl_setopt($ch, CURLOPT_HTTPHEADER, $options['headers']);
        if (isset($options['jar']))
        {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $options['jar']);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $options['jar']);
        }
        if (isset($options['auth']))
            curl_setopt($ch, CURLOPT_USERPWD, $options['auth']);
        $raw = curl_exec($ch);
        if ($raw === false)
        {
            $error = curl_error($ch);
            throw new RuntimeException($method.' '.$url.' failed: '.$error);
        }
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $body = substr($raw, $headerSize);
        return array(
            'status'  => $status,
            'headers' => substr($raw, 0, $headerSize),
            'body'    => $body,
            'json'    => json_decode($body, true),
        );
    }

    public static function get($url, $options = array())
    {
        return self::request('GET', $url, $options);
    }

    public static function post($url, $body = '', $options = array())
    {
        return self::request('POST', $url, array('body' => $body) + $options);
    }

    public static function postJson($url, $data, $options = array())
    {
        $headers = isset($options['headers']) ? $options['headers'] : array();
        $headers[] = 'Content-Type: application/json';
        return self::request('POST', $url, array('body' => json_encode($data), 'headers' => $headers) + $options);
    }
}
