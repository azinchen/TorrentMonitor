<?php
// Migrates TorrentMonitor 3.x data from a database (SQLite, MySQL/MariaDB,
// PostgreSQL) to the JSON files of the new version.
//
// A standalone program: it does not use TorrentMonitor files and changes nothing
// in the database (read-only). Run once by hand:
//
//   php tools/convert-db.php            check and migrate
//   php tools/convert-db.php --dry-run  only show what would be migrated
//   php tools/convert-db.php --help     all options

const FORMAT_VERSION = 1;

function usage()
{
    return <<<TEXT
Перенос данных TorrentMonitor из базы данных в JSON-файлы.

Использование: php tools/convert-db.php [параметры]

Откуда брать данные (по умолчанию - из config.php прежней установки):
  --config=ФАЙЛ      прежний config.php с настройками db.* и encryption.key
                     (по умолчанию config.php в каталоге TorrentMonitor)
  --type=ТИП         sqlite, mysql или pgsql
  --db=ИМЯ           файл базы SQLite или имя базы MySQL/PostgreSQL
  --host=АДРЕС       сервер базы данных
  --port=ПОРТ        порт сервера
  --user=ИМЯ         пользователь базы данных
  --password=ПАРОЛЬ  пароль пользователя базы данных
  --charset=КОДИРОВКА  кодировка соединения MySQL (например utf8)
  --key=КЛЮЧ         ключ шифрования (64 шестнадцатеричных знака), если пароли в базе
                     зашифрованы, а в config.php ключа нет
  --key-file=ФАЙЛ    файл с ключом шифрования (в Docker-образе: db/db_crypt.key)
  --config-xml=ФАЙЛ  прежний config.xml с прокси для отдельных трекеров
                     (по умолчанию config.xml в каталоге TorrentMonitor)

Куда записывать:
  --data=КАТАЛОГ     каталог данных (по умолчанию переменная TM_DATA_DIR,
                     иначе data в каталоге TorrentMonitor)
  --force            заменить уже существующие файлы данных
  --dry-run          ничего не записывать, только показать итог

Параметры командной строки важнее значений из config.php.
После переноса строки db.* и encryption.key в config.php больше не нужны.

TEXT;
}

function fail($message)
{
    fwrite(STDERR, 'Ошибка: '.$message."\n");
    exit(1);
}

function say($message)
{
    echo $message."\n";
}

// ---- options ------------------------------------------------------------------

$options = getopt('', array(
    'config:', 'type:', 'db:', 'host:', 'port:', 'user:', 'password:', 'charset:', 'key:', 'key-file:',
    'config-xml:', 'data:', 'force', 'dry-run', 'help',
));
if (isset($options['help']))
{
    echo usage();
    exit(0);
}

$root = dirname(__DIR__);
$dryRun = isset($options['dry-run']);

// Config::write('name', 'value') values from the old config.php. The file is not executed,
// only read as text; commented-out lines are skipped.
function readOldConfig($file)
{
    $values = array();
    if ( ! is_file($file))
        return $values;
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line)
    {
        $line = trim($line);
        if ($line === '' || $line[0] == '#' || strpos($line, '//') === 0)
            continue;
        if (preg_match('/Config::write\(\s*([\'"])(.+?)\1\s*,\s*([\'"])(.*?)\3\s*\)/', $line, $m))
            $values[$m[2]] = stripslashes($m[4]);
    }
    return $values;
}

$configFile = isset($options['config']) ? $options['config'] : $root.'/config.php';
if (isset($options['config']) && ! is_file($configFile))
    fail('файл не найден: '.$configFile);
$old = readOldConfig($configFile);

$pick = function ($option, $configKey) use ($options, $old) {
    if (isset($options[$option]))
        return $options[$option];
    return isset($old[$configKey]) ? $old[$configKey] : '';
};
$db = array(
    'type'     => $pick('type', 'db.type'),
    'name'     => $pick('db', 'db.basename'),
    'host'     => $pick('host', 'db.host'),
    'port'     => $pick('port', 'db.port'),
    'user'     => $pick('user', 'db.user'),
    'password' => $pick('password', 'db.password'),
    'charset'  => $pick('charset', 'db.charset'),
);
if ($db['type'] === '')
    fail("не указан тип базы данных. Его нет в $configFile; задайте --config=ФАЙЛ или --type и --db.\n\n".usage());
if ($db['name'] === '')
    fail('не указано имя базы данных (--db)');

// encryption key
$keyHex = isset($options['key']) ? $options['key'] : (isset($old['encryption.key']) ? $old['encryption.key'] : '');
$keyFile = isset($options['key-file']) ? $options['key-file'] : '';
if ($keyHex === '' && $keyFile === '' && $db['type'] == 'sqlite' && is_file(dirname($db['name']).'/db_crypt.key'))
    $keyFile = dirname($db['name']).'/db_crypt.key';
if ($keyHex === '' && $keyFile !== '')
{
    if ( ! is_file($keyFile))
        fail('файл с ключом не найден: '.$keyFile);
    $keyHex = trim(file_get_contents($keyFile));
}
$keyHex = trim($keyHex);
if ($keyHex === '{ENCRYPTION_KEY}')
    $keyHex = '';
if ($keyHex !== '' && ! preg_match('/^[0-9a-fA-F]{64}$/', $keyHex))
    fail('ключ шифрования должен состоять из 64 шестнадцатеричных знаков');
$key = $keyHex === '' ? NULL : hex2bin($keyHex);

// ---- reading the database ------------------------------------------------------

try
{
    switch ($db['type'])
    {
        case 'sqlite':
            if ( ! is_file($db['name']))
                fail('файл базы данных не найден: '.$db['name']);
            $pdo = new PDO('sqlite:'.$db['name']);
            break;
        case 'mysql':
            $dsn = 'mysql:host='.($db['host'] ?: 'localhost').($db['port'] ? ';port='.$db['port'] : '').';dbname='.$db['name']
                .($db['charset'] ? ';charset='.$db['charset'] : '');
            $pdo = new PDO($dsn, $db['user'], $db['password']);
            break;
        case 'pgsql':
            $dsn = 'pgsql:host='.($db['host'] ?: 'localhost').($db['port'] ? ';port='.$db['port'] : '').';dbname='.$db['name'];
            $pdo = new PDO($dsn, $db['user'], $db['password']);
            break;
        default:
            fail('неизвестный тип базы данных: '.$db['type'].' (нужен sqlite, mysql или pgsql)');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}
catch (PDOException $e)
{
    $drivers = implode(', ', PDO::getAvailableDrivers());
    fail('не удалось подключиться к базе данных: '.$e->getMessage()."\nДоступные драйверы PDO: ".($drivers ?: 'нет'));
}

// all rows of a table; tables missing from the old database are treated as empty
function rows(PDO $pdo, $table)
{
    try
    {
        return $pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
    }
    catch (PDOException $e)
    {
        return array();
    }
}

function byId($a, $b)
{
    return (int)$a['id'] - (int)$b['id'];
}

// decrypts values saved by version 3.x with encryption.key (AES-256-GCM, enc: prefix)
$undecrypted = array();
$decrypt = function ($value, $what) use ($key, &$undecrypted) {
    $value = (string)$value;
    if (strpos($value, 'enc:') !== 0)
        return $value;
    if ($key !== NULL)
    {
        $raw = base64_decode(substr($value, 4));
        $ivLength = openssl_cipher_iv_length('aes-256-gcm');
        $plain = openssl_decrypt(substr($raw, $ivLength + 16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, $ivLength), substr($raw, $ivLength, 16));
        if ($plain !== FALSE)
            return $plain;
    }
    $undecrypted[] = $what;
    return '';
};
if ( ! function_exists('openssl_decrypt'))
    $key = NULL;

$notes = array();

// settings. Old databases may contain duplicate rows for one key:
// the first non-empty value is used.
$stateKeys = array('cf_userAgent', 'sentUpdateNotification');
$settingRows = rows($pdo, 'settings');
if (empty($settingRows))
    fail('в базе нет таблицы settings или она пуста: это не база TorrentMonitor?');
usort($settingRows, 'byId');
$buckets = array('config' => array(), 'state' => array());
foreach ($settingRows as $row)
{
    $name = $row['key'];
    if ($name == 'dbVersion')
        continue;
    $value = $name == 'torrentPassword' ? $decrypt($row['val'], 'пароль torrent-клиента') : (string)$row['val'];
    $bucket = in_array($name, $stateKeys) ? 'state' : 'config';
    if (array_key_exists($name, $buckets[$bucket]))
    {
        if ($buckets[$bucket][$name] === '' && $value !== '')
            $buckets[$bucket][$name] = $value;
        $notes[$name] = 'настройка "'.$name.'" записана в базе несколько раз, взято значение: '
            .(in_array($name, array('password', 'torrentPassword', 'ApiKey')) ? '(скрыто)' : '"'.$buckets[$bucket][$name].'"');
        continue;
    }
    $buckets[$bucket][$name] = $value;
}
$settings = $buckets['config'];
$stateSettings = $buckets['state'];

// tracker credentials and cookies
$trackers = array();
$cookies = array();
foreach (rows($pdo, 'credentials') as $row)
{
    $tracker = $row['tracker'];
    $login = (string)$row['log'];
    $password = $decrypt($row['pass'], 'пароль трекера '.$tracker);
    $passkey = $decrypt(isset($row['passkey']) ? $row['passkey'] : '', 'passkey трекера '.$tracker);
    $cookie = $decrypt(isset($row['cookie']) ? $row['cookie'] : '', 'cookie трекера '.$tracker);
    if (trim($login) !== '' || trim($password) !== '' || $passkey !== '')
        $trackers[$tracker] = array('login' => $login, 'password' => $password, 'passkey' => $passkey);
    if ($cookie !== '')
        $cookies[$tracker] = $cookie;
}

// notification service addresses
$notifications = array();
foreach (rows($pdo, 'notifications') as $row)
    if ((string)$row['address'] !== '')
        $notifications[(string)$row['id']] = (string)$row['address'];

// topics
$now = date('Y-m-d H:i:s');
$topics = array();
$topicRows = rows($pdo, 'torrent');
usort($topicRows, 'byId');
foreach ($topicRows as $row)
{
    $timestamp = (string)$row['timestamp'];
    $topics[] = array(
        'id'             => (int)$row['id'],
        'tracker'        => (string)$row['tracker'],
        'name'           => (string)$row['name'],
        'hd'             => (int)$row['hd'],
        'path'           => (string)$row['path'],
        'torrent_id'     => (string)$row['torrent_id'],
        'ep'             => (string)$row['ep'],
        'timestamp'      => $timestamp === '' ? '0000-00-00 00:00:00' : $timestamp,
        'auto_update'    => (int)$row['auto_update'],
        'hash'           => (string)$row['hash'],
        'script'         => (string)$row['script'],
        'pause'          => (int)$row['pause'],
        'error'          => (int)$row['error'],
        'closed'         => isset($row['closed']) ? (int)$row['closed'] : 0,
        'category'       => isset($row['category']) ? (string)$row['category'] : '',
        // the old database did not know when a topic was added; the torrent's date is used as the update time
        'addedAt'        => $now,
        'lastActivityAt' => (strpos($timestamp, '0000') === 0 || strpos($timestamp, '2000-01-01') === 0 || strtotime($timestamp) === FALSE) ? '' : $timestamp,
    );
}

// monitored users and the topics found for them
$users = array();
$userRows = rows($pdo, 'watch');
usort($userRows, 'byId');
foreach ($userRows as $row)
    $users[] = array('id' => (int)$row['id'], 'tracker' => (string)$row['tracker'], 'name' => (string)$row['name']);

$buffer = array();
$bufferRows = rows($pdo, 'buffer');
usort($bufferRows, 'byId');
foreach ($bufferRows as $row)
    $buffer[] = array(
        'id' => (int)$row['id'], 'user_id' => (int)$row['user_id'], 'section' => (string)$row['section'],
        'threme_id' => (int)$row['threme_id'], 'threme' => (string)$row['threme'], 'timestamp' => (string)$row['timestamp'],
        'accept' => (int)$row['accept'], 'downloaded' => (int)$row['downloaded'], 'new' => (int)$row['new'],
        'tracker' => (string)$row['tracker'],
    );

// errors, news, the torrent client queue
$warnings = array();
$warningRows = rows($pdo, 'warning');
usort($warningRows, 'byId');
foreach ($warningRows as $row)
    $warnings[] = array(
        'id' => (int)$row['id'], 'time' => (string)$row['time'], 'where' => (string)$row['where'],
        'reason' => (string)$row['reason'], 't_id' => $row['t_id'] === NULL ? NULL : (int)$row['t_id'],
    );

$news = array();
foreach (rows($pdo, 'news') as $row)
    $news[] = array('id' => (string)$row['id'], 'text' => (string)$row['text'], 'new' => (int)$row['new']);

$temp = array();
foreach (rows($pdo, 'temp') as $row)
    $temp[(string)$row['id']] = array('name' => (string)$row['name'], 'path' => (string)$row['path'], 'tracker' => (string)$row['tracker'], 'date' => (string)$row['date']);

// per-tracker proxies from the old config.xml
$trackerProxy = array();
$xmlFile = isset($options['config-xml']) ? $options['config-xml'] : $root.'/config.xml';
if (isset($options['config-xml']) && ! is_file($xmlFile))
    fail('файл не найден: '.$xmlFile);
if (is_file($xmlFile) && function_exists('simplexml_load_file'))
{
    $xml = @simplexml_load_file($xmlFile);
    $parsed = $xml ? json_decode(json_encode($xml), TRUE) : array();
    if ( ! empty($parsed['proxy']) && is_array($parsed['proxy']))
    {
        foreach ($parsed['proxy'] as $tracker => $proxy)
        {
            if ( ! is_array($proxy) || ! isset($proxy['use']))
                continue;
            $entry = array('use' => $proxy['use'] == 'yes' ? 'yes' : 'no');
            if ( ! empty($proxy['address']) && is_string($proxy['address']))
            {
                $entry['address'] = $proxy['address'];
                $entry['type'] = strtoupper(isset($proxy['type']) && is_string($proxy['type']) ? $proxy['type'] : '');
            }
            $trackerProxy[$tracker] = $entry;
        }
    }
}

// ---- result -------------------------------------------------------------------

$maxId = function ($list) {
    $max = 0;
    foreach ($list as $item)
        $max = max($max, (int)$item['id']);
    return $max + 1;
};

$files = array(
    'config.json' => array(
        'version'       => FORMAT_VERSION,
        'settings'      => $settings,
        'trackers'      => $trackers,
        'notifications' => $notifications,
        'trackerProxy'  => $trackerProxy,
    ),
    'torrents.json' => array(
        'version'    => FORMAT_VERSION,
        'nextId'     => $maxId($topics),
        'torrents'   => $topics,
        'nextUserId' => $maxId($users),
        'users'      => $users,
    ),
    'state.json' => array(
        'version'       => FORMAT_VERSION,
        'settings'      => $stateSettings,
        'lastStart'     => '',
        'cookies'       => $cookies,
        'nextWarningId' => $maxId($warnings),
        'warnings'      => $warnings,
        'news'          => $news,
        'nextBufferId'  => $maxId($buffer),
        'buffer'        => $buffer,
        'temp'          => $temp,
    ),
);

$dataDir = isset($options['data']) ? $options['data'] : (getenv('TM_DATA_DIR') ?: $root.'/data');
$dataDir = rtrim($dataDir, '/\\');

say('База данных: '.$db['type'].' '.$db['name']);
say('Каталог данных: '.$dataDir);
say('');
say('Будет перенесено:');
say('  настроек: '.(count($settings) + count($stateSettings)));
say('  трекеров с учётными данными: '.count($trackers).', сохранённых cookies: '.count($cookies));
say('  адресов уведомлений: '.count($notifications));
say('  тем: '.count($topics));
say('  отслеживаемых пользователей: '.count($users).', найденных у них тем: '.count($buffer));
say('  ошибок: '.count($warnings).', новостей: '.count($news).', раздач в очереди к torrent-клиенту: '.count($temp));
say('  прокси для отдельных трекеров: '.count($trackerProxy));

if ($notes)
{
    say('');
    say('Замечания:');
    foreach ($notes as $note)
        say('  - '.$note);
}
if ($undecrypted)
{
    say('');
    say('Не удалось расшифровать (нет ключа шифрования или он не подходит). После переноса введите заново:');
    foreach (array_unique($undecrypted) as $what)
        say('  - '.$what);
    say('Чтобы перенести эти значения, укажите ключ: --key=КЛЮЧ или --key-file=ФАЙЛ.');
}
if (isset($settings['password']) && preg_match('/^[0-9a-f]{32}$/', $settings['password']))
{
    say('');
    say('Пароль на вход хранится в старом формате (MD5). Он продолжит работать и будет');
    say('сохранён в новом формате при первом входе в веб-интерфейс.');
}

if ($dryRun)
{
    say('');
    say('Пробный запуск: ничего не записано.');
    exit(0);
}

$existing = array();
foreach (array_keys($files) as $name)
    if (is_file($dataDir.'/'.$name))
        $existing[] = $name;
if ($existing && ! isset($options['force']))
    fail('в каталоге данных уже есть '.implode(', ', $existing).'. Чтобы заменить их, добавьте --force.');

if ( ! is_dir($dataDir) && ! @mkdir($dataDir, 0700, TRUE))
    fail('не удалось создать каталог данных: '.$dataDir);
if ( ! is_file($dataDir.'/.htaccess'))
    @file_put_contents($dataDir.'/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");

foreach ($files as $name => $data)
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $tmp = $dataDir.'/'.$name.'.tmp';
    if (@file_put_contents($tmp, $json."\n") === FALSE)
        fail('нет прав на запись в каталог данных: '.$dataDir);
    chmod($tmp, 0600);
    // when run as root, files are handed over to the data directory's owner (usually the web server user)
    if (function_exists('posix_geteuid') && posix_geteuid() === 0)
    {
        @chown($tmp, fileowner($dataDir));
        @chgrp($tmp, filegroup($dataDir));
    }
    if ($existing && is_file($dataDir.'/'.$name))
        @copy($dataDir.'/'.$name, $dataDir.'/'.$name.'.bak');
    if ( ! rename($tmp, $dataDir.'/'.$name))
        fail('не удалось записать '.$dataDir.'/'.$name);
}

say('');
say('Готово. Записаны config.json, torrents.json и state.json.');
say('Что дальше:');
say('  1. Убедитесь, что веб-сервер и пользователь, от которого запускается engine.php,');
say('     могут читать и изменять файлы в '.$dataDir.'.');
say('  2. Удалите из config.php строки db.* и encryption.key (или весь файл, если других настроек в нём нет).');
say('  3. Откройте веб-интерфейс и проверьте темы и настройки. База данных не изменена: её можно оставить как резервную копию.');
