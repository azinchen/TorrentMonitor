<?php
include_once dirname(__FILE__).'/Config.class.php';
include_once dirname(__FILE__).'/JsonStore.class.php';

//Все данные TorrentMonitor хранятся в трёх JSON-файлах в каталоге данных:
//  config.json   - настройки, учётные данные трекеров, адреса уведомлений (меняется только пользователем)
//  torrents.json - отслеживаемые темы и пользователи
//  state.json    - рабочее состояние: cookies, ошибки, новости, очереди (меняется при каждом запуске)
class Storage
{
    const CONFIG_VERSION   = 1;
    const TORRENTS_VERSION = 1;
    const STATE_VERSION    = 1;

    private static $stores = array();
    private static $dir = NULL;

    //Каталог данных: переменная окружения TM_DATA_DIR, затем Config::write('data.dir', ...)
    //в config.php, иначе каталог data рядом с программой.
    public static function dir()
    {
        if (self::$dir === NULL)
        {
            $dir = getenv('TM_DATA_DIR');
            if (empty($dir))
                $dir = Config::read('data.dir');
            if (empty($dir))
                $dir = dirname(dirname(__FILE__)).'/data';
            self::$dir = rtrim(str_replace('\\', '/', $dir), '/');
        }
        return self::$dir;
    }

    //создаёт каталог данных и закрывает его от раздачи веб-сервером Apache
    public static function prepare()
    {
        $dir = self::dir();
        if ( ! is_dir($dir))
            @mkdir($dir, 0700, TRUE);
        if (is_dir($dir) && ! is_file($dir.'/.htaccess'))
            @file_put_contents($dir.'/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        return is_dir($dir) && is_writable($dir);
    }

    //были ли данные уже созданы (хотя бы один раз сохранены настройки или темы)
    public static function initialized()
    {
        return is_file(self::dir().'/config.json') || is_file(self::dir().'/torrents.json');
    }

    public static function config()
    {
        return self::store('config.json', self::configDefaults(), self::CONFIG_VERSION, array(), TRUE);
    }

    public static function torrents()
    {
        return self::store('torrents.json', self::torrentsDefaults(), self::TORRENTS_VERSION, array(), TRUE);
    }

    public static function state()
    {
        return self::store('state.json', self::stateDefaults(), self::STATE_VERSION, array(), FALSE);
    }

    private static function store($name, $defaults, $version, $migrations, $backup)
    {
        if ( ! isset(self::$stores[$name]))
        {
            self::prepare();
            self::$stores[$name] = new JsonStore(self::dir().'/'.$name, $defaults, $version, $migrations, $backup);
        }
        return self::$stores[$name];
    }

    //настройки новой установки
    public static function settingsDefaults()
    {
        return array(
            'serverAddress'       => '',
            'auth'                => '1',
            //пароль по умолчанию: torrentmonitor (при первом входе хеш заменяется на bcrypt)
            'password'            => '1f10c9fd49952a7055531975c06c5bd8',
            'ApiKey'              => '',
            'rss'                 => '1',
            'debug'               => '0',
            'debugFor'            => '',
            'autoUpdate'          => '0',
            'userAgent'           => 'Mozilla/5.0 (X11; Linux x86_64; rv:133.0) Gecko/20100101 Firefox/133.0',
            'httpTimeout'         => '15',
            'flaresolverrUrl'     => '',
            'proxy'               => '0',
            'proxyAddress'        => '127.0.0.1:9050',
            'proxyType'           => '',
            'useTorrent'          => '0',
            'torrentClient'       => '',
            'torrentAddress'      => '127.0.0.1:9091',
            'torrentLogin'        => '',
            'torrentPassword'     => '',
            'pathToDownload'      => '',
            'qbitCategory'        => '',
            'deleteDistribution'  => '0',
            'deleteOldFiles'      => '0',
            'deleteFilesOnRemove' => '0',
            'send'                => '0',
            'sendUpdate'          => '0',
            'sendUpdateService'   => '',
            'sendWarning'         => '0',
            'sendWarningService'  => '',
        );
    }

    //настройки, которые меняет сама программа: хранятся в state.json, а не в config.json
    public static function stateSettingsDefaults()
    {
        return array(
            'cf_userAgent'           => '',
            'sentUpdateNotification' => '0',
        );
    }

    private static function configDefaults()
    {
        return array(
            'settings'      => self::settingsDefaults(),
            //трекер => array('login' => ..., 'password' => ..., 'passkey' => ...)
            'trackers'      => array(),
            //номер сервиса уведомлений (см. Catalog::notifications()) => адрес или ключ
            'notifications' => array(),
            //трекер => array('use' => 'yes'|'no', 'address' => 'хост:порт', 'type' => 'SOCKS5'|'HTTP')
            'trackerProxy'  => array(),
        );
    }

    private static function torrentsDefaults()
    {
        return array(
            'nextId'     => 1,
            'torrents'   => array(),
            'nextUserId' => 1,
            'users'      => array(),
        );
    }

    private static function stateDefaults()
    {
        return array(
            'settings'      => self::stateSettingsDefaults(),
            'lastStart'     => '',
            //трекер => строка cookie сессии
            'cookies'       => array(),
            'nextWarningId' => 1,
            'warnings'      => array(),
            'news'          => array(),
            //темы, найденные у отслеживаемых пользователей
            'nextBufferId'  => 1,
            'buffer'        => array(),
            //раздачи, которые не удалось передать в torrent-клиент
            'temp'          => array(),
        );
    }
}

//Справочники, которые раньше были строками таблиц базы данных.
class Catalog
{
    //трекер => array(id, type, necessarily). Номера сохранены прежними: их использует интерфейс.
    public static function trackers()
    {
        return array(
            'rutracker.org'     => array('id' => 1,  'type' => 'forum', 'necessarily' => 1),
            'nnmclub.to'        => array('id' => 2,  'type' => 'forum', 'necessarily' => 1),
            'lostfilm.tv'       => array('id' => 3,  'type' => 'RSS',   'necessarily' => 1),
            'rutor.is'          => array('id' => 5,  'type' => 'forum', 'necessarily' => 0),
            'tfile.cc'          => array('id' => 6,  'type' => 'forum', 'necessarily' => 0),
            'kinozal.me'        => array('id' => 7,  'type' => 'forum', 'necessarily' => 1),
            'anidub.com'        => array('id' => 8,  'type' => 'forum', 'necessarily' => 1),
            'baibako.tv'        => array('id' => 9,  'type' => 'RSS',   'necessarily' => 1),
            'casstudio.tk'      => array('id' => 10, 'type' => 'forum', 'necessarily' => 1),
            'newstudio.tv'      => array('id' => 11, 'type' => 'RSS',   'necessarily' => 1),
            'animelayer.ru'     => array('id' => 12, 'type' => 'forum', 'necessarily' => 1),
            'rustorka.com'      => array('id' => 14, 'type' => 'forum', 'necessarily' => 1),
            'pornolab.net'      => array('id' => 15, 'type' => 'forum', 'necessarily' => 1),
            'lostfilm-mirror'   => array('id' => 16, 'type' => 'RSS',   'necessarily' => 0),
            'hamsterstudio.org' => array('id' => 17, 'type' => 'RSS',   'necessarily' => 1),
            'booktracker.org'   => array('id' => 19, 'type' => 'forum', 'necessarily' => 1),
            'baibako.tv_forum'  => array('id' => 20, 'type' => 'forum', 'necessarily' => 1),
            'riperam.org'       => array('id' => 21, 'type' => 'forum', 'necessarily' => 1),
            'kinozal.tv'        => array('id' => 22, 'type' => 'forum', 'necessarily' => 1),
            'kinozal.guru'      => array('id' => 23, 'type' => 'forum', 'necessarily' => 1),
        );
    }

    public static function trackerById($id)
    {
        foreach (self::trackers() as $tracker => $info)
            if ($info['id'] == $id)
                return $tracker;
        return NULL;
    }

    //номер => array(service, type)
    public static function notifications()
    {
        $list = array();
        $id = 1;
        foreach (array('E-mail', 'Prowl', 'Pushbullet', 'Pushover', 'Pushall', 'Telegram', 'Gotify') as $service)
        {
            $list[$id++] = array('service' => $service, 'type' => 'notification');
            $list[$id++] = array('service' => $service, 'type' => 'warning');
        }
        return $list;
    }
}
?>
