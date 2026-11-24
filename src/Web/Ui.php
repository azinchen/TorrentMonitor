<?php
namespace TorrentMonitor\Web;

use TorrentMonitor\App;
use TorrentMonitor\Client\Clients;
use TorrentMonitor\Data\Accounts;
use TorrentMonitor\Data\Pending;
use TorrentMonitor\Data\Settings;
use TorrentMonitor\Data\State;
use TorrentMonitor\Data\Topics;
use TorrentMonitor\Data\Users;
use TorrentMonitor\Data\Warnings;
use TorrentMonitor\Engine\Delivery;
use TorrentMonitor\Engine\Launcher;
use TorrentMonitor\Engine\Lock;
use TorrentMonitor\Engine\Runner;
use TorrentMonitor\Messages;
use TorrentMonitor\Notify\Notifications;
use TorrentMonitor\Storage\Files;
use TorrentMonitor\Storage\StorageException;
use TorrentMonitor\Time;
use TorrentMonitor\Tracker\Registry;
use TorrentMonitor\Tracker\TrackerException;
use TorrentMonitor\Update\Release;
use TorrentMonitor\Update\Updater;

//Web interface requests: POST /ui/<action> with a JSON body, JSON response.
//Response: {ok: true, ...data} or {ok: false, message: "..."}.
final class Ui
{
    //actions available without logging in
    const OPEN = array('boot', 'login', 'setup');

    //settings changed on the «Настройки» (Settings) page (passwords and the API key have separate actions)
    const SETTINGS = array('serverAddress', 'auth', 'rss', 'debug', 'autoUpdate', 'userAgent', 'httpTimeout', 'flaresolverrUrl',
        'proxy', 'proxyAddress', 'proxyType', 'useTorrent', 'torrentClient', 'torrentAddress', 'torrentLogin', 'pathToDownload',
        'qbitCategory', 'deleteDistribution', 'deleteOldFiles', 'deleteFilesOnRemove', 'stopAfterDays', 'removeOnSeriesDelete', 'sonarrReplaceTopic', 'sonarrReplaceTorrent',
        'send', 'sendUpdate', 'sendWarning', 'trackerTimezone', 'updateChannel', 'dataFileMode');

    private array $in = array();

    public static function handle(string $action): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $ui = new self();
        try
        {
            $problem = App::storageProblem();
            if ($problem !== NULL)
                $ui->fail($problem, 503);
            if ($_SERVER['REQUEST_METHOD'] != 'POST')
                $ui->fail('Нужен запрос POST.', 405);
            Auth::start();
            if ( ! Auth::csrfValid() && $action != 'boot')
                $ui->fail('Страница устарела. Обновите её.', 403);
            if ( ! in_array($action, self::OPEN, TRUE) && ! Auth::ok())
                $ui->fail('Нужно войти.', 401);

            $body = json_decode((string)file_get_contents('php://input'), TRUE);
            $ui->in = is_array($body) ? $body : array();
            $method = 'do'.str_replace(' ', '', ucwords(str_replace(array('.', '-'), ' ', $action)));
            if ( ! preg_match('/^[a-z.\-]+$/', $action) || ! method_exists($ui, $method))
                $ui->fail('Неизвестное действие.', 404);
            $result = $ui->$method();
            echo json_encode(array('ok' => TRUE) + (is_array($result) ? $result : array()), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        catch (StorageException $e)
        {
            $ui->fail($e->getMessage(), 503);
        }
    }

    /** @return never */
    private function fail(string $message, int $code = 200)
    {
        http_response_code($code);
        echo json_encode(array('ok' => FALSE, 'message' => $message), JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function str(string $key, string $default = ''): string
    {
        return isset($this->in[$key]) && is_scalar($this->in[$key]) ? trim((string)$this->in[$key]) : $default;
    }

    private function flag(string $key): bool
    {
        return ! empty($this->in[$key]) && $this->in[$key] !== 'false' && $this->in[$key] !== '0';
    }

    //whether initial setup is needed: there is no data yet
    private static function fresh(): bool
    {
        return ! is_file(Files::dir().'/config.json');
    }

    // ----------------------------------------------------------- login, startup

    private function doBoot(): array
    {
        $result = array('csrf' => Auth::csrf(), 'version' => App::version(), 'setup' => self::fresh(), 'in' => FALSE);
        if ($result['setup'])
            $result['suggestedAddress'] = self::guessAddress();
        elseif (Auth::ok())
            $result = array('in' => TRUE) + $result + $this->doOverview();
        return $result;
    }

    private static function guessAddress(): string
    {
        $https = ( ! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https');
        return ($https ? 'https' : 'http').'://'.(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost').'/';
    }

    private function doLogin(): array
    {
        usleep(300000); //slows down password guessing
        if (self::fresh())
            $this->fail('Сначала выполните первоначальную настройку.');
        if ( ! Auth::login((string)(isset($this->in['password']) ? $this->in['password'] : ''), $this->flag('remember')))
            $this->fail('Неверный пароль.');
        return array('in' => TRUE) + $this->doOverview();
    }

    private function doLogout(): array
    {
        Auth::logout();
        return array();
    }

    //Initial setup: password, address, torrent client.
    private function doSetup(): array
    {
        if ( ! self::fresh())
            $this->fail('TorrentMonitor уже настроен.');
        $password = (string)(isset($this->in['password']) ? $this->in['password'] : '');
        $protect = ! $this->flag('noPassword');
        if ($protect && strlen($password) < 4)
            $this->fail('Пароль должен быть не короче 4 символов.');

        $values = array('auth' => $protect ? '1' : '0', 'ApiKey' => bin2hex(random_bytes(32)),
            'serverAddress' => rtrim($this->str('serverAddress', self::guessAddress()), '/').'/');
        if ($this->str('torrentClient') !== '' && isset(Clients::CLASSES[$this->str('torrentClient')]))
            $values += array('useTorrent' => '1', 'torrentClient' => $this->str('torrentClient'), 'torrentAddress' => rtrim($this->str('torrentAddress'), '/'),
                'torrentLogin' => $this->str('torrentLogin'), 'torrentPassword' => (string)(isset($this->in['torrentPassword']) ? $this->in['torrentPassword'] : ''),
                'pathToDownload' => $this->str('pathToDownload'));
        Settings::setMany($values);
        if ($protect)
        {
            Auth::setPassword($password);
            Auth::login($password, FALSE);
        }
        return array('in' => TRUE) + $this->doOverview();
    }

    // ------------------------------------------------------------------- data

    //Everything the interface shows. Requested after login and after every change.
    private function doOverview(): array
    {
        $settings = Settings::all();
        $stopAfter = (int)$settings['stopAfterDays'];
        $warnings = array();
        foreach (Warnings::all() as $w)
            $warnings[] = array('id' => $w['id'], 'time' => $w['time'], 'timeUtc' => Time::serverUtc((string)$w['time']), 'source' => $w['source'], 'code' => $w['code'],
                'text' => Messages::text($w['code']), 'topic' => $w['topicId']);

        $topics = array();
        $pending = Pending::all();
        foreach (Topics::all() as $t)
        {
            $known = Registry::has($t['tracker']);
            $zone = Time::zone($t['tracker']);
            $topics[] = array(
                //the release date is the tracker's clock, the rest is the server's: the page shows both in the browser's time zone
                'updatedAtUtc' => Time::utc((string)$t['updatedAt'], $zone), 'zone' => Time::label($zone, (string)$t['updatedAt']),
                'addedAtUtc' => Time::serverUtc((string)$t['addedAt']),
                'id' => $t['id'], 'tracker' => $t['tracker'], 'kind' => $known ? Registry::kind($t['tracker']) : 'topic', 'name' => $t['name'],
                'topicId' => $t['topicId'], 'url' => $known && $t['topicId'] !== '' ? Registry::get($t['tracker'])->topicUrl($t['topicId']) : '',
                'quality' => $t['quality'], 'episode' => $t['episode'], 'updatedAt' => $t['updatedAt'], 'autoRename' => (bool)$t['autoRename'],
                'path' => $t['path'], 'category' => $t['category'], 'script' => $t['script'], 'paused' => (bool)$t['paused'],
                'error' => (bool)$t['error'], 'inClient' => $t['hash'] !== '', 'stopAfterDays' => $t['stopAfterDays'],
                'stopped' => Topics::expired($t, $stopAfter), 'waiting' => isset($pending[(string)$t['id']]),
                'addedAt' => $t['addedAt'], 'lastActivityAt' => $t['lastActivityAt'], 'supported' => $known,
                'sonarrTitle' => $t['sonarrTitle'], 'sonarrSeasons' => array_values((array)$t['sonarrSeasons']),
            );
        }

        $users = array();
        foreach (Users::all() as $u)
        {
            $found = Users::found(function ($f) use ($u) { return $f['userId'] == $u['id'] && $f['unread']; });
            usort($found, function ($a, $b) { return strcmp($b['date'], $a['date']) ?: (int)$b['topicId'] - (int)$a['topicId']; });
            $items = array();
            foreach ($found as $f)
                $items[] = array('id' => $f['id'], 'section' => $f['section'], 'title' => $f['title'], 'date' => $f['date'],
                    'url' => Registry::has($f['tracker']) ? Registry::get($f['tracker'])->topicUrl($f['topicId']) : '');
            $users[] = array('id' => $u['id'], 'tracker' => $u['tracker'], 'name' => $u['name'], 'found' => $items);
        }

        $trackers = array();
        $proxies = Accounts::proxies();
        $counts = Warnings::counts();
        foreach (Registry::names() as $name)
        {
            $account = Accounts::get($name);
            $proxy = isset($proxies[$name]) ? $proxies[$name] : array();
            $trackers[] = array(
                'name' => $name, 'kind' => Registry::kind($name), 'needsAccount' => Registry::needsAccount($name), 'watchesUsers' => Registry::watchesUsers($name),
                'ready' => Registry::ready($name), 'login' => $account['login'], 'hasPassword' => $account['password'] !== '', 'passkey' => $account['passkey'],
                'usesPasskey' => FALSE, 'address' => $account['address'], 'timezone' => Accounts::timezone($name), 'defaultAddress' => Registry::get($name)->base(),
                'hasCookie' => Accounts::cookie($name) !== '',
                'proxy' => isset($proxy['use']) ? ($proxy['use'] == 'yes' ? 'own' : 'off') : 'default',
                'proxyAddress' => isset($proxy['address']) ? (string)$proxy['address'] : '', 'proxyType' => isset($proxy['type']) ? strtoupper((string)$proxy['type']) : 'SOCKS5',
                'warnings' => isset($counts[$name]) ? $counts[$name] : 0,
            );
        }

        $shown = array_intersect_key($settings, array_flip(self::SETTINGS));
        $shown['hasTorrentPassword'] = $settings['torrentPassword'] !== '';
        $shown['hasApiKey'] = $settings['ApiKey'] !== '';
        foreach (array('Update' => 'notification', 'Warning' => 'warning') as $what => $type)
        {
            $target = Settings::notificationTarget('send'.$what.'Service');
            $shown['notify'.$what.'Service'] = (string)$target['service'];
            $shown['notify'.$what.'Address'] = $target['address'];
        }

        $release = Release::latest();

        return array(
            'topics' => $topics, 'users' => $users, 'trackers' => $trackers, 'settings' => $shown, 'warnings' => $warnings,
            'categories' => Topics::categories(), 'sonarrTitles' => Topics::sonarrTitles(), 'clients' => array_keys(Clients::CLASSES),
            'services' => array_values(array_unique(array_column(Settings::notificationServices(), 'service'))),
            'timezones' => Time::names(),
            'status' => array(
                'version' => App::versionLabel(), 'lastRun' => State::lastRun(), 'lastRunUtc' => Time::serverUtc((string)State::lastRun()), 'running' => Lock::busy(), 'client' => Clients::currentName(),
                'authRequired' => Auth::required(), 'wiki' => Release::wikiUrl(),
                'update' => Release::isNewer($release) ? array('version' => $release['version'], 'notes' => $release['notes'], 'url' => $release['url'], 'downgrade' => Release::isDowngrade($release)) : NULL,
            ),
        );
    }

    // ------------------------------------------------------------------ topics

    //topic fields shared by adding and editing
    private function topicFields(): array
    {
        $fields = array('path' => $this->str('path'), 'category' => $this->str('category'), 'script' => $this->str('script'));
        if ($fields['path'] !== '' && substr($fields['path'], -1) != '/' && substr($fields['path'], -1) != '\\')
            $fields['path'] .= '/';
        $days = isset($this->in['stopAfterDays']) ? $this->in['stopAfterDays'] : NULL;
        $fields['stopAfterDays'] = ($days === NULL || $days === '') ? NULL : max(0, (int)$days);
        return $fields;
    }

    //link of a forum topic to a series in Sonarr, from the form
    private function sonarrLink(?array $topic): array
    {
        $link = Topics::sonarrLink($this->str('sonarrTitle'), isset($this->in['sonarrSeasons']) ? $this->in['sonarrSeasons'] : '', $topic);
        if (is_string($link))
            $this->fail($link);
        return $link;
    }

    private function quality(): string
    {
        $quality = $this->str('quality', 'sd');
        return in_array($quality, array('sd', '720', '1080'), TRUE) ? $quality : 'sd';
    }

    private function doTopicAdd(): array
    {
        $fields = $this->topicFields();
        if ($this->str('kind') == 'series')
        {
            $tracker = $this->str('tracker');
            $name = $this->str('name');
            if (Registry::kind($tracker) != 'series')
                $this->fail('Выберите трекер.');
            if ( ! Registry::ready($tracker))
                $this->fail('Сначала укажите учётные данные для '.$tracker.' на странице «Трекеры».');
            if ($name === '' || ! Registry::get($tracker)->validName($name))
                $this->fail('Недопустимое название сериала. Укажите его так, как оно написано на трекере (латиницей).');
            if (Topics::findSeries($tracker, $name, $this->quality()) !== NULL)
                $this->fail('Этот сериал в таком качестве уже отслеживается.');
            Topics::add($fields + array('tracker' => $tracker, 'name' => $name, 'quality' => $this->quality()));
            return array('message' => 'Сериал добавлен.') + $this->doOverview();
        }

        $parsed = Registry::fromUrl($this->str('url'));
        if ($parsed === NULL)
            $this->fail('Не удалось распознать ссылку. Нужна ссылка на тему одного из поддерживаемых трекеров.');
        if ( ! Registry::ready($parsed['tracker']))
            $this->fail('Сначала укажите учётные данные для '.$parsed['tracker'].' на странице «Трекеры».');
        if (Topics::find($parsed['tracker'], $parsed['topicId']) !== NULL)
            $this->fail('Эта тема уже отслеживается.');
        $fields += $this->sonarrLink(NULL);
        $name = $this->str('name');
        Topics::add($fields + $parsed + array('name' => $name !== '' ? $name : self::title($parsed), 'autoRename' => $name === '' || $this->flag('autoRename')));
        return array('message' => 'Тема добавлена.') + $this->doOverview();
    }

    //topic title from the tracker; on failure - the link (the title will be filled in on the first check)
    private static function title(array $parsed): string
    {
        try
        {
            $tracker = Registry::get($parsed['tracker']);
            $tracker->login();
            $title = $tracker->title($parsed['topicId']);
            if ( ! empty($title))
                return $title;
        }
        catch (\Throwable $e)
        {
        }
        return '';
    }

    private function doTopicSave(): array
    {
        $topic = Topics::get(isset($this->in['id']) ? $this->in['id'] : 0);
        if ($topic === NULL)
            $this->fail('Тема не найдена.');
        $fields = $this->topicFields() + array('name' => $this->str('name'), 'paused' => $this->flag('paused'));
        if (Registry::kind($topic['tracker']) == 'series')
        {
            if ($fields['name'] === '' || ! Registry::get($topic['tracker'])->validName($fields['name']))
                $this->fail('Недопустимое название сериала.');
            $fields['quality'] = $this->quality();
        }
        else
        {
            $fields['autoRename'] = $this->flag('autoRename');
            //the link to Sonarr changes only when the form sends it (other callers leave it as it is)
            if (array_key_exists('sonarrTitle', $this->in) || array_key_exists('sonarrSeasons', $this->in))
                $fields = $this->sonarrLink($topic) + $fields;
            if ($this->str('url') !== '')
            {
                $parsed = Registry::fromUrl($this->str('url'));
                if ($parsed === NULL)
                    $this->fail('Не удалось распознать ссылку на тему.');
                $other = Topics::find($parsed['tracker'], $parsed['topicId']);
                if ($other !== NULL && $other['id'] != $topic['id'])
                    $this->fail('Тема с этой ссылкой уже отслеживается.');
                $fields += $parsed;
            }
        }
        //"download again": on the next check the torrent will be treated as new
        if ($this->flag('reset'))
            $fields = array('updatedAt' => '', 'episode' => '', 'error' => FALSE) + $fields;
        Topics::update($topic['id'], $fields);
        return array('message' => 'Сохранено.') + $this->doOverview();
    }

    private function doTopicPause(): array
    {
        if ( ! Topics::update(isset($this->in['id']) ? $this->in['id'] : 0, array('paused' => $this->flag('paused'))))
            $this->fail('Тема не найдена.');
        return $this->doOverview();
    }

    private function doTopicDelete(): array
    {
        $topic = Topics::get(isset($this->in['id']) ? $this->in['id'] : 0);
        if ($topic === NULL)
            $this->fail('Тема не найдена.');
        $removed = $this->flag('removeFromClient') && $topic['hash'] !== ''
            && Delivery::removeFromClient($topic['hash'], isset($this->in['deleteData']) ? $this->flag('deleteData') : NULL);
        Topics::delete($topic['id']);
        return array('message' => $removed ? 'Тема удалена, раздача удалена из torrent-клиента.' : 'Тема удалена.') + $this->doOverview();
    }

    //check one topic now
    private function doTopicCheck(): array
    {
        return $this->startRun(array('topic' => isset($this->in['id']) ? (int)$this->in['id'] : 0));
    }

    // ------------------------------------------------------------------- users

    private function doUserAdd(): array
    {
        $tracker = $this->str('tracker');
        $name = $this->str('name');
        if ( ! Registry::watchesUsers($tracker))
            $this->fail('Выберите трекер.');
        if ( ! Registry::ready($tracker))
            $this->fail('Сначала укажите учётные данные для '.$tracker.' на странице «Трекеры».');
        if ($name === '')
            $this->fail('Укажите имя пользователя.');
        if (Users::exists($tracker, $name))
            $this->fail('Этот пользователь уже отслеживается.');
        Users::add($tracker, $name);
        return array('message' => 'Пользователь добавлен. Его раздачи появятся после следующей проверки.') + $this->doOverview();
    }

    private function doUserDelete(): array
    {
        Users::delete(isset($this->in['id']) ? $this->in['id'] : 0);
        return $this->doOverview();
    }

    private function foundFilter(): callable
    {
        $id = isset($this->in['id']) ? $this->in['id'] : 0;
        return function ($f) use ($id) { return $f['id'] == $id; };
    }

    //download the torrent once (on the next check)
    private function doFoundDownload(): array
    {
        Users::changeFound($this->foundFilter(), array('accepted' => TRUE, 'unread' => FALSE));
        return array('message' => 'Раздача будет скачана при следующей проверке.') + $this->doOverview();
    }

    //monitor the topic permanently
    private function doFoundTrack(): array
    {
        $items = Users::found($this->foundFilter());
        if (empty($items))
            $this->fail('Раздача не найдена.');
        if (Topics::find($items[0]['tracker'], $items[0]['topicId']) === NULL)
            Topics::add(array('tracker' => $items[0]['tracker'], 'topicId' => $items[0]['topicId'], 'name' => $items[0]['title']));
        Users::changeFound($this->foundFilter(), array('unread' => FALSE));
        return array('message' => 'Тема добавлена в отслеживаемые.') + $this->doOverview();
    }

    private function doFoundDismiss(): array
    {
        Users::changeFound($this->foundFilter(), array('unread' => FALSE));
        return $this->doOverview();
    }

    private function doFoundClear(): array
    {
        $user = isset($this->in['userId']) ? $this->in['userId'] : 0;
        Users::changeFound(function ($f) use ($user) { return $f['userId'] == $user; }, array('unread' => FALSE));
        return $this->doOverview();
    }

    // ---------------------------------------------------------------- trackers

    private function doTrackerSave(): array
    {
        $name = $this->str('name');
        if ( ! Registry::has($name))
            $this->fail('Неизвестный трекер.');
        $fields = array('login' => $this->str('login'), 'passkey' => $this->str('passkey'), 'address' => $this->str('address'), 'timezone' => $this->str('timezone'));
        if ($fields['timezone'] !== '' && ! Time::valid($fields['timezone']))
            $this->fail('Неизвестный часовой пояс: '.$fields['timezone']);
        $password = isset($this->in['password']) ? (string)$this->in['password'] : '';
        //the saved password is not sent to the browser: an empty field means "do not change"
        if ($password !== '')
            $fields['password'] = $password;
        elseif ($this->flag('clearPassword') || $fields['login'] === '')
            $fields['password'] = '';
        foreach (preg_split('/[,;]+/', $fields['address'], -1, PREG_SPLIT_NO_EMPTY) as $address)
            if ( ! preg_match('~^(https?://)?[a-z0-9][a-z0-9.\-]*\.[a-z]{2,}(:\d+)?/?$~i', trim($address)))
                $this->fail('Адрес трекера указан неверно: '.trim($address));
        Accounts::set($name, $fields);

        //cookie pasted manually (login with a captcha); changing the login or password resets the previous session
        $cookie = isset($this->in['cookie']) ? trim((string)$this->in['cookie']) : '';
        if ($cookie !== '' || $password !== '' || $this->flag('clearCookie'))
            Accounts::setCookie($name, $cookie);

        $proxies = Accounts::proxies();
        $mode = $this->str('proxy', 'default');
        if ($mode == 'off')
            $proxies[$name] = array('use' => 'no');
        elseif ($mode == 'own')
        {
            $proxies[$name] = array('use' => 'yes');
            if ($this->str('proxyAddress') !== '')
                $proxies[$name] += array('address' => $this->str('proxyAddress'), 'type' => $this->str('proxyType', 'SOCKS5'));
        }
        else
            unset($proxies[$name]);
        Accounts::setProxies($proxies);
        App::resetHttp();
        Registry::reset();
        Warnings::clear($name);
        return array('message' => 'Сохранено.') + $this->doOverview();
    }

    //removes the login, password, passkey and the saved session of the tracker; the address and proxy stay
    private function doTrackerForget(): array
    {
        $name = $this->str('name');
        if ( ! Registry::has($name))
            $this->fail('Неизвестный трекер.');
        Accounts::set($name, array('login' => '', 'password' => '', 'passkey' => ''));
        Accounts::setCookie($name, '');
        App::resetHttp();
        Registry::reset();
        Warnings::clear($name);
        return array('message' => 'Учётные данные '.$name.' удалены.') + $this->doOverview();
    }

    //tries to log in to the tracker with the saved credentials
    private function doTrackerTest(): array
    {
        $name = $this->str('name');
        if ( ! Registry::has($name))
            $this->fail('Неизвестный трекер.');
        @set_time_limit(240);
        try
        {
            Registry::get($name)->login();
        }
        catch (TrackerException $e)
        {
            $this->fail(Messages::text($e->getMessage()));
        }
        catch (\Throwable $e)
        {
            $this->fail('Ошибка: '.$e->getMessage());
        }
        return array('message' => Registry::needsAccount($name) ? 'Вход на '.$name.' выполнен.' : $name.' не требует входа.') + $this->doOverview();
    }

    // ---------------------------------------------------------------- settings

    private function doSettingsSave(): array
    {
        $values = array();
        foreach (self::SETTINGS as $key)
        {
            if ( ! isset($this->in[$key]) || ! is_scalar($this->in[$key]))
                continue;
            $value = is_bool($this->in[$key]) ? ($this->in[$key] ? '1' : '0') : trim((string)$this->in[$key]);
            $values[$key] = $value;
        }
        if (isset($values['serverAddress']) && $values['serverAddress'] !== '')
            $values['serverAddress'] = rtrim($values['serverAddress'], '/').'/';
        if (isset($values['torrentAddress']))
            $values['torrentAddress'] = rtrim($values['torrentAddress'], '/');
        if (isset($values['pathToDownload']) && $values['pathToDownload'] !== '' && ! preg_match('~[/\\\\]$~', $values['pathToDownload']))
            $values['pathToDownload'] .= '/';
        foreach (array('httpTimeout' => 1, 'stopAfterDays' => 0) as $key => $min)
            if (isset($values[$key]))
                $values[$key] = (string)max($min, (int)$values[$key]);
        if (isset($values['dataFileMode']) && $values['dataFileMode'] !== '' && ! Files::validMode($values['dataFileMode']))
            $this->fail('Права на файлы данных: три восьмеричные цифры, первая — 6 или 7 (например, 640). Пусто — по умолчанию.');
        if (isset($values['updateChannel']) && ! in_array($values['updateChannel'], array('stable', 'beta', 'dev'), TRUE))
            $this->fail('Неизвестный канал обновлений.');
        if (isset($values['trackerTimezone']) && ! Time::valid($values['trackerTimezone']))
            $this->fail('Неизвестный часовой пояс: '.$values['trackerTimezone']);
        if (isset($values['torrentClient']) && $values['torrentClient'] !== '' && ! isset(Clients::CLASSES[$values['torrentClient']]))
            $this->fail('Неизвестный torrent-клиент.');
        if (isset($values['auth']) && $values['auth'] == '1' && (string)Settings::get('password') === '')
            $this->fail('Сначала задайте пароль.');

        //the client's saved password is not sent to the browser: an empty field means "do not change"
        $password = isset($this->in['torrentPassword']) ? (string)$this->in['torrentPassword'] : '';
        if ($password !== '')
            $values['torrentPassword'] = $password;
        elseif ($this->flag('clearTorrentPassword'))
            $values['torrentPassword'] = '';

        foreach (array('Update' => 'notification', 'Warning' => 'warning') as $what => $type)
        {
            if ( ! isset($this->in['notify'.$what.'Service']))
                continue;
            $id = self::serviceId($this->str('notify'.$what.'Service'), $type);
            $values['send'.$what.'Service'] = (string)$id;
            if ($id !== '')
                Settings::setNotificationAddress($id, $this->str('notify'.$what.'Address'));
        }
        $channel = Release::channel();
        //the files are written with the new access mode at once, this one included
        if (isset($values['dataFileMode']))
            Files::setMode($values['dataFileMode']);
        Settings::setMany($values);
        if (isset($values['dataFileMode']))
            Files::setMode($values['dataFileMode']);
        App::resetHttp();

        //another update channel: its newest version is installed even if it is lower than the installed one.
        //Switching automatic updates off is not a request to go back to the regular releases.
        $message = 'Настройки сохранены.';
        if (Release::channel() != $channel)
        {
            State::setChannelChanged(Settings::on('autoUpdate'));
            State::setUpdateNotified(FALSE);
            $release = Release::latest(TRUE);
            if (Release::isDowngrade($release))
                $message .= ' Канал обновлений изменён: будет установлена версия '.$release['version'].', она не выше текущей '.App::versionLabel().'.';
            elseif (Release::isNewer($release))
                $message .= ' Канал обновлений изменён: будет установлена версия '.$release['version'].'.';
        }
        return array('message' => $message) + $this->doOverview();
    }

    //notification service number by name and kind ('' - not selected)
    private static function serviceId(string $service, string $type)
    {
        foreach (Settings::notificationServices() as $id => $info)
            if ($info['service'] == $service && $info['type'] == $type)
                return $id;
        return '';
    }

    private function doSettingsPassword(): array
    {
        $password = isset($this->in['password']) ? (string)$this->in['password'] : '';
        if (strlen($password) < 4)
            $this->fail('Пароль должен быть не короче 4 символов.');
        if (Auth::required() && ! Auth::passwordValid(isset($this->in['current']) ? (string)$this->in['current'] : ''))
            $this->fail('Текущий пароль указан неверно.');
        Auth::setPassword($password);
        Settings::set('auth', '1');
        $_SESSION['in'] = TRUE;
        return array('message' => 'Пароль изменён.') + $this->doOverview();
    }

    //a new API key is shown once
    private function doSettingsApikey(): array
    {
        $key = bin2hex(random_bytes(32));
        Settings::set('ApiKey', $key);
        return array('key' => $key, 'message' => 'Создан новый ключ API. Прежний больше не действует.') + $this->doOverview();
    }

    private function doSettingsShowkey(): array
    {
        return array('key' => (string)Settings::get('ApiKey'));
    }

    private function doNotifyTest(): array
    {
        if ( ! Notifications::test($this->str('service'), $this->str('address')))
            $this->fail('Сервис не принял сообщение. Проверьте адрес или ключи.');
        return array('message' => 'Пробное сообщение отправлено.');
    }

    //checks the connection to the torrent client with the saved settings
    private function doClientTest(): array
    {
        if (Clients::currentName() === NULL)
            $this->fail('Передача в torrent-клиент выключена. Включите её и сохраните настройки.');
        $problem = Health::clientProblem();
        if ($problem !== NULL)
            $this->fail($problem);
        return array('message' => 'Подключение к '.Clients::currentName().' работает.');
    }

    // ------------------------------------------------------------------ status

    private function doWarningsClear(): array
    {
        $source = $this->str('source');
        foreach ($source !== '' ? array($source) : array_keys(Warnings::counts()) as $name)
            Warnings::clear($name);
        return $this->doOverview();
    }

    private function doHealth(): array
    {
        @set_time_limit(120);
        return array('groups' => Health::report());
    }

    private function doRun(): array
    {
        return $this->startRun(array());
    }

    private function startRun(array $options): array
    {
        if (Lock::busy())
            return array('message' => 'Проверка уже выполняется.', 'running' => TRUE);
        if (Launcher::start($options) === NULL)
        {
            //wait until the run takes the lock so that the interface shows "running" right away
            for ($i = 0; $i < 20 && ! Lock::busy(); $i++)
                usleep(100000);
            return array('message' => 'Проверка запущена.', 'running' => TRUE);
        }
        //a background run is impossible: perform the check within this request
        @set_time_limit(0);
        ignore_user_abort(TRUE);
        if (session_status() === PHP_SESSION_ACTIVE)
            session_write_close();
        (new Runner(function ($line) { }, $options))->run();
        return array('message' => 'Проверка выполнена.', 'running' => FALSE) + $this->doOverview();
    }

    //log of the last run
    private function doRunLog(): array
    {
        $log = (string)@file_get_contents(Runner::logFile());
        return array('running' => Lock::busy(), 'log' => strlen($log) > 200000 ? substr($log, -200000) : $log);
    }

    private function doUpdateCheck(): array
    {
        $release = Release::latest(TRUE);
        return array('message' => Release::isNewer($release) ? 'Доступна версия '.$release['version'].'.' : 'Установлена последняя версия.') + $this->doOverview();
    }

    private function doUpdateInstall(): array
    {
        @set_time_limit(600);
        ob_start();
        $ok = Updater::main();
        $output = trim(html_entity_decode(strip_tags(str_replace(array('<br />', '<br>'), "\n", (string)ob_get_clean())), ENT_QUOTES, 'UTF-8'));
        if ( ! $ok)
            $this->fail($output !== '' ? $output : 'Обновление не выполнено.');
        return array('message' => 'Обновление установлено. Страница будет перезагружена.', 'output' => $output, 'reload' => TRUE);
    }
}
