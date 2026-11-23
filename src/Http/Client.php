<?php
namespace TorrentMonitor\Http;

// The only HTTP client of TorrentMonitor: all requests to trackers, torrent clients,
// notifications and GitHub go through it.
//
// Cloudflare. If a site answers with a challenge, the client asks the solver (FlareSolverr/Byparr)
// to open the site's page, remembers the issued cookies and the browser User-Agent and repeats
// the original request directly. This also covers POST requests (login) and downloading
// torrent files, which do not go through the solver's browser. If the direct retry still
// hits the challenge (the solver has a different IP), the solver's own response is used.
final class Client
{
    private string $userAgent;
    private int $timeout;
    private int $concurrency;
    private ?Solver $solver;
    private ?ClearanceStore $clearance;
    /** @var array{enabled:bool,address:string,type:string} */
    private array $proxy;
    /** @var array<string,array> tracker => array(use, address, type) */
    private array $trackerProxy;
    /** @var callable|null function(string $line): void */
    private $log;
    // Whether bypassing Cloudflare is allowed (it can be turned off for availability checks)
    public bool $solveChallenges = TRUE;

    //$options: userAgent, timeout, concurrency, solverUrl, proxy => array(enabled, address, type),
    // trackerProxy => array(tracker => array(use, address, type)), log => callable
    public function __construct(array $options = array(), ?ClearanceStore $clearance = NULL)
    {
        $this->userAgent = ! empty($options['userAgent']) ? (string)$options['userAgent'] : 'Mozilla/5.0 (X11; Linux x86_64; rv:133.0) Gecko/20100101 Firefox/133.0';
        $this->timeout = ! empty($options['timeout']) ? max(1, (int)$options['timeout']) : 15;
        $this->concurrency = ! empty($options['concurrency']) ? max(1, (int)$options['concurrency']) : 10;
        $this->solver = ! empty($options['solverUrl']) ? new Solver((string)$options['solverUrl']) : NULL;
        $this->clearance = $clearance;
        $this->proxy = (isset($options['proxy']) ? (array)$options['proxy'] : array()) + array('enabled' => FALSE, 'address' => '', 'type' => '');
        $this->trackerProxy = isset($options['trackerProxy']) ? (array)$options['trackerProxy'] : array();
        $this->log = isset($options['log']) ? $options['log'] : NULL;
    }

    public function hasSolver(): bool
    {
        return $this->solver !== NULL;
    }

    public function get(string $url): Response
    {
        return $this->send(Request::get($url));
    }

    public function send(Request $request): Response
    {
        $responses = $this->sendAll(array($request));
        return $responses[0];
    }

    // Performs requests in parallel. The result keys match the keys of $requests.
    /**
     * @param array<int|string,Request> $requests
     * @return array<int|string,Response>
     */
    public function sendAll(array $requests): array
    {
        // Identical GET requests (one RSS feed for ten series) are performed once
        $unique = array();
        $aliases = array();
        foreach ($requests as $key => $request)
        {
            $signature = $request->method == 'GET'
                ? md5($request->url."\n".$request->cookie."\n".implode("\n", $request->headers))
                : 'key:'.$key;
            if ( ! isset($unique[$signature]))
                $unique[$signature] = $request;
            $aliases[$key] = $signature;
        }

        $raw = $this->transfer($unique);

        // Cloudflare challenges are bypassed one at a time: the first solution gives cookies to the other requests to the site
        foreach ($unique as $signature => $request)
            if ($this->isChallenge($raw[$signature]))
                $raw[$signature] = $this->solve($request, $raw[$signature]);

        $result = array();
        foreach ($aliases as $key => $signature)
            $result[$key] = $raw[$signature];
        return $result;
    }

    public static function isChallengePage(int $status, string $headers, string $body): bool
    {
        if ($status != 403 && $status != 503)
            return FALSE;
        if (preg_match('/^cf-mitigated:\s*challenge/mi', $headers))
            return TRUE;
        return strpos($body, 'cf-browser-verification') !== FALSE
            || strpos($body, 'Just a moment') !== FALSE
            || strpos($body, 'challenge-platform') !== FALSE
            || (stripos($body, 'cloudflare') !== FALSE
                && (strpos($body, 'Checking your browser') !== FALSE || strpos($body, 'DDoS protection') !== FALSE));
    }

    // Proxy address for the request as scheme://host:port, or NULL
    public function proxyFor(Request $request): ?string
    {
        if ($request->plain)
            return NULL;

        $enabled = ! empty($this->proxy['enabled']);
        $address = (string)$this->proxy['address'];
        $type = strtoupper((string)$this->proxy['type']);

        $names = array(preg_replace('/^www\./', '', $request->host()));
        if ($request->tracker !== NULL)
            array_unshift($names, $request->tracker);
        foreach ($names as $name)
        {
            if ( ! isset($this->trackerProxy[$name]))
                continue;
            $own = $this->trackerProxy[$name];
            $enabled = isset($own['use']) && $own['use'] == 'yes';
            if ($enabled && ! empty($own['address']))
            {
                $address = (string)$own['address'];
                $type = strtoupper(isset($own['type']) ? (string)$own['type'] : '');
            }
            break;
        }

        if ( ! $enabled || $address === '')
            return NULL;
        if (strpos($address, '://') !== FALSE)
            return $address;
        return ($type == 'SOCKS5' ? 'socks5h' : 'http').'://'.$address;
    }

    // ---------------------------------------------------------------- internals

    private function isChallenge(Response $response): bool
    {
        return self::isChallengePage($response->status, $response->headers, $response->body);
    }

    private function solve(Request $request, Response $blocked): Response
    {
        $blocked->challenged = TRUE;
        if ($this->solver === NULL || $request->plain || ! $this->solveChallenges)
            return $blocked;

        $host = $request->host();
        $proxy = $this->proxyFor($request);
        $solverProxy = $proxy === NULL ? NULL : str_replace('socks5h://', 'socks5://', $proxy);
        $ownCookie = $this->withoutCloudflare($request->cookie);

        // Another request of the same batch may have already obtained fresh cookies: try with them
        $known = $this->clearance !== NULL ? $this->clearance->get($host) : NULL;
        if ($known !== NULL && $blocked->sentClearance !== $known['cookie'])
        {
            $retry = $this->transfer(array($request))[0];
            if ( ! $this->isChallenge($retry))
                return $retry;
        }

        // The solver's browser opens the address closed by the challenge: the request address itself (for POST too -
        // sites often protect only some pages, and an unprotected page does not issue the cookie).
        // A file cannot be opened in the browser: the page it is downloaded from is used instead.
        $page = ($request->method == 'GET' && ! $request->binary);
        $targets = $request->binary ? array_filter(array($request->referer, $request->origin().'/')) : array($request->url);
        $solution = NULL;
        foreach ($targets as $target)
        {
            $this->log('Cloudflare: '.$host.' запрашивает проверку, обращаюсь к решателю ('.$target.')');
            $solution = $this->solver->request('GET', $target, $ownCookie, '', $solverProxy);
            if ($solution === NULL)
                continue;
            $passed = array();
            foreach ($solution['cookies'] as $name => $value)
                if (Cookies::isCloudflare($name))
                    $passed[$name] = $value;
            if ($this->clearance === NULL || empty($passed) || $solution['userAgent'] === '')
                continue;

            $this->clearance->set($host, Cookies::build($passed), $solution['userAgent']);
            $retry = $this->transfer(array($request))[0];
            if ( ! $this->isChallenge($retry))
                return $retry;
            $this->log('Cloudflare: cookie решателя не подошли для прямого запроса к '.$host.' (другой IP-адрес?)');
            break;
        }
        if ($solution === NULL)
        {
            $this->log('Cloudflare: решатель не справился с '.$host);
            if ($this->clearance !== NULL)
                $this->clearance->forget($host);
            return $blocked;
        }

        // The direct request does not get through: take what the solver's browser saw
        if ( ! $page)
        {
            if ($request->binary)
                return $blocked;
            $solution = $this->solver->request('POST', $request->url, $ownCookie, $request->bodyString(), $solverProxy);
            if ($solution === NULL)
                return $blocked;
        }
        if (self::isChallengePage($solution['status'] ?: 403, '', $solution['body']))
            return $blocked;

        $response = new Response();
        $response->status = $solution['status'] ?: 200;
        $response->body = $solution['body'];
        $response->cookies = $solution['cookies'];
        $response->solved = TRUE;
        return $response;
    }

    private function withoutCloudflare(string $cookie): string
    {
        $own = array();
        foreach (Cookies::parse($cookie) as $name => $value)
            if ( ! Cookies::isCloudflare($name))
                $own[$name] = $value;
        return Cookies::build($own);
    }

    /**
     * @param array<int|string,Request> $requests
     * @return array<int|string,Response>
     */
    private function transfer(array $requests): array
    {
        $responses = array();
        $handles = array();
        $headers = array();
        $started = array();
        $sent = array();

        $queue = array_keys($requests);
        $active = array();
        $multi = curl_multi_init();

        $start = function () use (&$queue, &$active, &$handles, &$headers, &$started, &$sent, $requests, $multi) {
            while ( ! empty($queue) && count($active) < $this->concurrency)
            {
                $key = array_shift($queue);
                $headers[$key] = '';
                $handles[$key] = $this->handle($requests[$key], $headers[$key], $sent[$key]);
                $started[$key] = microtime(TRUE);
                $active[spl_object_id($handles[$key])] = $key;
                curl_multi_add_handle($multi, $handles[$key]);
            }
        };

        $start();
        while ( ! empty($active))
        {
            do
                $status = curl_multi_exec($multi, $running);
            while ($status == CURLM_CALL_MULTI_PERFORM);

            while (($info = curl_multi_info_read($multi)) !== FALSE)
            {
                if ($info['msg'] !== CURLMSG_DONE)
                    continue;
                $ch = $info['handle'];
                $key = $active[spl_object_id($ch)];
                unset($active[spl_object_id($ch)]);

                $response = new Response();
                $response->status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $response->headers = $headers[$key];
                $response->cookies = Cookies::fromHeaders($headers[$key]);
                $response->error = $info['result'] === CURLE_OK ? '' : (curl_error($ch) ?: 'curl error '.$info['result']);
                $response->errno = (int)$info['result'];
                $response->proxy = $this->proxyFor($requests[$key]);
                $response->sentClearance = $sent[$key];
                $body = (string)curl_multi_getcontent($ch);
                $charset = $requests[$key]->charset;
                if ($charset !== NULL && $body !== '' && ! mb_check_encoding($body, 'UTF-8'))
                    $body = \TorrentMonitor\Text::toUtf8($body, $charset);
                $response->body = $body;
                $responses[$key] = $response;

                $this->log(sprintf('%s %s -> %s, %d байт, %.2f с', $requests[$key]->method, $requests[$key]->url,
                    $response->error !== '' ? $response->error : $response->status, strlen($body), microtime(TRUE) - $started[$key]));

                curl_multi_remove_handle($multi, $ch);
                unset($handles[$key]);
                $start();
            }

            if ( ! empty($active) && curl_multi_select($multi, 1.0) === -1)
                usleep(20000);
        }
        curl_multi_close($multi);

        return $responses;
    }

    /** @return \CurlHandle */
    private function handle(Request $request, string &$headers, &$sentClearance)
    {
        $userAgent = $this->userAgent;
        $cookie = $request->cookie;
        $sentClearance = NULL;
        if ( ! $request->plain && $this->clearance !== NULL)
        {
            $known = $this->clearance->get($request->host());
            if ($known !== NULL)
            {
                // Cloudflare cookies are valid only together with the User-Agent of the browser that passed the challenge
                $userAgent = $known['userAgent'];
                $cookie = Cookies::merge($cookie, $known['cookie']);
                $sentClearance = $known['cookie'];
            }
        }

        $ch = curl_init();
        $options = array(
            CURLOPT_URL            => $request->url,
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_USERAGENT      => $userAgent,
            CURLOPT_ENCODING       => '',
            CURLOPT_CONNECTTIMEOUT => min(10, $request->timeout !== NULL ? $request->timeout : $this->timeout),
            CURLOPT_TIMEOUT        => $request->timeout !== NULL ? $request->timeout : $this->timeout,
            CURLOPT_FOLLOWLOCATION => $request->follow,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                $headers .= $line;
                return strlen($line);
            },
        );
        if ($request->method == 'POST')
        {
            $options[CURLOPT_POST] = TRUE;
            $options[CURLOPT_POSTFIELDS] = $request->bodyString();
        }
        elseif ($request->method != 'GET')
        {
            $options[CURLOPT_CUSTOMREQUEST] = $request->method;
            if ($request->body !== NULL)
                $options[CURLOPT_POSTFIELDS] = $request->bodyString();
        }
        if ($cookie !== '')
            $options[CURLOPT_COOKIE] = $cookie;
        if ( ! empty($request->headers))
            $options[CURLOPT_HTTPHEADER] = $request->headers;
        if ($request->referer !== NULL)
            $options[CURLOPT_REFERER] = $request->referer;
        if ($request->auth !== NULL)
            $options[CURLOPT_USERPWD] = $request->auth;
        $proxy = $this->proxyFor($request);
        if ($proxy !== NULL)
            $options[CURLOPT_PROXY] = $proxy;

        curl_setopt_array($ch, $options);
        return $ch;
    }

    private function log(string $line): void
    {
        if ($this->log !== NULL)
            call_user_func($this->log, $line);
    }
}
