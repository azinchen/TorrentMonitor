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
        foreach (array_merge((array)glob(self::dir().'/*.json'), (array)glob(self::dir().'/*.json.bak'), (array)glob(self::dir().'/*.log')) as $file)
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
        //The settings are gone and the topics are not: that is not a new installation. Treating it
        //as one would offer the first setup, with a new password, to whoever opens the address.
        if ( ! self::config()->exists() && self::torrents()->exists())
            throw new StorageException('Файл настроек отсутствует: '.self::config()->file().', хотя файл тем есть'
                .(is_file(self::config()->file().'.bak') ? '. Предыдущая версия файла сохранена рядом: config.json.bak'
                    : '. Верните его из резервной копии; чтобы начать заново, удалите и torrents.json'));
        self::config()->read();
        self::torrents()->read();
        self::state()->read();
        //a damaged state file was put aside and started anew: said once, in the journal
        if (self::state()->recovered !== NULL)
        {
            $aside = self::state()->recovered;
            self::state()->recovered = NULL;
            \TorrentMonitor\Data\Journal::add('data', 'Файл state.json был повреждён и начат заново: сохранённые сессии трекеров, отметки ошибок '
                .'и найденные темы пользователей сброшены. Прежний файл сохранён как '.$aside);
        }
    }

    // The data file that stops the program and can be brought back from its copy: array('file' =>
    // 'config.json', 'time' => when the copy was made, 'Y-m-d H:i:s'), or NULL when there is none.
    public static function restorable(): ?array
    {
        foreach (array('config.json' => self::config(), 'torrents.json' => self::torrents()) as $name => $store)
        {
            $file = $store->file();
            $broken = FALSE;
            if ($name == 'config.json' && ! $store->exists() && self::torrents()->exists())
                $broken = TRUE;
            else
            {
                try
                {
                    $store->read();
                }
                catch (StorageException $e)
                {
                    $broken = TRUE;
                }
            }
            if ( ! $broken || ! is_file($file.'.bak'))
                continue;
            //the copy must be a file the program can read
            $copy = json_decode((string)@file_get_contents($file.'.bak'), TRUE);
            if ( ! is_array($copy) || ! isset($copy['version']) || ! is_int($copy['version']))
                continue;
            try
            {
                call_user_func(array(Repair::class, $name == 'config.json' ? 'config' : 'torrents'), $copy + ($name == 'torrents.json' && $copy['version'] < self::TORRENTS_VERSION ? array('topics' => array()) : array()));
            }
            catch (Damaged $e)
            {
                continue;
            }
            return array('file' => $name, 'time' => date('Y-m-d H:i:s', (int)@filemtime($file.'.bak')));
        }
        return NULL;
    }

    // Puts the copy in place of the damaged file, which is kept as <file>.broken. FALSE if there is nothing to restore.
    public static function restore(): bool
    {
        $what = self::restorable();
        if ($what === NULL)
            return FALSE;
        $file = self::dir().'/'.$what['file'];
        if (is_file($file) && ! @rename($file, $file.'.broken'))
            return FALSE;
        if ( ! @copy($file.'.bak', $file))
            return FALSE;
        self::protect($file);
        if (is_file($file.'.broken'))
            self::protect($file.'.broken');
        self::$stores = array();
        \TorrentMonitor\Data\Journal::add('data', 'Файл '.$what['file'].' был повреждён и возвращён из копии от '.$what['time']
            .'. Повреждённый файл сохранён как '.$what['file'].'.broken');
        return TRUE;
    }

    public static function config(): JsonStore
    {
        return self::store('config.json', self::configDefaults(), self::CONFIG_VERSION, array(), TRUE, array(Repair::class, 'config'));
    }

    public static function torrents(): JsonStore
    {
        return self::store('torrents.json', self::torrentsDefaults(), self::TORRENTS_VERSION, array(1 => array(Migrations::class, 'torrents1')), TRUE, array(Repair::class, 'torrents'));
    }

    public static function state(): JsonStore
    {
        return self::store('state.json', self::stateDefaults(), self::STATE_VERSION, array(1 => array(Migrations::class, 'state1')), FALSE, array(Repair::class, 'state'), TRUE);
    }

    private static function store($name, $defaults, $version, $migrations, $backup, $repair = NULL, $recover = FALSE): JsonStore
    {
        if ( ! isset(self::$stores[$name]))
        {
            self::prepare();
            self::$stores[$name] = new JsonStore(self::dir().'/'.$name, $defaults, $version, $migrations, $backup, NULL, $repair, $recover);
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
            'autoUpdate'           => '0',
            // how many lines the journal of events keeps; 0 - no journal
            'journalSize'          => '1000',
            // how many detailed logs of check runs are kept (engine.log, engine.1.log ...)
            'debugKeep'            => '10',
            // access mode of the data files, three octal digits (640); empty - the owner only
            'dataFileMode'         => '',
            // what automatic updates install: stable, beta or dev
            'updateChannel'        => 'stable',
            // time zone the trackers show their dates in; a tracker may have its own
            'trackerTimezone'      => 'Europe/Moscow',
            'userAgent'            => 'Mozilla/5.0 (X11; Linux x86_64; rv:133.0) Gecko/20100101 Firefox/133.0',
            'httpTimeout'          => '15',
            // requests one tracker gets at the same time; empty - curl.concurrency of config.php, else 10
            'httpConcurrency'      => '',
            // trackers asked at the same time
            'httpSites'            => '3',
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
            // the version of the program files at the previous start ('' - not known yet): a different
            // one now means the program was updated from outside, by a new Docker image for example
            'program'        => '',
            // result of the last check run, one line: shown above its log
            'lastRunSummary' => '',
            // what the torrent client and the Cloudflare solver said about themselves when they were last
            // talked to: 'client' | 'solver' => array(name, version, details, address, at)
            'services'       => array(),
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
