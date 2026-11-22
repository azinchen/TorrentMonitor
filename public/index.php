<?php
//Единственная точка входа веб-сервера. Корень сайта - каталог public: программа, настройки
//и данные лежат уровнем выше и из браузера недоступны.
use TorrentMonitor\App;
use TorrentMonitor\Web\Api;
use TorrentMonitor\Web\Feed;
use TorrentMonitor\Web\Ui;

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
    //страница одна: данные она запрашивает сама (см. src/Web/Ui.php)
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    echo str_replace('{{version}}', rawurlencode(App::version()), (string)file_get_contents(dirname(__DIR__).'/src/Web/page.html'));
}
else
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Не найдено.';
}
