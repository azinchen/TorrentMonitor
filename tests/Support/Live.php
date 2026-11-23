<?php
use TorrentMonitor\App;
use TorrentMonitor\Data\Accounts;
use TorrentMonitor\Tracker\Registry;

// Live tests: accounts from tests/env/secrets/trackers.env and automatic choice of
// what to watch on each tracker (a fresh topic from the tracker's own listing page,
// or a series from its RSS feed). A topic or series can also be fixed in the secrets.
class Live
{
    // tracker => array(prefix of its keys in trackers.env, pages that list fresh torrents)
    const TRACKERS = array(
        'rutracker.org'    => array('RUTRACKER',   array('/forum/tracker.php', '/forum/index.php')),
        'nnmclub.to'       => array('NNMCLUB',     array('/forum/tracker.php', '/forum/portal.php', '/')),
        'kinozal'          => array('KINOZAL',     array('/browse.php', '/')),
        'pornolab.net'     => array('PORNOLAB',    array('/forum/tracker.php', '/forum/index.php')),
        'rustorka.com'     => array('RUSTORKA',    array('/forum/tracker.php', '/forum/index.php')),
        'booktracker.org'  => array('BOOKTRACKER', array('/tracker.php', '/index.php')),
        'rutor.is'         => array('RUTOR',       array('/', '/new')),
        'anidub.com'       => array('ANIDUB',      array('/')),
        'animelayer.ru'    => array('ANIMELAYER',  array('/torrents/anime/', '/')),
        'riperam.org'      => array('RIPERAM',     array('/', '/search.php?search_id=active_topics')),
        'lostfilm.tv'      => array('LOSTFILM',        array()),
        'lostfilm-mirror'  => array('LOSTFILM_MIRROR', array()),
        'newstudio.tv'     => array('NEWSTUDIO',       array()),
    );

    // What a link to a topic looks like where the topic id is a path, not a number:
    // tracker => array(pattern of the address with the topic number in brackets, pattern of addresses to leave out)
    const LINKS = array(
        'anidub.com'  => array('~/(\d+)-[^/]+\.html$~', NULL),
        'riperam.org' => array('~(?:-t|/topic)(\d+)\.html$~', '~/(pravila|novosti)[^/]*/~'),
    );

    // What a visitor without an account can see (for the test of trackers that have no account
    // in the secrets): forums - the page listing the sections whose pages list the topics (when the
    // usual listing shows nothing to guests); date, download - whether the release date and the link
    // to the torrent file are shown to guests (default: yes); no - why nothing can be tested.
    const GUEST = array(
        'rutracker.org'   => array('forums' => '/forum/index.php', 'date' => FALSE),
        'pornolab.net'    => array('forums' => '/forum/index.php', 'date' => FALSE),
        'riperam.org'     => array('download' => FALSE),
        'booktracker.org' => array('forums' => '/index.php', 'date' => FALSE, 'download' => FALSE),
        'nnmclub.to'      => array('download' => FALSE),
        'rustorka.com'    => array('no' => 'the site shows its captcha to every visitor'),
    );

    public static function guest($name)
    {
        $key = isset(self::TRACKERS[$name]) ? $name : 'kinozal';
        return (isset(self::GUEST[$key]) ? self::GUEST[$key] : array()) + array('forums' => NULL, 'date' => TRUE, 'download' => TRUE, 'no' => NULL);
    }

    private static function app()
    {
        require_once TM_ROOT.'/src/bootstrap.php';
    }

    // names for the PHPUnit data provider (only names: values must not get into the output)
    public static function names()
    {
        $sets = array();
        foreach (array_keys(self::TRACKERS) as $name)
            $sets[$name] = array($name);
        return $sets;
    }

    // Settings of a tracker from the secrets:
    // tracker (real name), login, password, passkey, cookie, address, topic, quality, watchUser, skip
    public static function account($name)
    {
        $secrets = Env::secrets('trackers');
        $prefix = self::TRACKERS[$name][0];
        $get = function ($key) use ($secrets, $prefix) {
            return isset($secrets[$prefix.'_'.$key]) ? $secrets[$prefix.'_'.$key] : '';
        };
        $tracker = $name == 'kinozal' ? ($get('DOMAIN') ?: 'kinozal.me') : $name;
        return array(
            'tracker' => $tracker, 'login' => $get('LOGIN'), 'password' => $get('PASSWORD'), 'passkey' => $get('PASSKEY'),
            // TM_LIVE_AUTO=1 ignores the topics fixed in the secrets
            'cookie' => $get('COOKIE'), 'address' => $get('ADDRESS'), 'topic' => getenv('TM_LIVE_AUTO') === '1' ? '' : $get('TOPIC'), 'quality' => $get('QUALITY'),
            'watchUser' => $get('WATCH_USER'), 'skip' => $get('SKIP') === '1',
        );
    }

    // strings that must never appear in test output
    public static function secretsOf(array $account)
    {
        $hide = array();
        foreach (array('login' => 'LOGIN', 'password' => 'PASSWORD', 'passkey' => 'PASSKEY', 'cookie' => 'COOKIE') as $key => $label)
            if ($account[$key] !== '')
                $hide[$label] = $account[$key];
        return $hide;
    }

    // why the tracker cannot be tested, or NULL
    public static function missing(array $account)
    {
        self::app();
        if ($account['skip'])
            return 'skipped in tests/env/secrets/trackers.env';
        if ( ! Registry::needsAccount($account['tracker']))
            return NULL;
        if ($account['cookie'] === '' && ($account['login'] === '' || $account['password'] === ''))
            return 'no login and password (or cookie) for '.$account['tracker'].' in tests/env/secrets/trackers.env';
        return NULL;
    }

    // puts the account into the application's settings
    public static function configure(array $account)
    {
        self::app();
        $tracker = $account['tracker'];
        Accounts::set($tracker, array('login' => $account['login'], 'password' => $account['password'],
            'passkey' => $account['passkey'], 'address' => $account['address']));
        Accounts::setCookie($tracker, $account['cookie']);
        App::resetHttp();
        Registry::reset();
    }

    private static function call($object, $method, ...$arguments)
    {
        $reflection = new ReflectionMethod($object, $method);
        if (PHP_VERSION_ID < 80100)
            $reflection->setAccessible(true);
        return $reflection->invoke($object, ...$arguments);
    }

    // a page of the tracker fetched the way the application does it (session, encoding, solver);
    // $guest - without logging in
    public static function page($tracker, $path, $guest = FALSE)
    {
        $object = Registry::get($tracker);
        if ($guest)
            return self::call($object, 'send', self::call($object, 'get', $path, '')->follow());
        $object->login();
        return self::call($object, 'send', self::call($object, 'get', $path)->follow());
    }

    // what the tracker's own code reads from a topic page fetched without logging in:
    // array(status, date or NULL, title or NULL, whether a link to the torrent file was found)
    public static function guestTopic($tracker, $url)
    {
        $object = Registry::get($tracker);
        $topicId = (string)$object->topicIdFromUrl($url);
        $response = self::call($object, 'send', self::call($object, 'topicRequest', $topicId)->cookie('')->follow());
        $page = $response->body;
        $title = NULL;
        try
        {
            $title = self::call($object, 'parseTitle', $page);
        }
        catch (\Throwable $e)
        {
        }
        return array('status' => $response->status, 'challenged' => $response->challenged, 'date' => self::call($object, 'parseDate', $page),
            'title' => $title, 'download' => self::call($object, 'downloadRequest', $topicId, $page) !== NULL);
    }

    // what the listing pages answered during the last topicUrls() call (for failure messages)
    public static $listing = '';

    // Addresses of fresh topics of a forum tracker, newest first. A fixed topic from the
    // secrets (an address or an id) replaces the search.
    public static function topicUrls(array $account, $limit = 3, $guest = FALSE)
    {
        $tracker = $account['tracker'];
        $object = Registry::get($tracker);
        if ($account['topic'] !== '')
            return array(preg_match('~^https?://~', $account['topic']) ? $account['topic'] : $object->topicUrl($account['topic']));

        $key = isset(self::TRACKERS[$tracker]) ? $tracker : 'kinozal';
        self::$listing = '';
        $urls = array();
        $pages = self::TRACKERS[$key][1];
        $rule = isset(self::LINKS[$key]) ? self::LINKS[$key] : array(NULL, NULL);
        // guests may see nothing in the usual listing: take the pages of a few sections instead
        if ($guest && self::guest($tracker)['forums'] !== NULL)
        {
            $index = self::page($tracker, self::guest($tracker)['forums'], TRUE);
            preg_match_all('~viewforum\.php\?f=\d+~', $index->body, $found);
            $forums = array_values(array_unique($found[0]));
            $folder = preg_replace('~[^/]*$~', '', self::guest($tracker)['forums']);
            $pages = array();
            for ($i = 0; $i < 6 && $i < count($forums); $i++)
                $pages[] = $folder.$forums[(int)floor($i * count($forums) / 6)];
            self::$listing .= self::guest($tracker)['forums'].': HTTP '.$index->status.', sections '.count($forums).'; ';
        }
        foreach ($pages as $path)
        {
            $response = self::page($tracker, $path, $guest);
            self::$listing .= $path.': '.($response->status == 0 ? 'no answer ('.$response->error.')' : 'HTTP '.$response->status.', '.strlen($response->body).' bytes')
                .($response->challenged ? ', Cloudflare challenge' : '').'; ';
            foreach (self::links($response->body, $object->base().$path) as $link)
            {
                $parsed = Registry::fromUrl($link);
                if ($parsed === NULL || $parsed['tracker'] != $tracker || (string)$parsed['topicId'] === '')
                    continue;
                $url = $object->topicUrl((string)$parsed['topicId']);
                // only links that really lead to a topic page, not any link with a number in it
                if (parse_url($url, PHP_URL_PATH) != parse_url($link, PHP_URL_PATH) && ! self::samePath($url, $link))
                    continue;
                // where the id is a path, any address of the site would pass: only the ones shaped like a topic
                $number = (string)$parsed['topicId'];
                if ($rule[0] !== NULL)
                {
                    if ( ! preg_match($rule[0], $link, $m) || ($rule[1] !== NULL && preg_match($rule[1], $link)))
                        continue;
                    $number = $m[1];
                }
                $urls[$url] = $number;
            }
            // a guest reads several sections: one of them may hold only announcements
            if ( ! $guest && count($urls) >= $limit)
                break;
        }
        // numbered topics: the newest first. Menus link to old topics (rules, announcements),
        // fresh torrents have the highest numbers.
        if ($urls === array_filter($urls, 'ctype_digit'))
            arsort($urls, SORT_NUMERIC);
        return array_slice(array_keys($urls), 0, $limit);
    }

    private static function samePath($a, $b)
    {
        return rtrim((string)parse_url($a, PHP_URL_PATH), '/') === rtrim((string)parse_url($b, PHP_URL_PATH), '/');
    }

    // absolute addresses of all links of a page
    public static function links($html, $pageUrl)
    {
        $origin = parse_url($pageUrl, PHP_URL_SCHEME).'://'.parse_url($pageUrl, PHP_URL_HOST)
            .(parse_url($pageUrl, PHP_URL_PORT) ? ':'.parse_url($pageUrl, PHP_URL_PORT) : '');
        $folder = preg_replace('~[^/]*$~', '', (string)parse_url($pageUrl, PHP_URL_PATH)) ?: '/';
        preg_match_all('/href\s*=\s*["\']([^"\'#]+)/i', $html, $found);
        $links = array();
        foreach ($found[1] as $href)
        {
            $href = html_entity_decode(trim($href), ENT_QUOTES, 'UTF-8');
            if ($href === '' || preg_match('~^(javascript|mailto|magnet|data):~i', $href))
                continue;
            if (preg_match('~^https?://~i', $href))
                $links[] = $href;
            elseif (strncmp($href, '//', 2) === 0)
                $links[] = parse_url($pageUrl, PHP_URL_SCHEME).':'.$href;
            elseif ($href[0] == '/')
                $links[] = $origin.$href;
            else
                $links[] = $origin.$folder.preg_replace('~^\./~', '', $href);
        }
        return array_values(array_unique($links));
    }

    // A series to watch on a series tracker, taken from the newest suitable item of its feed:
    // array(name, quality, episode before the newest one) or NULL. With "name" set in the
    // secrets only the quality and the episode are looked up.
    // $guest - without logging in (the feeds are public).
    public static function series(array $account, $guest = FALSE)
    {
        $tracker = $account['tracker'];
        $object = Registry::get($tracker);
        if ( ! $guest)
            $object->login();
        $response = self::call($object, 'send', $object->request(array('name' => '', 'quality' => 'sd', 'episode' => '')));
        self::$listing = 'feed: '.($response->status == 0 ? 'no answer ('.$response->error.')' : 'HTTP '.$response->status.', '.strlen($response->body).' bytes');
        $body = $response->body;
        if ($tracker == 'lostfilm.tv')
            $body = str_replace(array('<![CDATA[', ']]>'), '', str_replace('&', '&amp;', str_replace('&amp;', '&', $body)));
        $body = (string)preg_replace('/^(\s*<\?xml[^>]*encoding=["\'])[^"\']*/i', '$1utf-8', $body);
        $before = libxml_use_internal_errors(TRUE);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($before);
        if ($xml === FALSE || ! isset($xml->channel->item))
            return NULL;

        foreach ($xml->channel->item as $item)
        {
            $choice = self::seriesFromItem($tracker, (string)$item->title, (string)$item->link, $account['quality']);
            if ($choice === NULL || ! $object->validName($choice[0]))
                continue;
            if ($account['topic'] !== '' && stripos((string)$item->title, $account['topic']) === FALSE
                && stripos(str_replace('.', ' ', (string)$item->title), $account['topic']) === FALSE)
                continue;
            if ($account['topic'] !== '')
                $choice[0] = $account['topic'];
            return $choice;
        }
        return NULL;
    }

    // array(name, quality, 'SxxEyy' of the episode before this one) from one feed item, or NULL
    private static function seriesFromItem($tracker, $title, $link, $wanted)
    {
        $quality = NULL;
        $name = NULL;
        $se = NULL;
        if ($tracker == 'lostfilm.tv')
        {
            // Русское название (Original Name). Название серии. (S01E02)
            if ( ! preg_match('/\(([^()]*[A-Za-z][^()]*)\)/', $title, $n) || ! preg_match('/S(\d{2})E(\d{2})/', $title, $se))
                return NULL;
            $name = trim($n[1]);
            $quality = $wanted !== '' ? $wanted : '1080';
        }
        elseif ($tracker == 'lostfilm-mirror')
        {
            // Original.Name.S01E02.1080p.rus.LostFilm.TV.mkv
            if ( ! preg_match('/^(.+?)[\s.]+s(\d{2})\.?e(\d{2})/i', $title, $n) || ! preg_match('/s(\d{2})\.?e(\d{2})/i', $link, $se))
                return NULL;
            $name = trim(str_replace('.', ' ', $n[1]));
            foreach (array('1080' => 'mkv|MKV', '720' => 'mp4|MP4', 'sd' => 'avi|AVI') as $q => $pattern)
                if ($quality === NULL && preg_match('/'.$pattern.'/', $link) && ($wanted === '' || $wanted == $q))
                    $quality = (string)$q;
        }
        elseif ($tracker == 'newstudio.tv')
        {
            // Название (Сезон 1, Серия 2) / Original Name (2026) WEBDLRip
            if ( ! preg_match('/Сезон (\d{1,2}), Серия (\d{1,2})/u', $title, $se) || ! preg_match('~/\s*([A-Za-z0-9][A-Za-z0-9 .+\'`:;-]*?)\s*(\(|$)~', $title, $n))
                return NULL;
            $name = trim($n[1]);
            foreach (array('1080' => '1080p', '720' => '720p', 'sd' => 'WEBDLRip') as $q => $pattern)
                if ($quality === NULL && preg_match('/'.$pattern.'/', $title) && ($wanted === '' || $wanted == $q))
                    $quality = (string)$q;
        }
        if ($name === NULL || $name === '' || $quality === NULL)
            return NULL;
        return array($name, $quality, self::episodeBefore((int)$se[1], (int)$se[2]));
    }

    // the episode right before the given one ('' if there is none: the first episode of the first season)
    public static function episodeBefore($season, $episode)
    {
        if ($episode > 1)
            return sprintf('S%02dE%02d', $season, $episode - 1);
        return $season > 1 ? sprintf('S%02dE99', $season - 1) : '';
    }
}
