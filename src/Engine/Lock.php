<?php
namespace TorrentMonitor\Engine;

use TorrentMonitor\Storage\Files;

// Run lock: two runs do not work at the same time.
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
            return TRUE; // The directory is not writable: the storage will report that
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

    // Whether a run is in progress right now (in another process)
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
