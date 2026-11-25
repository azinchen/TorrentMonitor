<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\Data\Accounts;
use TorrentMonitor\Http\Client;
use TorrentMonitor\Http\Request;
use TorrentMonitor\Http\Response;

// A tracker. One object is created per run and holds the session.
//
// How the engine uses it:
//   login()                    - once per run; an exception stops work with the tracker
//   request($topic)            - which page to fetch for a topic (pages of all topics are loaded in parallel)
//   check($topic, $response)   - parses the page; returns the new releases found
abstract class Tracker
{
    // site addresses: the main one and fallbacks (the first reachable one is used).
    // The address from the tracker settings is put before them.
    const ADDRESSES = array();
    // encoding of the site's pages (NULL - UTF-8)
    const CHARSET = NULL;
    // whether a login and password are needed
    const NEEDS_ACCOUNT = TRUE;
    // the tracker also works for a guest: without a login it is checked without signing in
    const ACCOUNT_OPTIONAL = FALSE;

    protected string $name;
    protected Client $http;
    /** @var array{login:string,password:string,passkey:string,address:string} */
    protected array $account;
    // dry run: torrent files are not downloaded (trackers may have a daily limit)
    public bool $probe = FALSE;
    // session cookie
    protected string $session = '';
    private bool $loggedIn = FALSE;
    /** @var string[] */
    private array $addresses;
    // whether the current address has returned at least one response
    private bool $reached = FALSE;
    // the site's last response was an unsolved Cloudflare challenge
    private bool $blocked = FALSE;
    // the last request got no response: 'proxy_fail' or 'tracker_unreachable'
    private ?string $failure = NULL;

    public function __construct(string $name, Client $http)
    {
        $this->name = $name;
        $this->http = $http;
        $this->account = Accounts::get($name);
        $this->addresses = $this->defaultAddresses();
        foreach (array_reverse(preg_split('/[\s,;]+/', $this->account['address'], -1, PREG_SPLIT_NO_EMPTY)) as $address)
        {
            if (strpos($address, '://') === FALSE)
                $address = 'https://'.$address;
            array_unshift($this->addresses, rtrim($address, '/'));
        }
        $this->addresses = array_values(array_unique($this->addresses));
    }

    /** @return string[] default site addresses */
    protected function defaultAddresses(): array
    {
        return static::ADDRESSES;
    }

    public function name(): string
    {
        return $this->name;
    }

    // site address without a trailing /
    public function base(): string
    {
        return $this->addresses[0];
    }

    // ------------------------------------------------------- for the engine and API

    // Logs in to the tracker unless already logged in. TrackerException with an error code on failure.
    public function login(): void
    {
        if ($this->loggedIn)
            return;
        if (static::NEEDS_ACCOUNT)
        {
            // the cookie may have been pasted in by the user manually (login with a captcha): then no login and password are needed
            $saved = Accounts::cookie($this->name);
            if ($saved !== '' && $this->sessionAlive($saved))
                $this->session = $saved;
            elseif (static::ACCOUNT_OPTIONAL && ! Accounts::filled($this->name))
                //no account: the tracker is read as a guest
                $this->session = '';
            elseif ( ! Accounts::filled($this->name))
                throw new TrackerException($saved !== '' ? ($this->blocked ? 'cloudflare' : ($this->failure ?: 'credential_wrong')) : 'credential_miss');
            else
            {
                try
                {
                    $this->session = $this->signIn();
                }
                catch (TrackerException $e)
                {
                    // no connection at all says more than "no login page" or "no cookie"
                    if ($this->blocked)
                        throw new TrackerException('cloudflare');
                    throw $this->failure !== NULL && $e->getMessage() != 'credential_wrong' ? new TrackerException($this->failure) : $e;
                }
                Accounts::setCookie($this->name, $this->session);
            }
        }
        $this->loggedIn = TRUE;
    }

    // Opens the main page of the site: the check of a tracker that is used without signing in.
    // TrackerException with an error code when the site does not answer or cannot be read.
    public function reach(): void
    {
        $response = $this->send($this->get('/'));
        if ($response->challenged)
            throw new TrackerException('cloudflare');
        if ($response->status == 0 || $response->status >= 500)
            throw new TrackerException($this->failure ?: 'cant_get_forum_page');
    }

    // request for the page the topic is checked by; NULL if no page is needed
    abstract public function request(array $topic): ?Request;

    // Parses the topic page. Returns the new releases (empty - no updates).
    // TrackerException with an error code if the topic could not be checked.
    /** @return Found[] */
    abstract public function check(array $topic, Response $response): array;

    // topic title on the tracker (for adding a topic without a title); NULL if it could not be obtained
    public function title(string $topicId): ?string
    {
        return NULL;
    }

    // topic address on the tracker for links in the interface and notifications ('' - no link)
    public function topicUrl(string $topicId): string
    {
        return '';
    }

    // topic id from its address; NULL if the address does not match
    public function topicIdFromUrl(string $url): ?string
    {
        return NULL;
    }

    // whether such a series title is acceptable (for series trackers)
    public function validName(string $name): bool
    {
        return TRUE;
    }

    // ---------------------------------------------- watching users

    // Topics of a tracker user: array of array(section, topicId, title, date 'Y-m-d').
    // NULL if the tracker cannot do this.
    public function userTopics(string $user): ?array
    {
        return NULL;
    }

    // torrent file of the topic; TrackerException on failure
    public function download(string $topicId): string
    {
        throw new TrackerException('torrent_file_fail');
    }

    // ------------------------------------------------- for tracker classes

    // whether the saved session cookie is still valid
    protected function sessionAlive(string $cookie): bool
    {
        return FALSE;
    }

    // Authentication: returns the session cookie. TrackerException with an error code on failure.
    protected function signIn(): string
    {
        throw new TrackerException('credential_miss');
    }

    // login in the site's encoding, ready to be put into a POST body
    protected function encodedLogin(): string
    {
        $login = $this->account['login'];
        if (static::CHARSET !== NULL)
            $login = \TorrentMonitor\Text::fromUtf8($login, static::CHARSET);
        return rawurlencode($login);
    }

    protected function get(string $path, ?string $cookie = NULL): Request
    {
        return $this->prepare(Request::get($this->url($path)), $cookie);
    }

    /** @param string|array $body */
    protected function post(string $path, $body = '', ?string $cookie = NULL): Request
    {
        return $this->prepare(Request::post($this->url($path), $body), $cookie);
    }

    private function prepare(Request $request, ?string $cookie): Request
    {
        return $request->cookie($cookie === NULL ? $this->session : $cookie)->charset(static::CHARSET)->tracker($this->name);
    }

    // full address: a path relative to the site address or a ready-made address
    protected function url(string $path): string
    {
        return preg_match('~^https?://~i', $path) ? $path : $this->base().$path;
    }

    // Performs a request. Until the site has responded at least once, tries the fallback addresses on a connection error.
    protected function send(Request $request): Response
    {
        $response = $this->http->send($request);
        while ($response->status == 0 && ! $this->reached && count($this->addresses) > 1
            && strncmp($request->url, $this->base(), strlen($this->base())) === 0)
        {
            $path = substr($request->url, strlen($this->base()));
            array_shift($this->addresses);
            $request->url = $this->base().$path;
            $response = $this->http->send($request);
        }
        if ($response->status != 0)
            $this->reached = TRUE;
        $this->blocked = $response->challenged;
        $this->failure = $response->failure();
        return $response;
    }

    // whether the content looks like a torrent file
    public static function isTorrent(string $data): bool
    {
        return strlen($data) > 100 && strpos($data, 'announce') !== FALSE && $data[0] == 'd';
    }

    // Downloads the torrent file. TrackerException('torrent_file_fail') if something else came back.
    protected function torrent(Request $request): string
    {
        if ($this->probe)
            return '';
        $response = $this->send($request->binary()->charset(NULL));
        if ( ! self::isTorrent($response->body))
            throw new TrackerException('torrent_file_fail');
        return $response->body;
    }

    // month as a word (янв, Feb, марта...) -> number '01'..'12'; NULL if not recognized
    // the time zone the tracker shows its dates in
    protected function zone(): \DateTimeZone
    {
        return \TorrentMonitor\Time::zone($this->name);
    }

    public static function monthNumber(string $month): ?string
    {
        $names = array('янв' => '01', 'фев' => '02', 'мар' => '03', 'апр' => '04', 'мая' => '05', 'май' => '05', 'июн' => '06',
            'июл' => '07', 'авг' => '08', 'сен' => '09', 'окт' => '10', 'ноя' => '11', 'дек' => '12',
            'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04', 'may' => '05', 'jun' => '06',
            'jul' => '07', 'aug' => '08', 'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12');
        $key = mb_substr(mb_strtolower(trim($month), 'UTF-8'), 0, 3, 'UTF-8');
        return isset($names[$key]) ? $names[$key] : NULL;
    }

    // month number -> 'Янв'..'Дек'
    public static function monthName($number): string
    {
        $names = array('', 'Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек');
        return isset($names[(int)$number]) ? $names[(int)$number] : '';
    }

    // contents of the page's <title> with HTML entities decoded
    protected static function pageTitle(string $page): ?string
    {
        if ( ! preg_match('/<title>(.*)<\/title>/sU', $page, $m))
            return NULL;
        return trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
    }
}
