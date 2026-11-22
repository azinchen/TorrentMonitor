<?php
//Единственная точка входа веб-сервера. Корень сайта - каталог public: программа, настройки
//и данные лежат уровнем выше и из браузера недоступны.
use TorrentMonitor\App;
use TorrentMonitor\Web\Api;
use TorrentMonitor\Web\Feed;

$path = rawurldecode((string)parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH));

//встроенный сервер PHP (php -S ... public/index.php): готовые файлы отдаёт он сам
if (PHP_SAPI == 'cli-server')
{
    if ($path != '/' && ! preg_match('/\.php$/i', $path) && strpos($path, '..') === FALSE && is_file(__DIR__.$path))
        return FALSE;
}
else
{
    //установка в подкаталоге сайта (http://host/tm/)
    $base = rtrim(str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/')), '/');
    if ($base !== '' && strncmp($path, $base.'/', strlen($base) + 1) === 0)
        $path = substr($path, strlen($base));
}
$path = '/'.trim($path, '/');

require_once dirname(__DIR__).'/src/bootstrap.php';
$legacy = dirname(__DIR__).'/legacy/';

if ($path == '/api' || strncmp($path, '/api/', 5) === 0)
{
    $segments = array_values(array_filter(explode('/', substr($path, 4)), 'strlen'));
    if (isset($segments[0]) && $segments[0] == 'index.php')
        array_shift($segments);
    Api::handle($segments);
}
elseif ($path == '/rss' || $path == '/rss/index.php')
    Feed::handle();
elseif (strncmp($path, '/torrents/', 10) === 0)
    Feed::torrent(substr($path, 10));
elseif ($path == '/action.php')
    require $legacy.'action.php';
elseif (preg_match('~^/include/([a-z_]+)\.php$~', $path, $m) && is_file($legacy.'include/'.$m[1].'.php'))
    require $legacy.'include/'.$m[1].'.php';
elseif ($path == '/' || $path == '/index.php')
{
    require_once $legacy.'bootstrap.php';
    $problem = App::storageProblem();
    if ($problem !== NULL)
    {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>TorrentMonitor</title><body style="font-family:sans-serif;max-width:46em;margin:3em auto;line-height:1.5">'
            .'<h1>TorrentMonitor не может начать работу</h1><p>'.htmlspecialchars($problem).'</p></body>';
        return;
    }
    if (session_status() !== PHP_SESSION_ACTIVE)
        session_start();
    if (empty($_SESSION['csrf_token']))
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    require $legacy.(Sys::checkAuth() ? 'pages/main.php' : 'pages/auth.php');
}
else
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Не найдено.';
}
