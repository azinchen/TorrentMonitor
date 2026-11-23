<?php
// Fake internet for the test stack. One PHP built-in server answers for:
//   - rutracker.org (reached over TLS through nginx in this container)
//   - GitHub Releases API           /github/repos/<owner>/<repo>/releases/latest
//   - a FlareSolverr/Byparr solver  /solver/v1
//   - test control                  /__control/reset | set | state | requests
// Behaviour is driven by a JSON state file that tests change through /__control/set.

require_once '/src/tests/Support/TorrentBuilder.php';

const STATE_DIR  = '/tmp/fake-state';
const STATE_FILE = STATE_DIR.'/state.json';
const LOG_FILE   = STATE_DIR.'/requests.jsonl';
const CF_COOKIE  = 'cf_clearance=fake-clearance';

function default_state()
{
    return array(
        'github' => array(
            'mode'    => '404',          // 404 = no releases, ok = release below, 500 = GitHub down
            'version' => '9.9.9',
            'notes'   => "Что нового:\n- пункт <b>один</b>\n- пункт два",
        ),
        'rutracker' => array(
            'login'      => 'tm-user',
            'password'   => 'tm-pass',
            'session'    => 'fake-session-0001',
            'cloudflare' => false,       // true = answer with a Cloudflare challenge page
            'cloudflareStrict' => false, // true = the clearance cookie does not help: only the solver's browser passes
            'down'       => false,       // true = answer 502 with an empty body
            // topic id => array('title' => ..., 'date' => '21-Ноя-26 10:00', 'revision' => 1)
            'topics'     => array(),
        ),
    );
}

function load_state()
{
    $state = json_decode((string)@file_get_contents(STATE_FILE), true);
    return is_array($state) ? $state : default_state();
}

function save_state($state)
{
    @mkdir(STATE_DIR, 0777, true);
    file_put_contents(STATE_FILE, json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function send($status, $body, $headers = array())
{
    http_response_code($status);
    foreach ($headers as $header)
        header($header, false);
    echo $body;
}

function send_json($data, $status = 200)
{
    send($status, json_encode($data, JSON_UNESCAPED_UNICODE), array('Content-Type: application/json'));
}

// ---- rutracker.org ---------------------------------------------------------

function cp1251($html)
{
    return mb_convert_encoding($html, 'windows-1251', 'UTF-8');
}

function rutracker_page($title, $inner)
{
    return "<html>\n<head>\n<meta charset=\"windows-1251\">\n<title>".$title."</title>\n</head>\n<body>\n".$inner."\n</body>\n</html>\n";
}

// Returns array(status, headers, body). Bodies are windows-1251, as on the real site.
function rutracker_response($state, $method, $path, $query, $cookie, $post, $viaSolver)
{
    $rt = $state['rutracker'];

    if ( ! empty($rt['down']))
        return array(502, array(), '');

    if ( ! empty($rt['cloudflare']) && ! $viaSolver && ( ! empty($rt['cloudflareStrict']) || strpos($cookie, CF_COOKIE) === false))
    {
        $body = '<html><head><title>Just a moment...</title></head><body>Checking your browser before accessing. Cloudflare</body></html>';
        return array(403, array('Content-Type: text/html; charset=UTF-8'), $body);
    }

    $html = array('Content-Type: text/html; charset=windows-1251');
    $loggedIn = strpos($cookie, 'bb_session='.$rt['session']) !== false;
    $guestBar = '<a href="profile.php?mode=register">Регистрация</a> <a href="login.php">Вход</a>';
    $userBar  = '<a href="profile.php?mode=viewprofile&amp;u=1">'.$rt['login'].'</a>';

    if ($path == '/forum/index.php')
        return array(200, $html, cp1251(rutracker_page('RuTracker.org', $loggedIn ? $userBar : $guestBar)));

    if ($path == '/forum/login.php')
    {
        parse_str($post, $form);
        $ok = isset($form['login_username'], $form['login_password'])
            && $form['login_username'] === $rt['login'] && $form['login_password'] === $rt['password'];
        if ($method == 'POST' && $ok)
            return array(302, array('Location: index.php', 'Set-Cookie: bb_session='.$rt['session'].'; path=/forum/; HttpOnly'), '');
        return array(200, $html, cp1251(rutracker_page('RuTracker.org', $guestBar.' Вы ввели неверное имя или пароль')));
    }

    parse_str((string)$query, $params);
    $topicId = isset($params['t']) ? (string)$params['t'] : '';
    $topic = isset($rt['topics'][$topicId]) ? $rt['topics'][$topicId] : null;

    if ($path == '/forum/viewtopic.php')
    {
        if ($topic === null)
            return array(200, $html, cp1251(rutracker_page('RuTracker.org', 'Тема не найдена')));
        $inner = ($loggedIn ? $userBar : $guestBar)."\n"
            ."<table>\n<tr>\n"
            ."\t<td style=\"width: 70%; padding: 5px;\">\n"
            ."\t\t<ul class=\"inlined middot-separated\">\n"
            ."\t\t\t<li>".$topic['date']."</li>\n"
            ."\t\t\t<li>Скачан: 12 раз</li>\n"
            ."\t\t</ul>\n"
            ."\t</td>\n</tr>\n</table>";
        return array(200, $html, cp1251(rutracker_page($topic['title'].' :: RuTracker.org', $inner)));
    }

    if ($path == '/forum/dl.php')
    {
        if ($topic === null || ! $loggedIn || $method != 'POST')
            return array(200, $html, cp1251(rutracker_page('RuTracker.org', $guestBar)));
        $torrent = TorrentBuilder::torrent($topicId, $topic['revision'], 'https://rutracker.org/forum/viewtopic.php?t='.$topicId);
        return array(200, array('Content-Type: application/x-bittorrent', 'Content-Disposition: attachment; filename="[rutracker.org].t'.$topicId.'.torrent"'), $torrent);
    }

    return array(404, $html, cp1251(rutracker_page('RuTracker.org', 'Not found')));
}

// ---- request handling ------------------------------------------------------

$state  = load_state();
$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query  = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
$host   = preg_replace('/^www\./', '', strtolower(preg_replace('/:\d+$/', '', isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '')));
$cookie = isset($_SERVER['HTTP_COOKIE']) ? $_SERVER['HTTP_COOKIE'] : '';
$body   = file_get_contents('php://input');

// control endpoints are not logged
if (strpos($path, '/__control/') === 0)
{
    if ($path == '/__control/reset')
    {
        save_state(default_state());
        @unlink(LOG_FILE);
        return send_json(array('ok' => true));
    }
    if ($path == '/__control/set')
    {
        $state = array_replace_recursive($state, (array)json_decode($body, true));
        save_state($state);
        return send_json($state);
    }
    if ($path == '/__control/state')
        return send_json($state);
    if ($path == '/__control/requests')
    {
        $rows = array();
        foreach (is_file(LOG_FILE) ? file(LOG_FILE, FILE_IGNORE_NEW_LINES) : array() as $line)
            $rows[] = json_decode($line, true);
        return send_json($rows);
    }
    return send_json(array('ok' => false), 404);
}

@mkdir(STATE_DIR, 0777, true);
file_put_contents(LOG_FILE, json_encode(array(
    'host'      => $host,
    'method'    => $method,
    'path'      => $path,
    'query'     => $query,
    'cookie'    => $cookie,
    'userAgent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
    'body'      => substr($body, 0, 2000),
), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n", FILE_APPEND | LOCK_EX);

if ($host == 'rutracker.org')
{
    list($status, $headers, $out) = rutracker_response($state, $method, $path, $query, $cookie, $body, false);
    return send($status, $out, $headers);
}

// GitHub Releases API
if (preg_match('#^/github/repos/[^/]+/[^/]+/releases/latest$#', $path))
{
    $gh = $state['github'];
    if ($gh['mode'] == '404')
        return send_json(array('message' => 'Not Found'), 404);
    if ($gh['mode'] == '500')
        return send(500, 'Server Error');
    $base = 'http://'.$_SERVER['HTTP_HOST'].'/github';
    return send_json(array(
        'tag_name'    => 'v'.$gh['version'],
        'html_url'    => $base.'/releases/tag/v'.$gh['version'],
        'body'        => str_replace("\n", "\r\n", $gh['notes']),
        'zipball_url' => $base.'/zipball',
        'assets'      => array(array(
            'name'                 => 'TorrentMonitor-'.$gh['version'].'.zip',
            'browser_download_url' => $base.'/release.zip',
        )),
    ));
}

// release archive: the code under test with another version number and one extra file
if ($path == '/github/release.zip')
{
    $version = $state['github']['version'];
    $file = STATE_DIR.'/release-'.$version.'.zip';
    @unlink($file);
    $zip = new ZipArchive;
    $zip->open($file, ZipArchive::CREATE);
    $prefix = 'TorrentMonitor-'.$version.'/';
    $skip = array('.git', '.github', '.claude', 'tests', 'tools', 'wiki', 'dist', 'config.php', 'data');
    $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator('/src', FilesystemIterator::SKIP_DOTS),
        function ($current) use ($skip) {
            return $current->getPath() != '/src' || ! in_array($current->getFilename(), $skip);
        }
    ));
    foreach ($iterator as $item)
    {
        $relative = substr($item->getPathname(), strlen('/src/'));
        if ($relative == 'version.txt')
            $zip->addFromString($prefix.$relative, json_encode(array('system' => $version, 'database' => $version)));
        else
            $zip->addFile($item->getPathname(), $prefix.$relative);
    }
    $zip->addFromString($prefix.'src/AddedByRelease.php', "<?php // file that exists only in the fake release\n");
    $zip->close();
    header('Content-Type: application/zip');
    readfile($file);
    return;
}

// FlareSolverr / Byparr API: "solves" the challenge of the fake tracker
if ($path == '/solver/v1' && $method == 'POST')
{
    $request = (array)json_decode($body, true);
    $url = parse_url(isset($request['url']) ? $request['url'] : '');
    $targetHost = preg_replace('/^www\./', '', isset($url['host']) ? $url['host'] : '');
    if ($targetHost != 'rutracker.org')
        return send_json(array('status' => 'error', 'message' => 'unknown host'), 500);

    $cookies = array();
    foreach (isset($request['cookies']) ? (array)$request['cookies'] : array() as $c)
        $cookies[$c['name']] = $c['value'];
    $cookieHeader = '';
    foreach ($cookies as $name => $value)
        $cookieHeader .= $name.'='.$value.'; ';

    $isPost = isset($request['cmd']) && $request['cmd'] == 'request.post';
    list($status, $headers, $out) = rutracker_response(
        $state, $isPost ? 'POST' : 'GET', $url['path'], isset($url['query']) ? $url['query'] : '',
        $cookieHeader, $isPost && isset($request['postData']) ? $request['postData'] : '', true
    );
    // a browser keeps the cookies it was given and follows the redirect after login
    foreach ($headers as $header)
        if (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/', $header, $m))
            $cookies[$m[1]] = $m[2];
    if ($status == 302)
    {
        $cookieHeader = '';
        foreach ($cookies as $name => $value)
            $cookieHeader .= $name.'='.$value.'; ';
        list($status, $headers, $out) = rutracker_response($state, 'GET', '/forum/index.php', '', $cookieHeader, '', true);
    }
    $cookies['cf_clearance'] = 'fake-clearance';

    $solutionCookies = array();
    foreach ($cookies as $name => $value)
        $solutionCookies[] = array('name' => $name, 'value' => $value, 'domain' => '.rutracker.org', 'path' => '/');

    $isHtml = (bool)preg_grep('#^Content-Type: text/html#', $headers);
    return send_json(array(
        'status'   => 'ok',
        'message'  => 'Challenge solved!',
        'solution' => array(
            'url'       => $request['url'],
            'status'    => $status,
            // a real solver returns the rendered page, always in UTF-8
            'response'  => $isHtml ? mb_convert_encoding($out, 'UTF-8', 'windows-1251') : '',
            'cookies'   => $solutionCookies,
            'userAgent' => 'Mozilla/5.0 (FakeSolver) TorrentMonitorTests/1.0',
        ),
    ));
}

send(404, 'fake: no handler for '.$host.$path);
