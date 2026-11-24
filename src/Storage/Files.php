<?php
namespace TorrentMonitor\Storage;

// All TorrentMonitor data is stored in three JSON files in the data directory:
//   config.json   - settings, tracker credentials, notification addresses (changed only by the user)
//   torrents.json - watched topics and users
//   state.json    - working state: cookies, errors, queues (changes on every run)
final class Files
{
    // Format of each file. Do not raise these for a new section or field: add it with a default
    // instead (see JsonStore), so that older versions of the program keep working with the files.
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

    //the dataFileMode setting as read from config.json: FALSE - not read yet, NULL - not set
    private static $mode = FALSE;

    // Whether the text is an access mode for the data files: three octal digits (a leading 0 is
    // allowed), the owner must keep reading and writing
    public static function validMode(string $text): bool
    {
        return (bool)preg_match('/^0?[67][0-7]{2}$/', $text);
    }

    // The access mode from the settings, or NULL when it is not set. It is read straight from the
    // file, not through the store: this runs while the store is writing that very file.
    private static function mode(): ?int
    {
        if (self::$mode === FALSE)
        {
            $config = json_decode((string)@file_get_contents(self::dir().'/config.json'), TRUE);
            $text = isset($config['settings']['dataFileMode']) ? (string)$config['settings']['dataFileMode'] : '';
            self::$mode = self::validMode($text) ? octdec($text) : NULL;
        }
        return self::$mode;
    }

    // Tells the mode that is being saved right now (the file does not hold it yet) and gives it to
    // the files that exist; '' - back to the default
    public static function setMode(string $text): void
    {
        self::$mode = self::validMode($text) ? octdec($text) : NULL;
        foreach (array_merge((array)glob(self::dir().'/*.json'), (array)glob(self::dir().'/*.json.bak')) as $file)
            self::protect($file);
    }

    // Access to a file of the data directory. By default: the owner only, and the group if the
    // directory is set up for two users (web server and cron); nobody else, the files hold
    // passwords. The dataFileMode setting replaces that with a mode of the user's choice.
    public static function protect(string $file): void
    {
        $dir = dirname($file);
        $dirMode = @fileperms($dir);
        $mode = self::mode();
        @chmod($file, $mode !== NULL ? $mode : (($dirMode !== FALSE && ($dirMode & 0020)) ? 0660 : 0600));
        // engine.php run as root must not take the files away from the web server user
        if (function_exists('posix_geteuid') && posix_geteuid() === 0)
        {
            @chown($file, fileowner($dir));
            @chgrp($file, filegroup($dir));
        }
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
            // access mode of the data files, three octal digits (640); empty - the owner only
            'dataFileMode'         => '',
            // what automatic updates install: stable, beta or dev
            'updateChannel'        => 'stable',
            // time zone the trackers show their dates in; a tracker may have its own
            'trackerTimezone'      => 'Europe/Moscow',
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
            // the update channel was changed, its version is not installed yet
            'channelChanged' => FALSE,
            // "Remember me" logins: sha256 of the token => expiry time (unix)
            'logins'         => array(),
            'lastRun'        => '',
            // tracker => session cookie string
            'cookies'        => array(),
            // host => array('cookie' => ..., 'userAgent' => ..., 'at' => ...): passed Cloudflare challenges
            'clearance'      => array(),
            'nextWarningId'  => 1,
            'warnings'       => array(),
            // Topics found for watched users
            'nextFoundId'    => 1,
            'found'          => array(),
            // Torrents that could not be handed to the torrent client: topic id => array(name, file, tracker, date)
            'pending'        => array(),
        );
    }
}
