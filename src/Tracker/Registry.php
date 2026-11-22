<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\App;
use TorrentMonitor\Data\Accounts;

//Список поддерживаемых трекеров.
final class Registry
{
    //трекер => array(класс, вид: topic - тема форума, series - сериал из RSS, номер в старом интерфейсе)
    const TRACKERS = array(
        'rutracker.org'    => array(Rutracker::class, 'topic', 1),
        'nnmclub.to'       => array(Nnmclub::class, 'topic', 2),
        'lostfilm.tv'      => array(Lostfilm::class, 'series', 3),
        'rutor.is'         => array(Rutor::class, 'topic', 5),
        'tfile.cc'         => array(Tfile::class, 'topic', 6),
        'kinozal.me'       => array(Kinozal::class, 'topic', 7),
        'anidub.com'       => array(Anidub::class, 'topic', 8),
        'baibako.tv'       => array(Baibako::class, 'series', 9),
        'newstudio.tv'     => array(Newstudio::class, 'series', 11),
        'animelayer.ru'    => array(Animelayer::class, 'topic', 12),
        'rustorka.com'     => array(Rustorka::class, 'topic', 14),
        'pornolab.net'     => array(Pornolab::class, 'topic', 15),
        'lostfilm-mirror'  => array(LostfilmMirror::class, 'series', 16),
        'booktracker.org'  => array(Booktracker::class, 'topic', 19),
        'baibako.tv_forum' => array(BaibakoForum::class, 'topic', 20),
        'riperam.org'      => array(Riperam::class, 'topic', 21),
        'kinozal.tv'       => array(Kinozal::class, 'topic', 22),
        'kinozal.guru'     => array(Kinozal::class, 'topic', 23),
    );

    //другие адреса тех же трекеров: хост => трекер
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

    //topic или series; NULL для неизвестного трекера
    public static function kind(string $name): ?string
    {
        return isset(self::TRACKERS[$name]) ? self::TRACKERS[$name][1] : NULL;
    }

    public static function needsAccount(string $name): bool
    {
        return isset(self::TRACKERS[$name]) && constant(self::TRACKERS[$name][0].'::NEEDS_ACCOUNT');
    }

    //можно ли работать с трекером: учётные данные (или cookie сессии) заданы или не нужны
    public static function ready(string $name): bool
    {
        return self::has($name) && ( ! self::needsAccount($name) || Accounts::filled($name) || Accounts::cookie($name) !== '');
    }

    //умеет ли трекер показывать темы пользователя
    public static function watchesUsers(string $name): bool
    {
        if ( ! isset(self::TRACKERS[$name]))
            return FALSE;
        $method = new \ReflectionMethod(self::TRACKERS[$name][0], 'userTopics');
        return $method->getDeclaringClass()->getName() !== Tracker::class;
    }

    public static function legacyId(string $name): ?int
    {
        return isset(self::TRACKERS[$name]) ? self::TRACKERS[$name][2] : NULL;
    }

    public static function byLegacyId($id): ?string
    {
        foreach (self::TRACKERS as $name => $definition)
            if ($definition[2] == $id)
                return $name;
        return NULL;
    }

    //объект трекера; один на запуск, чтобы сессия не создавалась заново
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

    //Определяет трекер и номер темы по адресу темы.
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
            //адрес (зеркало), заданный пользователем в настройках трекера
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
