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

// names of the columns of a table; empty when the database has no such table
function columns(PDO $pdo, $table)
{
    try
    {
        $statement = $pdo->query('SELECT * FROM '.$table.' WHERE 1 = 0');
        $names = array();
        for ($i = 0; $i < $statement->columnCount(); $i++)
        {
            $meta = $statement->getColumnMeta($i);
            $names[] = (string)$meta['name'];
        }
        return $names;
    }
    catch (PDOException $e)
    {
        return array();
    }
}

// a column that databases of earlier versions do not have yet
function col(array $row, $name, $default = '')
{
    return isset($row[$name]) ? $row[$name] : $default;
}

// Trackers of version 4. Topics of any other tracker are carried over, but cannot be checked.
const SUPPORTED_TRACKERS = array('rutracker.org', 'nnmclub.to', 'lostfilm.tv', 'rutor.is', 'kinozal.me', 'anidub.com', 'newstudio.tv',
    'animelayer.ru', 'rustorka.com', 'pornolab.net', 'lostfilm-mirror', 'booktracker.org', 'riperam.org', 'kinozal.tv', 'kinozal.guru');
// Names trackers had in earlier versions (the updates of 1.6 - 1.8.9.7 renamed them in the database;
// an installation that skipped those updates still has the old ones)
const RENAMED_TRACKERS = array('rutor.org' => 'rutor.is', 'rutor.info' => 'rutor.is',
    'nnm-club.ru' => 'nnmclub.to', 'nnm-club.me' => 'nnmclub.to', 'nnm-club.name' => 'nnmclub.to');

$renamed = array();
$trackerName = function ($name) use (&$renamed) {
    $name = (string)$name;
    if ( ! isset(RENAMED_TRACKERS[$name]))
        return $name;
    $renamed[$name] = RENAMED_TRACKERS[$name];
    return RENAMED_TRACKERS[$name];
};

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
$dbVersion = '';
foreach ($settingRows as $row)
{
    $name = $row['key'];
    if ($name == 'dbVersion')
    {
        $dbVersion = trim((string)$row['val']);
        continue;
    }
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

// Which version the database is of. The layout has been the same since 1.5; version 3.0 added the
// category of a topic, 2.1.7 the userAgent setting. Supported: 2.0 and later (tested: 2.0 and 3.3.6).
$topicColumns = columns($pdo, 'torrent');
$supported = in_array('closed', $topicColumns) && columns($pdo, 'notifications');
if ($dbVersion !== '')
    $databaseVersion = $dbVersion;
elseif (in_array('category', $topicColumns))
    $databaseVersion = '3.0 или новее';
elseif (array_key_exists('userAgent', $settings))
    $databaseVersion = '2.1.7 – 2.2.x';
elseif ($supported)
    $databaseVersion = 'до 2.1.7';
else
    $databaseVersion = 'старше 2.0';
if ( ! $supported)
    $notes['version'] = 'база данных старше версии 2.0: перенос таких баз не проверялся. Проверьте темы и настройки после переноса.';

// tracker credentials and cookies
$trackers = array();
$cookies = array();
foreach (rows($pdo, 'credentials') as $row)
{
    $name = $trackerName($row['tracker']);
    $login = (string)$row['log'];
    $password = $decrypt($row['pass'], 'пароль трекера '.$name);
    $passkey = $decrypt(isset($row['passkey']) ? $row['passkey'] : '', 'passkey трекера '.$name);
    $cookie = $decrypt(isset($row['cookie']) ? $row['cookie'] : '', 'cookie трекера '.$name);
    //an account under the present name of a tracker is not replaced by one under its old name
    if ((trim($login) !== '' || trim($password) !== '' || $passkey !== '') && ! isset($trackers[$name]))
        $trackers[$name] = array('login' => $login, 'password' => $password, 'passkey' => $passkey);
    if ($cookie !== '' && ! isset($cookies[$name]))
        $cookies[$name] = $cookie;
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
    $timestamp = (string)col($row, 'timestamp');
    $topics[] = array(
        'id'             => (int)$row['id'],
        'tracker'        => $trackerName($row['tracker']),
        'name'           => (string)$row['name'],
        'hd'             => (int)col($row, 'hd', 0),
        'path'           => (string)col($row, 'path'),
        'torrent_id'     => (string)$row['torrent_id'],
        'ep'             => (string)col($row, 'ep'),
        'timestamp'      => $timestamp === '' ? '0000-00-00 00:00:00' : $timestamp,
        'auto_update'    => (int)col($row, 'auto_update', 0),
        'hash'           => (string)col($row, 'hash'),
        'script'         => (string)col($row, 'script'),
        'pause'          => (int)col($row, 'pause', 0),
        'error'          => (int)col($row, 'error', 0),
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
    $users[] = array('id' => (int)$row['id'], 'tracker' => $trackerName($row['tracker']), 'name' => (string)$row['name']);

$buffer = array();
$bufferRows = rows($pdo, 'buffer');
usort($bufferRows, 'byId');
foreach ($bufferRows as $row)
    $buffer[] = array(
        'id' => (int)$row['id'], 'user_id' => (int)$row['user_id'], 'section' => (string)$row['section'],
        'threme_id' => (int)$row['threme_id'], 'threme' => (string)$row['threme'], 'timestamp' => (string)col($row, 'timestamp'),
        'accept' => (int)$row['accept'], 'downloaded' => (int)$row['downloaded'], 'new' => (int)$row['new'],
        'tracker' => $trackerName($row['tracker']),
    );

// errors, the torrent client queue
$warnings = array();
$warningRows = rows($pdo, 'warning');
usort($warningRows, 'byId');
foreach ($warningRows as $row)
    $warnings[] = array(
        'id' => (int)$row['id'], 'time' => (string)$row['time'], 'where' => $trackerName($row['where']),
        'reason' => (string)$row['reason'], 't_id' => col($row, 't_id', NULL) === NULL ? NULL : (int)$row['t_id'],
    );

$temp = array();
foreach (rows($pdo, 'temp') as $row)
    $temp[(string)$row['id']] = array('name' => (string)$row['name'], 'path' => (string)$row['path'], 'tracker' => $trackerName($row['tracker']), 'date' => (string)$row['date']);

// what the user should know about trackers
foreach ($renamed as $old => $new)
    $notes['renamed '.$old] = 'трекер '.$old.' теперь называется '.$new.': его темы и учётная запись перенесены под новым названием';
$unsupported = array();
foreach (array_merge($topics, $users) as $item)
    if ( ! in_array($item['tracker'], SUPPORTED_TRACKERS, TRUE))
        $unsupported[$item['tracker']] = (isset($unsupported[$item['tracker']]) ? $unsupported[$item['tracker']] : 0) + 1;
if ($unsupported)
{
    arsort($unsupported);
    $parts = array();
    foreach ($unsupported as $name => $count)
        $parts[] = $name.' — '.$count;
    $notes['unsupported'] = 'версия 4 не работает с трекерами: '.implode(', ', $parts).' (число тем и отслеживаемых пользователей). '
        .'Они перенесены, но проверяться не будут: удалите их в интерфейсе или замените темами другого трекера';
}

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
        'nextBufferId'  => $maxId($buffer),
        'buffer'        => $buffer,
        'temp'          => $temp,
    ),
);

$dataDir = isset($options['data']) ? $options['data'] : (getenv('TM_DATA_DIR') ?: $root.'/data');
$dataDir = rtrim($dataDir, '/\\');

say('База данных: '.$db['type'].' '.$db['name'].', версия '.$databaseVersion);
say('Каталог данных: '.$dataDir);
say('');
say('Будет перенесено:');
say('  настроек: '.(count($settings) + count($stateSettings)));
say('  трекеров с учётными данными: '.count($trackers).', сохранённых cookies: '.count($cookies));
say('  адресов уведомлений: '.count($notifications));
say('  тем: '.count($topics));
say('  отслеживаемых пользователей: '.count($users).', найденных у них тем: '.count($buffer));
say('  ошибок: '.count($warnings).', раздач в очереди к torrent-клиенту: '.count($temp));
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
//the first line of the journal of events of the new installation
$line = '['.date('Y-m-d H:i:s').'] data: Данные версии 3.x перенесены: тем '.count($topics).', трекеров с учётными данными '.count($trackers)."\n";
if (@file_put_contents($dataDir.'/journal.log', $line, FILE_APPEND) !== FALSE)
    @chmod($dataDir.'/journal.log', 0600);

say('Готово. Записаны config.json, torrents.json и state.json.');
say('Что дальше:');
say('  1. Убедитесь, что веб-сервер и пользователь, от которого запускается engine.php,');
say('     могут читать и изменять файлы в '.$dataDir.'.');
say('  2. Удалите из config.php строки db.* и encryption.key (или весь файл, если других настроек в нём нет).');
say('  3. Откройте веб-интерфейс и проверьте темы и настройки. База данных не изменена: её можно оставить как резервную копию.');
