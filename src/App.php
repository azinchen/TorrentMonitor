<?php
namespace TorrentMonitor;

use TorrentMonitor\Data\Accounts;
use TorrentMonitor\Data\Settings;
use TorrentMonitor\Data\State;
use TorrentMonitor\Http\Client;
use TorrentMonitor\Storage\Files;

// Installation paths and shared objects.
final class App
{
    private static ?Client $http = NULL;
    /** @var callable|null */
    private static $logger = NULL;

    public static function root(): string
    {
        return dirname(__DIR__);
    }

    public static function version(): string
    {
        $version = json_decode((string)@file_get_contents(self::root().'/version.txt'), TRUE);
        return isset($version['system']) ? (string)$version['system'] : '0';
    }

    // The version as it is shown: a copy installed from the dev channel also names its commit
    public static function versionLabel(): string
    {
        $version = json_decode((string)@file_get_contents(self::root().'/version.txt'), TRUE);
        $commit = isset($version['commit']) ? (string)$version['commit'] : '';
        return self::version().($commit !== '' ? ' (dev '.substr($commit, 0, 7).')' : '');
    }

    // Directory of saved torrent files
    public static function torrentsDir(): string
    {
        $dir = \Config::read('torrents.dir');
        if (empty($dir))
            $dir = self::root().'/torrents';
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if ( ! is_dir($dir))
            @mkdir($dir, 0755, TRUE);
        return $dir;
    }

    // Message for an installation migrated from a version with a database whose data
    // has not been converted yet; NULL if everything is fine.
    public static function legacyDatabaseNotice(): ?string
    {
        if (Files::initialized() || empty(\Config::read('db.type')))
            return NULL;
        return 'В config.php найдены настройки базы данных от предыдущей версии TorrentMonitor, '
            .'а каталог данных '.Files::dir().' пуст. Эта версия хранит данные в JSON-файлах и с базой данных не работает. '
            .'Перенесите данные командой: php tools/convert-db.php (подробности: php tools/convert-db.php --help). '
            .'Чтобы начать с чистой установки, удалите строки db.* из config.php.';
    }

    // The reason the program cannot work (data not migrated or corrupted), or NULL
    public static function storageProblem(): ?string
    {
        try
        {
            $notice = self::legacyDatabaseNotice();
            if ($notice === NULL)
            {
                Files::check();
                Data\State::sawVersion();
            }
            return $notice;
        }
        catch (Storage\StorageException $e)
        {
            return $e->getMessage();
        }
    }

    // An error nobody expected: instead of the raw text of PHP (with the paths of the server) the
    // user gets one sentence, and the journal and the PHP error log get the place it happened in.
    // $where - what was being done. Returns the sentence.
    public static function unexpected(\Throwable $e, string $where): string
    {
        $place = basename($e->getFile()).':'.$e->getLine();
        $text = 'Внутренняя ошибка программы ('.$where.'): '.$e->getMessage().' ['.$place.']';
        error_log('TorrentMonitor: '.get_class($e).' in '.$e->getFile().':'.$e->getLine().': '.$e->getMessage());
        try
        {
            Data\Journal::add('error', $text);
        }
        catch (\Throwable $ignored)
        {
            //the journal may be what is broken
        }
        return $text.'. Она записана в журнал; сообщите о ней разработчикам.';
    }

    // Where to write the HTTP client's debug lines (function(string): void) or NULL
    public static function setLogger(?callable $logger): void
    {
        self::$logger = $logger;
        self::$http = NULL;
    }

    public static function log(string $line): void
    {
        if (self::$logger !== NULL)
            call_user_func(self::$logger, $line);
    }

    // HTTP client with the user's settings
    public static function http(): Client
    {
        if (self::$http === NULL)
        {
            $settings = Settings::all();
            self::$http = new Client(array(
                'userAgent'    => $settings['userAgent'],
                'timeout'      => $settings['httpTimeout'],
                //the setting of the interface; without it the former setting of config.php
                'concurrency'  => $settings['httpConcurrency'] !== '' ? $settings['httpConcurrency'] : \Config::read('curl.concurrency'),
                'sites'        => $settings['httpSites'],
                'solverUrl'    => $settings['flaresolverrUrl'],
                'proxy'        => array('enabled' => ! empty($settings['proxy']), 'address' => $settings['proxyAddress'], 'type' => $settings['proxyType']),
                'trackerProxy' => Accounts::proxies(),
                'log'          => self::$logger,
                //the detailed log names these services instead of showing where they are
                'private'      => array_filter(array((string)$settings['torrentAddress'] => 'torrent-клиент', (string)$settings['flaresolverrUrl'] => 'решатель Cloudflare'),
                    function ($address) { return $address !== ''; }, ARRAY_FILTER_USE_KEY),
            ), new State());
        }
        return self::$http;
    }

    public static function resetHttp(): void
    {
        self::$http = NULL;
    }
}
