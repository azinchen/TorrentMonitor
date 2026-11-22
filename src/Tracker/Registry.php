<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\App;
use TorrentMonitor\Data\Accounts;

// The list of supported trackers.
final class Registry
{
    // tracker => array(class, kind: topic - forum topic, series - series from RSS)
    const TRACKERS = array(
        'rutracker.org'    => array(Rutracker::class, 'topic'),
        'nnmclub.to'       => array(Nnmclub::class, 'topic'),
        'lostfilm.tv'      => array(Lostfilm::class, 'series'),
        'rutor.is'         => array(Rutor::class, 'topic'),
        'tfile.cc'         => array(Tfile::class, 'topic'),
        'kinozal.me'       => array(Kinozal::class, 'topic'),
        'anidub.com'       => array(Anidub::class, 'topic'),
        'baibako.tv'       => array(Baibako::class, 'series'),
        'newstudio.tv'     => array(Newstudio::class, 'series'),
        'animelayer.ru'    => array(Animelayer::class, 'topic'),
        'rustorka.com'     => array(Rustorka::class, 'topic'),
        'pornolab.net'     => array(Pornolab::class, 'topic'),
        'lostfilm-mirror'  => array(LostfilmMirror::class, 'series'),
        'booktracker.org'  => array(Booktracker::class, 'topic'),
        'baibako.tv_forum' => array(BaibakoForum::class, 'topic'),
        'riperam.org'      => array(Riperam::class, 'topic'),
        'kinozal.tv'       => array(Kinozal::class, 'topic'),
        'kinozal.guru'     => array(Kinozal::class, 'topic'),
    );

    // other addresses of the same trackers: host => tracker
    const HOSTS = array(
        'tr.anidub.com' => 'anidub.com',
        'baibako.tv'    => 'baibako.tv_forum',
        'rutracker.net' => 'rutracker.org',
        'rutracker.nl'  => 'rutracker.org',
        'nnm-club.me'   => 'nnmclub.to',
        'rutor.info'    => 'rutor.is',
    );

    /** @var array<string,Tracker> */
    private static array $instances = array();

    /** @return string[] */
    public static function names(): array
    {
        $names = array_keys(self::TRACKERS);
        sort($names);
        return $names;
    }

    public static function has(string $name): bool
    {
        return isset(self::TRACKERS[$name]);
    }

    // topic or series; NULL for an unknown tracker
    public static function kind(string $name): ?string
    {
        return isset(self::TRACKERS[$name]) ? self::TRACKERS[$name][1] : NULL;
    }

    public static function needsAccount(string $name): bool
    {
        return isset(self::TRACKERS[$name]) && constant(self::TRACKERS[$name][0].'::NEEDS_ACCOUNT');
    }

    // whether the tracker can be used: credentials (or a session cookie) are set or not needed
    public static function ready(string $name): bool
    {
        return self::has($name) && ( ! self::needsAccount($name) || Accounts::filled($name) || Accounts::cookie($name) !== '');
    }

    // whether the tracker can list a user's topics
    public static function watchesUsers(string $name): bool
    {
        if ( ! isset(self::TRACKERS[$name]))
            return FALSE;
        $method = new \ReflectionMethod(self::TRACKERS[$name][0], 'userTopics');
        return $method->getDeclaringClass()->getName() !== Tracker::class;
    }

    // tracker object; one per run so that the session is not created again
    public static function get(string $name): Tracker
    {
        if ( ! isset(self::TRACKERS[$name]))
            throw new \InvalidArgumentException('Неизвестный трекер: '.$name);
        if ( ! isset(self::$instances[$name]))
        {
            $class = self::TRACKERS[$name][0];
            self::$instances[$name] = new $class($name, App::http());
        }
        return self::$instances[$name];
    }

    public static function reset(): void
    {
        self::$instances = array();
    }

    // Detects the tracker and the topic id from the topic address.
    /** @return array{tracker:string,topicId:string}|null */
    public static function fromUrl(string $url): ?array
    {
        $host = preg_replace('/^www\./', '', strtolower((string)parse_url($url, PHP_URL_HOST)));
        if ($host === '')
            return NULL;

        $tracker = NULL;
        if (isset(self::HOSTS[$host]))
            $tracker = self::HOSTS[$host];
        elseif (isset(self::TRACKERS[$host]))
            $tracker = $host;
        else
        {
            // address (mirror) set by the user in the tracker settings
            foreach (array_keys(self::TRACKERS) as $name)
            {
                foreach (preg_split('/[\s,;]+/', Accounts::get($name)['address'], -1, PREG_SPLIT_NO_EMPTY) as $address)
                {
                    $own = parse_url(strpos($address, '://') === FALSE ? 'https://'.$address : $address, PHP_URL_HOST);
                    if (preg_replace('/^www\./', '', strtolower((string)$own)) === $host)
                        $tracker = $name;
                }
            }
        }
        if ($tracker === NULL || self::kind($tracker) != 'topic')
            return NULL;

        $topicId = self::get($tracker)->topicIdFromUrl($url);
        return empty($topicId) ? NULL : array('tracker' => $tracker, 'topicId' => $topicId);
    }
}
