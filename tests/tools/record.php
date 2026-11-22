<?php
// Fetches a tracker page with the session TorrentMonitor already has and prints
// it with account data removed, to be saved as a test fixture.
// Run with: tests/run.sh record <tracker> <url> <fixture-name>
require '/src/tests/bootstrap.php';
chdir('/app');
include_once '/app/config.php';
include_once '/app/class/System.class.php';
include_once '/app/class/Database.class.php';

list(, $tracker, $url) = $argv + array(null, null, null);
if (empty($tracker) || empty($url))
{
    fwrite(STDERR, "usage: record.php <tracker> <url>\n");
    exit(2);
}

$cookie = (string)Database::getCookie($tracker);
$page = Sys::getUrlContent(array(
    'type'           => 'GET',
    'returntransfer' => 1,
    'url'            => $url,
    'cookie'         => $cookie,
));
if (empty($page))
{
    fwrite(STDERR, "empty answer from $url\n");
    exit(1);
}

$secrets = Env::secrets('trackers');
if ($cookie !== '')
    $secrets['SESSION_COOKIE'] = $cookie;
$clean = Scrubber::scrub($page, $secrets);

$leaks = Scrubber::leaks($clean, $secrets);
if ($leaks)
{
    fwrite(STDERR, 'refusing to output: still contains '.implode(', ', $leaks)."\n");
    exit(1);
}
echo $clean;
