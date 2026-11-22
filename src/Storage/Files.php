<?php
namespace TorrentMonitor\Storage;

//Все данные TorrentMonitor хранятся в трёх JSON-файлах в каталоге данных:
//  config.json   - настройки, учётные данные трекеров, адреса уведомлений (меняется только пользователем)
//  torrents.json - отслеживаемые темы и пользователи
//  state.json    - рабочее состояние: cookies, ошибки, новости, очереди (меняется при каждом запуске)
final class Files
{
    const CONFIG_VERSION   = 1;
    const TORRENTS_VERSION = 2;
    const STATE_VERSION    = 2;

    private static array $stores = array();
    private static ?string $dir = NULL;

    //Каталог данных: переменная окружения TM_DATA_DIR, затем Config::write('data.dir', ...)
    //в config.php, иначе каталог data рядом с программой.
    public static function dir(): string
    {
        if (self::$dir === NULL)
        {
            $dir = getenv('TM_DATA_DIR');
            if (empty($dir))
                $dir = \Config::read('data.dir');
            if (empty($dir))
                $dir = dirname(__DIR__, 2).'/data';
            self::$dir = rtrim(str_replace('\\', '/', $dir), '/');
        }
        return self::$dir;
    }

    //создаёт каталог данных и закрывает его от раздачи веб-сервером Apache
    public static function prepare(): bool
    {
        $dir = self::dir();
        if ( ! is_dir($dir))
            @mkdir($dir, 0700, TRUE);
        if (is_dir($dir) && ! is_file($dir.'/.htaccess'))
            @file_put_contents($dir.'/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        return is_dir($dir) && is_writable($dir);
    }

    //были ли данные уже созданы (хотя бы один раз сохранены настройки или темы)
    public static function initialized(): bool
    {
        return is_file(self::dir().'/config.json') || is_file(self::dir().'/torrents.json');
    }

    //читает все файлы данных; StorageException, если какой-то из них повреждён или недоступен
    public static function check(): void
    {
        self::config()->read();
        self::torrents()->read();
        self::state()->read();
    }

    public static function config(): JsonStore
    {
        return self::store('config.json', self::configDefaults(), self::CONFIG_VERSION, array(), TRUE);
    }

    public static function torrents(): JsonStore
    {
        return self::store('torrents.json', self::torrentsDefaults(), self::TORRENTS_VERSION, array(1 => array(Migrations::class, 'torrents1')), TRUE);
    }

    public static function state(): JsonStore
    {
        return self::store('state.json', self::stateDefaults(), self::STATE_VERSION, array(1 => array(Migrations::class, 'state1')), FALSE);
    }

    private static function store($name, $defaults, $version, $migrations, $backup): JsonStore
    {
        if ( ! isset(self::$stores[$name]))
        {
            self::prepare();
            self::$stores[$name] = new JsonStore(self::dir().'/'.$name, $defaults, $version, $migrations, $backup);
        }
        return self::$stores[$name];
    }

    //настройки новой установки
    public static function settingsDefaults(): array
    {
        return array(
            'serverAddress'        => '',
            'auth'                 => '1',
            //пароль по умолчанию: torrentmonitor (при первом входе хеш заменяется на bcrypt)
            'password'             => '1f10c9fd49952a7055531975c06c5bd8',
            'ApiKey'               => '',
            'rss'                  => '1',
            'debug'                => '0',
            'debugFor'             => '',
            'autoUpdate'           => '0',
            'userAgent'            => 'Mozilla/5.0 (X11; Linux x86_64; rv:133.0) Gecko/20100101 Firefox/133.0',
            'httpTimeout'          => '15',
            'flaresolverrUrl'      => '',
            'proxy'                => '0',
            'proxyAddress'         => '127.0.0.1:9050',
            'proxyType'            => '',
            'useTorrent'           => '0',
            'torrentClient'        => '',
            'torrentAddress'       => '127.0.0.1:9091',
            'torrentLogin'         => '',
            'torrentPassword'      => '',
            'pathToDownload'       => '',
            'qbitCategory'         => '',
            'deleteDistribution'   => '0',
            'deleteOldFiles'       => '0',
            'deleteFilesOnRemove'  => '0',
            //через сколько дней без обновлений прекращать проверку темы (0 - никогда)
            'stopAfterDays'        => '0',
            //удалять тему и раздачу, когда Sonarr сообщает об удалении сериала
            'removeOnSeriesDelete' => '0',
            'send'                 => '0',
            'sendUpdate'           => '0',
            'sendUpdateService'    => '',
            'sendWarning'          => '0',
            'sendWarningService'   => '',
        );
    }

    private static function configDefaults(): array
    {
        return array(
            'settings'      => self::settingsDefaults(),
            //трекер => array('login' => ..., 'password' => ..., 'passkey' => ..., 'address' => ...)
            'trackers'      => array(),
            //номер сервиса уведомлений (см. Notify\Services) => адрес или ключ
            'notifications' => array(),
            //трекер => array('use' => 'yes'|'no', 'address' => 'хост:порт', 'type' => 'SOCKS5'|'HTTP')
            'trackerProxy'  => array(),
        );
    }

    private static function torrentsDefaults(): array
    {
        return array(
            'nextId'     => 1,
            'topics'     => array(),
            'nextUserId' => 1,
            'users'      => array(),
        );
    }

    private static function stateDefaults(): array
    {
        return array(
            'updateNotified' => FALSE,
            //входы «запомнить меня»: sha256 от токена => время окончания (unix)
            'logins'         => array(),
            'lastRun'        => '',
            //трекер => строка cookie сессии
            'cookies'        => array(),
            //хост => array('cookie' => ..., 'userAgent' => ..., 'at' => ...): пройденные проверки Cloudflare
            'clearance'      => array(),
            'nextWarningId'  => 1,
            'warnings'       => array(),
            'news'           => array(),
            //темы, найденные у отслеживаемых пользователей
            'nextFoundId'    => 1,
            'found'          => array(),
            //раздачи, которые не удалось передать в torrent-клиент: номер темы => array(name, file, tracker, date)
            'pending'        => array(),
        );
    }
}
