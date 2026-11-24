<?php
namespace TorrentMonitor\Http;

// Cloudflare challenge solver with the FlareSolverr API (FlareSolverr, Byparr).
final class Solver
{
    private string $url;
    private int $maxTimeout;

    public function __construct(string $url, int $maxTimeout = 120)
    {
        $this->url = rtrim($url, '/').'/v1';
        $this->maxTimeout = $maxTimeout;
    }

    // What the solver at $url says about itself: array('name' => 'FlareSolverr', 'version' => '3.5.2'),
    // or NULL when nothing answers there. FlareSolverr tells at its main address, Byparr at /health;
    // a solver that answers without naming itself gives empty strings.
    public static function about(Client $http, string $url): ?array
    {
        $alive = FALSE;
        foreach (array('/', '/health') as $path)
        {
            $response = $http->send(Request::get(rtrim($url, '/').$path)->plain()->timeout(8));
            if ($response->status == 0)
                continue;
            $alive = TRUE;
            $data = json_decode($response->body, TRUE);
            if ( ! is_array($data) || empty($data['version']) || ! is_scalar($data['version']))
                continue;
            $name = isset($data['msg']) && preg_match('/^(\w+)/', (string)$data['msg'], $m) ? $m[1] : '';
            return array('name' => $name, 'version' => (string)$data['version']);
        }
        return $alive ? array('name' => '', 'version' => '') : NULL;
    }

    // Opens a page in the solver's browser.
    // Returns array(status, body, cookies => array(name => value), userAgent) or NULL
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
