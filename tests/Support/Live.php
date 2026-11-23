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
        'baibako.tv_forum' => array('BAIBAKO',     array('/browse.php', '/')),
        'lostfilm.tv'      => array('LOSTFILM',        array()),
        'lostfilm-mirror'  => array('LOSTFILM_MIRROR', array()),
        'baibako.tv'       => array('BAIBAKO',         array()),
        'newstudio.tv'     => array('NEWSTUDIO',       array()),
    );

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
        // the forum and the RSS feed of baibako.tv share the account but not the topic
        $own = $name == 'baibako.tv_forum' ? 'FORUM_' : '';
        return array(
            'tracker' => $tracker, 'login' => $get('LOGIN'), 'password' => $get('PASSWORD'), 'passkey' => $get('PASSKEY'),
            // TM_LIVE_AUTO=1 ignores the topics fixed in the secrets
            'cookie' => $get('COOKIE'), 'address' => $get('ADDRESS'), 'topic' => getenv('TM_LIVE_AUTO') === '1' ? '' : $get($own.'TOPIC'), 'quality' => $get('QUALITY'),
            'watchUser' => $get('WATCH_USER'), 'skip' => $get($own.'SKIP') === '1',
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
        if ($account['tracker'] == 'baibako.tv' && $account['passkey'] === '')
            return 'no passkey for baibako.tv in tests/env/secrets/trackers.env';
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
        // the same account serves both baibako entries
        if ($tracker == 'baibako.tv_forum' || $tracker == 'baibako.tv')
        {
            $other = $tracker == 'baibako.tv' ? 'baibako.tv_forum' : 'baibako.tv';
            Accounts::set($other, array('login' => $account['login'], 'password' => $account['password'], 'passkey' => $account['passkey']));
        }
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

    // a page of the tracker fetched the way the application does it (session, encoding, solver)
    public static function page($tracker, $path)
    {
        $object = Registry::get($tracker);
        $object->login();
        return self::call($object, 'send', self::call($object, 'get', $path)->follow());
    }

    // what the listing pages answered during the last topicUrls() call (for failure messages)
    public static $listing = '';

    // Addresses of fresh topics of a forum tracker, newest first. A fixed topic from the
    // secrets (an address or an id) replaces the search.
    public static function topicUrls(array $account, $limit = 3)
    {
        $tracker = $account['tracker'];
        $object = Registry::get($tracker);
        if ($account['topic'] !== '')
            return array(preg_match('~^https?://~', $account['topic']) ? $account['topic'] : $object->topicUrl($account['topic']));

        $key = isset(self::TRACKERS[$tracker]) ? $tracker : 'kinozal';
        self::$listing = '';
        $urls = array();
        foreach (self::TRACKERS[$key][1] as $path)
        {
            $response = self::page($tracker, $path);
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
                $urls[$url] = (string)$parsed['topicId'];
            }
            if (count($urls) >= $limit)
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
    public static function series(array $account)
    {
        $tracker = $account['tracker'];
        $object = Registry::get($tracker);
        $object->login();
        $response = self::call($object, 'send', $object->request(array('name' => '', 'quality' => 'sd', 'episode' => '')));
        $body = $response->body;
        if ($tracker == 'lostfilm.tv' || $tracker == 'baibako.tv')
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
                    $quality = $q;
        }
        elseif ($tracker == 'baibako.tv')
        {
            if ( ! preg_match('/s(\d{2})\.?e(\d{2})/i', $link, $se) && ! preg_match('/s(\d{2})\.?e\d{2}-(\d{2})/i', $title, $se))
                return NULL;
            // the name is the text before the season mark or the first slash
            if ( ! preg_match('~^\s*([^/\[(]+?)\s*(/|\(|\[|s\d{2}e\d{2}|$)~iu', $title, $n))
                return NULL;
            $name = trim($n[1]);
            foreach (array('1080' => 'HD(TV)?1080', '720' => 'HD(TV)?720', 'sd' => 'x264') as $q => $pattern)
                if ($quality === NULL && preg_match('/'.$pattern.'/', $title) && ($wanted === '' || $wanted == $q))
                    $quality = $q;
        }
        elseif ($tracker == 'newstudio.tv')
        {
            // Название (Сезон 1, Серия 2) / Original Name (2026) WEBDLRip
            if ( ! preg_match('/Сезон (\d{1,2}), Серия (\d{1,2})/u', $title, $se) || ! preg_match('~/\s*([A-Za-z0-9][A-Za-z0-9 .+\'`:;-]*?)\s*(\(|$)~', $title, $n))
                return NULL;
            $name = trim($n[1]);
            foreach (array('1080' => '1080p', '720' => '720p', 'sd' => 'WEBDLRip') as $q => $pattern)
                if ($quality === NULL && preg_match('/'.$pattern.'/', $title) && ($wanted === '' || $wanted == $q))
                    $quality = $q;
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
