<?php
//Runs the topic check. Usually called on a schedule: php engine.php
//  --tracker=rutracker.org  check only this tracker
//  --topic=12               check only the topic with this number
//  --dry-run                show the updates found without downloading or changing anything
//  --fresh                  look for a new version of TorrentMonitor now, not from the answer kept for six hours
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
        elseif ($argument == '--fresh')
            $options['fresh'] = TRUE;
        elseif (preg_match('/^--(tracker|topic)=(.+)$/', $argument, $m))
            $options[$m[1]] = $m[2];
        else
        {
            echo "php engine.php [--tracker=<трекер>] [--topic=<номер темы>] [--dry-run] [--fresh]\n";
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
