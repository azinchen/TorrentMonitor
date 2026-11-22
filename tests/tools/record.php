<?php
// Saves a scrubbed copy of a tracker page, to build fixtures for parser tests.
// Run with: tests/run.sh record <tracker> <path on the site, e.g. /forum/viewtopic.php?t=1> <fixture name>
// The tracker account must be saved in the running test stack (tests/run.sh up, then the live test or the UI).
require '/src/tests/bootstrap.php';
require '/app/src/bootstrap.php';

use TorrentMonitor\App;
use TorrentMonitor\Data\Accounts;
use TorrentMonitor\Http\Request;
use TorrentMonitor\Tracker\Registry;

list(, $name, $path) = $argv + array(null, '', '/');
if ( ! Registry::has($name))
    exit("unknown tracker: $name\n");
$tracker = Registry::get($name);
try
{
    $tracker->login();
}
catch (Throwable $e)
{
    fwrite(STDERR, 'login failed: '.$e->getMessage()."\n");
}
$charset = constant(get_class($tracker).'::CHARSET');
$response = App::http()->send(Request::get($tracker->base().$path)->cookie(Accounts::cookie($name))->charset($charset)->tracker($name)->follow());
$account = Accounts::get($name);
$body = Scrubber::scrub($response->body, array('LOGIN' => $account['login'], 'PASSWORD' => $account['password']));
fwrite(STDERR, 'HTTP '.$response->status.', '.strlen($body)." bytes\n");
echo $body;
