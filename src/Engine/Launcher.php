<?php
namespace TorrentMonitor\Engine;

use TorrentMonitor\App;
use TorrentMonitor\Tracker\Registry;

//Запуск проверки в фоне (из API и веб-интерфейса).
final class Launcher
{
    //Запускает engine.php отдельным процессом. Возвращает NULL при успехе или причину, по которой это невозможно.
    //$options: tracker, topic
    public static function start(array $options = array()): ?string
    {
        if ( ! function_exists('exec'))
            return 'Функция exec отключена в PHP, запустить проверку в фоне нельзя.';
        $php = self::phpBinary();
        if ($php === NULL)
            return 'CLI-интерпретатор PHP не найден. Укажите путь в config.php: Config::write(\'php.cli\', \'/usr/bin/php8.3\');';

        $command = escapeshellarg($php).' '.escapeshellarg(App::root().'/engine.php');
        if ( ! empty($options['tracker']) && Registry::has((string)$options['tracker']))
            $command .= ' '.escapeshellarg('--tracker='.$options['tracker']);
        if ( ! empty($options['topic']))
            $command .= ' --topic='.(int)$options['topic'];
        exec($command.' > /dev/null 2>&1 &');
        return NULL;
    }

    public static function phpBinary(): ?string
    {
        $configured = \Config::read('php.cli');
        if ( ! empty($configured))
            return is_executable($configured) ? $configured : NULL;
        if (PHP_SAPI === 'cli')
            return PHP_BINARY;
        $candidates = array(
            PHP_BINDIR.'/php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
            PHP_BINDIR.'/php'.PHP_MAJOR_VERSION.PHP_MINOR_VERSION,
            PHP_BINDIR.'/php', '/usr/local/bin/php', '/usr/bin/php',
        );
        foreach ($candidates as $candidate)
            if (@is_executable($candidate))
                return $candidate;
        return NULL;
    }
}
