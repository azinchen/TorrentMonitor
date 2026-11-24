<?php
namespace TorrentMonitor\Web;

use TorrentMonitor\App;
use TorrentMonitor\Client\Clients;
use TorrentMonitor\Data\Settings;
use TorrentMonitor\Engine\Launcher;
use TorrentMonitor\Engine\Runner;
use TorrentMonitor\Http\Client;
use TorrentMonitor\Http\Request;
use TorrentMonitor\Storage\Files;
use TorrentMonitor\Tracker\Registry;

//Checks for the «Состояние» (Status) page.
final class Health
{
    /** @var array[] */
    private array $groups = array();

    //Returns groups of checks: array(title, items => array(array(ok => TRUE|FALSE|NULL, text))).
    //ok = NULL - information only, no verdict.
    public static function report(): array
    {
        $health = new self();
        $health->system();
        $health->storage();
        $health->network();
        $health->client();
        $health->notifications();
        return $health->groups;
    }

    private function add(string $group, ?bool $ok, string $text): void
    {
        foreach ($this->groups as $i => $g)
        {
            if ($g['title'] == $group)
            {
                $this->groups[$i]['items'][] = array('ok' => $ok, 'text' => $text);
                return;
            }
        }
        $this->groups[] = array('title' => $group, 'items' => array(array('ok' => $ok, 'text' => $text)));
    }

    public static function bytes(float $bytes): string
    {
        if ($bytes <= 0)
            return '0 Б';
        $units = array('Б', 'КБ', 'МБ', 'ГБ', 'ТБ');
        $pow = min((int)floor(log($bytes, 1024)), count($units) - 1);
        return round($bytes / (1024 ** $pow), 1).' '.$units[$pow];
    }

    private function system(): void
    {
        $g = 'Сервер';
        $this->add($g, NULL, 'TorrentMonitor '.App::version().', PHP '.PHP_VERSION.'.');
        $this->add($g, version_compare(PHP_VERSION, '8.0', '>='), 'Нужен PHP 8.0 или новее.');
        $missing = array();
        foreach (array('curl' => 'cURL', 'mbstring' => 'mbstring', 'simplexml' => 'SimpleXML', 'dom' => 'DOM', 'json' => 'JSON') as $ext => $label)
            if ( ! extension_loaded($ext))
                $missing[] = $label;
        $this->add($g, empty($missing), empty($missing) ? 'Нужные расширения PHP установлены.' : 'Не хватает расширений PHP: '.implode(', ', $missing).'.');
        $this->add($g, class_exists('ZipArchive') ? TRUE : NULL, class_exists('ZipArchive') ? 'Расширение zip установлено: обновление из интерфейса доступно.'
            : 'Расширение zip не установлено: обновление из интерфейса недоступно.');
        $background = function_exists('exec') && Launcher::phpBinary() !== NULL;
        $this->add($g, $background ? TRUE : NULL, $background ? 'Проверку можно запускать в фоне.'
            : 'Фоновый запуск недоступен (нет exec или CLI-версии PHP): проверка из интерфейса выполняется, пока открыта страница.');

        $last = \TorrentMonitor\Data\State::lastRun();
        $age = $last === '' ? NULL : time() - strtotime($last);
        if ($age === NULL)
            $this->add($g, FALSE, 'Проверка ещё ни разу не запускалась. Добавьте engine.php в расписание (cron).');
        else
            $this->add($g, $age < 2 * 86400, 'Последний запуск: '.$last.($age >= 2 * 86400 ? ' — больше двух суток назад, проверьте расписание (cron).' : '.'));
    }

    private function storage(): void
    {
        $g = 'Файлы';
        $dir = Files::dir();
        $this->add($g, Files::prepare(), Files::prepare() ? 'Каталог данных '.$dir.' доступен для записи.' : 'Нет прав на запись в каталог данных '.$dir.': настройки и темы не сохраняются.');
        $public = rtrim(str_replace('\\', '/', (string)realpath(App::root().'/public')), '/');
        $real = rtrim(str_replace('\\', '/', (string)realpath($dir)), '/');
        if ($real !== '' && $public !== '' && strpos($real.'/', $public.'/') === 0)
            $this->add($g, FALSE, 'Каталог данных лежит внутри каталога сайта: пароли могут быть доступны из браузера. Перенесите его.');

        $torrents = App::torrentsDir();
        $this->add($g, is_writable($torrents), is_writable($torrents) ? 'Каталог torrent-файлов '.$torrents.' доступен для записи.' : 'Нет прав на запись в каталог torrent-файлов '.$torrents.'.');
        $free = @disk_free_space($torrents);
        if ($free !== FALSE)
            $this->add($g, $free > 100 * 1024 * 1024, 'Свободно на диске: '.self::bytes($free).'.');
        $root = App::root();
        $updatable = is_writable($root) && is_writable($root.'/version.txt');
        $this->add($g, $updatable ? TRUE : NULL, $updatable ? 'Каталог программы доступен для записи: обновление из интерфейса возможно.'
            : 'Каталог программы недоступен для записи: обновление из интерфейса невозможно.');
    }

    private function network(): void
    {
        $requests = array();
        $urls = \Config::read('connectivity.url');
        $urls = $urls === NULL ? Runner::CONNECTIVITY : array_filter((array)$urls);
        foreach (array_values($urls) as $i => $url)
            $requests['net'.$i] = Request::get($url)->timeout(8)->binary();
        $names = array();
        foreach (Registry::names() as $name)
        {
            //only trackers in use are checked: those with credentials or topics
            if ( ! self::used($name))
                continue;
            $names[] = $name;
            $requests['t:'.$name] = Request::get(Registry::get($name)->base().'/')->follow()->tracker($name)->timeout(12);
        }
        $solver = (string)Settings::get('flaresolverrUrl');
        if ($solver !== '')
            $requests['solver'] = Request::get(rtrim($solver, '/').'/')->plain()->timeout(6);

        $http = App::http();
        $http->solveChallenges = FALSE;
        $responses = $http->sendAll($requests);
        $http->solveChallenges = TRUE;

        $g = 'Сеть';
        $online = empty($urls);
        foreach ($responses as $key => $response)
            if (strncmp($key, 'net', 3) === 0 && $response->status != 0)
                $online = TRUE;
        $this->add($g, $online, $online ? 'Подключение к интернету есть.' : 'Нет подключения к интернету.');
        if ($solver !== '')
        {
            $this->add($g, $responses['solver']->status != 0, $responses['solver']->status != 0 ? 'Решатель Cloudflare '.$solver.' отвечает.'
                : 'Решатель Cloudflare '.$solver.' не отвечает.');
            if ($responses['solver']->status != 0)
                \TorrentMonitor\Engine\About::seeSolver();
        }

        $g = 'Трекеры';
        if (empty($names))
            $this->add($g, NULL, 'Нет трекеров с учётными данными или темами.');
        foreach ($names as $name)
        {
            $r = $responses['t:'.$name];
            $base = Registry::get($name)->base();
            if ($r->challenged || Client::isChallengePage($r->status, $r->headers, $r->body))
                $this->add($g, $solver !== '' ? TRUE : FALSE, $name.' ('.$base.') закрыт проверкой Cloudflare'.($solver !== '' ? ': используется решатель.' : ', а решатель не настроен.'));
            elseif ($r->status >= 200 && $r->status < 500)
                $this->add($g, TRUE, $name.' ('.$base.') доступен.');
            else
                $this->add($g, FALSE, $name.' ('.$base.') недоступен: '.($r->status == 0 ? $r->failureText() : 'HTTP '.$r->status).'.');
            if (Registry::needsAccount($name) && ! Registry::ready($name))
                $this->add($g, FALSE, $name.': не указаны учётные данные.');
        }
    }

    private static function used(string $name): bool
    {
        if (Registry::needsAccount($name) && Registry::ready($name))
            return TRUE;
        foreach (\TorrentMonitor\Data\Topics::all() as $topic)
            if ($topic['tracker'] == $name)
                return TRUE;
        return FALSE;
    }

    private function client(): void
    {
        $g = 'Torrent-клиент';
        $name = Clients::currentName();
        if ($name === NULL)
        {
            $this->add($g, NULL, 'Передача в torrent-клиент выключена: torrent-файлы только сохраняются.');
            return;
        }
        $problem = self::clientProblem();
        if ($problem === NULL)
            \TorrentMonitor\Engine\About::seeClient();
        $this->add($g, $problem === NULL, $problem === NULL ? $name.' по адресу '.Settings::get('torrentAddress').' отвечает, вход выполнен.'
            : $name.' по адресу '.Settings::get('torrentAddress').': '.$problem);
        if ($name == 'SynologyDS' || $name == 'TorrServer')
            $this->add($g, Settings::get('serverAddress') !== '' ? NULL : FALSE, 'Этот клиент получает ссылку на torrent-файл: адрес TorrentMonitor ('
                .(Settings::get('serverAddress') ?: 'не задан').') должен быть доступен с него.');
    }

    //tries to log in to the torrent client; NULL - everything is fine, otherwise the error text
    // Checks the network settings as they are saved: the internet (through the proxy, if one is
    // set) and whether the Cloudflare solver answers at its address.
    /** @return array[] array(ok: bool|null, text) */
    public static function networkCheck(): array
    {
        $lines = array();
        $http = App::http();
        $proxy = Settings::on('proxy') ? (string)Settings::get('proxyAddress') : '';

        //the internet, by the same addresses a check run uses
        $urls = \Config::read('connectivity.url');
        $urls = $urls === NULL ? Runner::CONNECTIVITY : array_filter((array)$urls);
        if (empty($urls))
            $urls = Runner::CONNECTIVITY;
        $requests = array();
        foreach (array_values($urls) as $url)
            $requests[] = Request::get($url)->timeout(8)->binary();
        $online = FALSE;
        $reason = '';
        foreach ($http->sendAll($requests) as $response)
        {
            if ($response->status != 0)
                $online = TRUE;
            else
                $reason = $response->failureText();
        }
        if ($proxy !== '')
            $lines[] = array($online, $online ? 'Прокси '.$proxy.' работает: интернет через него доступен.'
                : 'Через прокси '.$proxy.' интернет недоступен'.($reason !== '' ? ': '.$reason : '').'.');
        else
            $lines[] = array($online, $online ? 'Подключение к интернету есть.' : 'Нет подключения к интернету'.($reason !== '' ? ': '.$reason : '').'.');

        $solver = (string)Settings::get('flaresolverrUrl');
        if ($solver === '')
        {
            $lines[] = array(NULL, 'Решатель Cloudflare не задан.');
            return $lines;
        }
        //Only whether the solver answers at its address: no page is opened through it. What it
        //says about itself is remembered for the log of check runs.
        $about = \TorrentMonitor\Engine\About::seeSolver();
        if ($about === NULL)
            $lines[] = array(FALSE, 'Решатель Cloudflare '.$solver.' не отвечает.');
        else
            $lines[] = array(TRUE, 'Решатель Cloudflare '.$solver.' отвечает'.($about['version'] !== '' ? ': '.trim($about['name'].' '.$about['version']) : '').'.');
        return $lines;
    }

    public static function clientProblem(): ?string
    {
        try
        {
            $client = Clients::current();
            if ($client === NULL)
                return 'клиент не выбран.';
            //request for details of a nonexistent torrent: succeeds only with the right address and password
            $client->info(str_repeat('0', 40));
            return NULL;
        }
        catch (\TorrentMonitor\Client\ClientException $e)
        {
            return \TorrentMonitor\Messages::text($e->getMessage());
        }
    }

    private function notifications(): void
    {
        $g = 'Уведомления';
        if ( ! Settings::on('send'))
        {
            $this->add($g, NULL, 'Уведомления выключены.');
            return;
        }
        foreach (array('sendUpdate' => array('sendUpdateService', 'об обновлениях'), 'sendWarning' => array('sendWarningService', 'об ошибках')) as $switch => $info)
        {
            if ( ! Settings::on($switch))
            {
                $this->add($g, NULL, 'Уведомления '.$info[1].' выключены.');
                continue;
            }
            $target = Settings::notificationTarget($info[0]);
            $ok = $target['service'] !== NULL && $target['address'] !== '';
            $this->add($g, $ok, $ok ? 'Уведомления '.$info[1].' отправляются через '.$target['service'].'.' : 'Уведомления '.$info[1].' включены, но сервис или адрес не заданы.');
        }
    }
}
