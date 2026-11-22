<?php
//Запуск проверки тем. Обычно вызывается по расписанию: php engine.php
//  --tracker=rutracker.org  проверить только этот трекер
//  --topic=12               проверить только тему с этим номером
//  --dry-run                показать найденные обновления, ничего не скачивая и не меняя
require_once __DIR__.'/src/bootstrap.php';

use TorrentMonitor\App;
use TorrentMonitor\Engine\Runner;

$cli = PHP_SAPI == 'cli';
$options = array();
if ($cli)
{
    foreach (array_slice($argv, 1) as $argument)
    {
        if ($argument == '--dry-run')
            $options['dryRun'] = TRUE;
        elseif (preg_match('/^--(tracker|topic)=(.+)$/', $argument, $m))
            $options[$m[1]] = $m[2];
        else
        {
            echo "php engine.php [--tracker=<трекер>] [--topic=<номер темы>] [--dry-run]\n";
            exit($argument == '--help' ? 0 : 1);
        }
    }
}

set_time_limit(0);
ignore_user_abort(TRUE);

$say = function ($line) use ($cli) {
    echo '['.date('Y-m-d H:i:s').'] '.($cli ? $line."\n" : nl2br(htmlspecialchars($line))."<br />\n");
    if ( ! $cli)
        @flush();
};

$problem = App::storageProblem();
if ($problem !== NULL)
{
    $say($problem);
    if ($cli)
        exit(1);
    return;
}

$ok = (new Runner($say, $options))->run();
if ($cli)
    exit($ok ? 0 : 1);
