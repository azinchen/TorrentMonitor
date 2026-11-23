<?php
namespace TorrentMonitor\Storage;

// All TorrentMonitor data is stored in three JSON files in the data directory:
//   config.json   - settings, tracker credentials, notification addresses (changed only by the user)
//   torrents.json - watched topics and users
//   state.json    - working state: cookies, errors, news, queues (changes on every run)
final class Files
{
    const CONFIG_VERSION   = 1;
    const TORRENTS_VERSION = 2;
    const STATE_VERSION    = 2;

    private static array $stores = array();
    private static ?string $dir = NULL;

    // Data directory: the TM_DATA_DIR environment variable, then Config::write('data.dir', ...)
    // in config.php, otherwise the data directory next to the program.
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

    // Creates the data directory and closes it from being served by the Apache web server
    public static function prepare(): bool
    {
        $dir = self::dir();
        if ( ! is_dir($dir))
            @mkdir($dir, 0700, TRUE);
        if (is_dir($dir) && ! is_file($dir.'/.htaccess'))
            @file_put_contents($dir.'/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        return is_dir($dir) && is_writable($dir);
    }

    // Whether the data has already been created (settings or topics were saved at least once)
    public static function initialized(): bool
    {
        return is_file(self::dir().'/config.json') || is_file(self::dir().'/torrents.json');
    }

    // Reads all data files; StorageException if any of them is corrupted or inaccessible
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

    // Settings of a new installation
    public static function settingsDefaults(): array
    {
        return array(
            'serverAddress'        => '',
            'auth'                 => '1',
            // Password hash (bcrypt). Empty until the setup form sets it: there is no default password.
            'password'             => '',
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
            // After how many days without updates to stop checking a topic (0 - never)
            'stopAfterDays'        => '0',
            // Delete the topic and the torrent when Sonarr reports that the series was deleted
            'removeOnSeriesDelete' => '0',
            // When Sonarr grabs a season pack: delete the older topic of the same series and season
            'sonarrReplaceTopic'   => '0',
            // ...and remove its torrent from the torrent client (only together with the previous one)
            'sonarrReplaceTorrent' => '0',
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
            // tracker => array('login' => ..., 'password' => ..., 'passkey' => ..., 'address' => ...)
            'trackers'      => array(),
            // Notification service number (see Notify\Services) => address or key
            'notifications' => array(),
            // tracker => array('use' => 'yes'|'no', 'address' => 'host:port', 'type' => 'SOCKS5'|'HTTP')
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
            // "Remember me" logins: sha256 of the token => expiry time (unix)
            'logins'         => array(),
            'lastRun'        => '',
            // tracker => session cookie string
            'cookies'        => array(),
            // host => array('cookie' => ..., 'userAgent' => ..., 'at' => ...): passed Cloudflare challenges
            'clearance'      => array(),
            'nextWarningId'  => 1,
            'warnings'       => array(),
            'news'           => array(),
            // Topics found for watched users
            'nextFoundId'    => 1,
            'found'          => array(),
            // Torrents that could not be handed to the torrent client: topic id => array(name, file, tracker, date)
            'pending'        => array(),
        );
    }
}
