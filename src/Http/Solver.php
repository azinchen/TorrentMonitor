<?php
namespace TorrentMonitor\Http;

//Решатель проверок Cloudflare с API FlareSolverr (FlareSolverr, Byparr).
final class Solver
{
    private string $url;
    private int $maxTimeout;

    public function __construct(string $url, int $maxTimeout = 120)
    {
        $this->url = rtrim($url, '/').'/v1';
        $this->maxTimeout = $maxTimeout;
    }

    //открывает страницу в браузере решателя.
    //Возвращает array(status, body, cookies => array(имя => значение), userAgent) или NULL
    public function request(string $method, string $url, string $cookie = '', string $postData = '', ?string $proxy = NULL): ?array
    {
        $cookies = array();
        foreach (Cookies::parse($cookie) as $name => $value)
            $cookies[] = array('name' => $name, 'value' => $value);

        $params = array(
            'cmd'        => $method == 'POST' ? 'request.post' : 'request.get',
            'url'        => $url,
            'maxTimeout' => $this->maxTimeout * 1000,
            'cookies'    => $cookies,
        );
        if ($method == 'POST')
            $params['postData'] = $postData;
        if ( ! empty($proxy))
            $params['proxy'] = array('url' => $proxy);

        $ch = curl_init($this->url);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => 1,
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $this->maxTimeout + 60,
        ));
        $raw = curl_exec($ch);
        if ( ! is_string($raw) || $raw === '')
            return NULL;
        $data = json_decode($raw, TRUE);
        if ( ! is_array($data) || ! isset($data['solution']) || ! is_array($data['solution']))
            return NULL;

        $solution = $data['solution'];
        $result = array(
            'status'    => isset($solution['status']) ? (int)$solution['status'] : 0,
            'body'      => isset($solution['response']) ? (string)$solution['response'] : '',
            'cookies'   => array(),
            'userAgent' => isset($solution['userAgent']) ? (string)$solution['userAgent'] : '',
        );
        foreach (isset($solution['cookies']) ? (array)$solution['cookies'] : array() as $c)
            if (isset($c['name'], $c['value']))
                $result['cookies'][$c['name']] = $c['value'];
        return $result;
    }
}
