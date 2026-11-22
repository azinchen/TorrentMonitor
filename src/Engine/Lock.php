<?php
namespace TorrentMonitor\Engine;

use TorrentMonitor\Storage\Files;

//Блокировка запуска: два запуска одновременно не работают.
final class Lock
{
    /** @var resource|null */
    private static $handle = NULL;

    public static function acquire(): bool
    {
        if (self::$handle !== NULL)
            return TRUE;
        Files::prepare();
        $handle = @fopen(Files::dir().'/engine.lock', 'c');
        if ($handle === FALSE)
            return TRUE; //каталог недоступен для записи: об этом сообщит хранилище
        if ( ! flock($handle, LOCK_EX | LOCK_NB))
        {
            fclose($handle);
            return FALSE;
        }
        self::$handle = $handle;
        return TRUE;
    }

    public static function release(): void
    {
        if (self::$handle === NULL)
            return;
        flock(self::$handle, LOCK_UN);
        fclose(self::$handle);
        self::$handle = NULL;
    }

    //идёт ли сейчас запуск (в другом процессе)
    public static function busy(): bool
    {
        if (self::$handle !== NULL)
            return TRUE;
        $handle = @fopen(Files::dir().'/engine.lock', 'c');
        if ($handle === FALSE)
            return FALSE;
        $free = flock($handle, LOCK_EX | LOCK_NB);
        if ($free)
            flock($handle, LOCK_UN);
        fclose($handle);
        return ! $free;
    }
}
