<?php
namespace TorrentMonitor;

use TorrentMonitor\Data\Accounts;
use TorrentMonitor\Data\Settings;
use TorrentMonitor\Data\State;
use TorrentMonitor\Http\Client;
use TorrentMonitor\Storage\Files;

//Пути установки и общие объекты.
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

    //каталог сохранённых torrent-файлов
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

    //Сообщение для установки, перенесённой с версии с базой данных, данные которой
    //ещё не преобразованы; NULL, если всё в порядке.
    public static function legacyDatabaseNotice(): ?string
    {
        if (Files::initialized() || empty(\Config::read('db.type')))
            return NULL;
        return 'В config.php найдены настройки базы данных от предыдущей версии TorrentMonitor, '
            .'а каталог данных '.Files::dir().' пуст. Эта версия хранит данные в JSON-файлах и с базой данных не работает. '
            .'Перенесите данные командой: php tools/convert-db.php (подробности: php tools/convert-db.php --help). '
            .'Чтобы начать с чистой установки, удалите строки db.* из config.php.';
    }

    //причина, по которой работать нельзя (данные не перенесены или повреждены), или NULL
    public static function storageProblem(): ?string
    {
        try
        {
            $notice = self::legacyDatabaseNotice();
            if ($notice === NULL)
                Files::check();
            return $notice;
        }
        catch (Storage\StorageException $e)
        {
            return $e->getMessage();
        }
    }

    //куда писать отладочные строки HTTP-клиента (function(string): void) или NULL
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

    //HTTP-клиент с настройками пользователя
    public static function http(): Client
    {
        if (self::$http === NULL)
        {
            $settings = Settings::all();
            self::$http = new Client(array(
                'userAgent'    => $settings['userAgent'],
                'timeout'      => $settings['httpTimeout'],
                'concurrency'  => \Config::read('curl.concurrency'),
                'solverUrl'    => $settings['flaresolverrUrl'],
                'proxy'        => array('enabled' => ! empty($settings['proxy']), 'address' => $settings['proxyAddress'], 'type' => $settings['proxyType']),
                'trackerProxy' => Accounts::proxies(),
                'log'          => self::$logger,
            ), new State());
        }
        return self::$http;
    }

    public static function resetHttp(): void
    {
        self::$http = NULL;
    }
}
