<?php
//The web server's only entry point. The site root is the public directory: the program, settings
//and data live one level up and cannot be reached from a browser.
use TorrentMonitor\App;
use TorrentMonitor\Web\Api;
use TorrentMonitor\Web\Feed;
use TorrentMonitor\Web\Ui;

$path = rawurldecode((string)parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH));

//PHP built-in server (php -S ... public/index.php): it serves existing files itself
if (PHP_SAPI == 'cli-server')
{
    if ($path != '/' && ! preg_match('/\.php$/i', $path) && strpos($path, '..') === FALSE && is_file(__DIR__.$path))
        return FALSE;
}
else
{
    //installed in a subdirectory of the site (http://host/tm/)
    $base = rtrim(str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/')), '/');
    if ($base !== '' && strncmp($path, $base.'/', strlen($base) + 1) === 0)
        $path = substr($path, strlen($base));
}
$path = '/'.trim($path, '/');

require_once dirname(__DIR__).'/src/bootstrap.php';

//a warning of PHP printed into an answer would break it for the page and show the paths of the server:
//warnings go to the error log of PHP, and unexpected errors are answered in words (see App::unexpected)
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if ($path == '/api' || strncmp($path, '/api/', 5) === 0)
{
    $segments = array_values(array_filter(explode('/', substr($path, 4)), 'strlen'));
    if (isset($segments[0]) && $segments[0] == 'index.php')
        array_shift($segments);
    Api::handle($segments);
}
elseif (strncmp($path, '/ui/', 4) === 0)
    Ui::handle(substr($path, 4));
elseif ($path == '/rss' || $path == '/rss/index.php')
    Feed::handle();
elseif (strncmp($path, '/torrents/', 10) === 0)
    Feed::torrent(substr($path, 10));
elseif ($path == '/' || $path == '/index.php')
{
    //there is a single page: it requests its data itself (see src/Web/Ui.php)
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    //The address of the scripts and styles changes whenever the files do, so a browser never runs
    //an old script with a new page. The version number alone is not enough: a copy installed from
    //the version in development keeps its number from one change to the next.
    $changed = 0;
    foreach ((array)glob(__DIR__.'/assets/{*,vendor/*}', GLOB_BRACE) as $asset)
        $changed = max($changed, (int)@filemtime($asset));
    echo str_replace('{{version}}', rawurlencode(App::version().'-'.dechex($changed)), (string)file_get_contents(dirname(__DIR__).'/src/Web/page.html'));
}
else
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Не найдено.';
}
