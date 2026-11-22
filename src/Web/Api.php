<?php
namespace TorrentMonitor\Web;

use TorrentMonitor\App;
use TorrentMonitor\Client\ClientException;
use TorrentMonitor\Client\Clients;
use TorrentMonitor\Data\Settings;
use TorrentMonitor\Data\Topics;
use TorrentMonitor\Data\Warnings;
use TorrentMonitor\Engine\Delivery;
use TorrentMonitor\Engine\Launcher;
use TorrentMonitor\Engine\Lock;
use TorrentMonitor\Engine\Problems;
use TorrentMonitor\Messages;
use TorrentMonitor\Notify\Notifications;
use TorrentMonitor\Storage\Migrations;
use TorrentMonitor\Storage\StorageException;
use TorrentMonitor\Tracker\Registry;

//HTTP API: /api/torrents, /api/errors, /api/run, /api/sonarr. Описание - wiki/API.md.
final class Api
{
    //$segments - части пути после /api
    public static function handle(array $segments): void
    {
        header('Content-Type: application/json; charset=utf-8');
        try
        {
            $problem = App::storageProblem();
            if ($problem !== NULL)
                self::respond(FALSE, $problem, NULL, 503);

            $stored = (string)Settings::get('ApiKey');
            if ($stored === '' || ! hash_equals($stored, self::token()))
                self::respond(FALSE, 'Неверный токен.', NULL, 401);

            $method = $_SERVER['REQUEST_METHOD'];
            $resource = isset($segments[0]) ? $segments[0] : '';
            $id = isset($segments[1]) && $segments[1] !== '' ? (int)$segments[1] : NULL;

            if ($resource == 'sonarr' && $method == 'POST')
                self::sonarr();
            elseif ($resource == 'torrents' && $method == 'GET' && $id !== NULL)
                self::topic($id);
            elseif ($resource == 'torrents' && $method == 'GET')
                self::topics();
            elseif ($resource == 'torrents' && $method == 'POST')
                self::add();
            elseif ($resource == 'torrents' && $method == 'DELETE')
                self::delete($id);
            elseif ($resource == 'run' && $method == 'POST')
                self::run();
            elseif ($resource == 'errors' && $method == 'GET')
                self::errors($id);
            else
                self::respond(FALSE, 'Неизвестный ресурс. Доступны: GET/POST /api/torrents, GET /api/torrents/{id}, DELETE /api/torrents/{id}, POST /api/run, GET /api/errors, GET /api/errors/{id}, POST /api/sonarr.', NULL, 404);
        }
        catch (StorageException $e)
        {
            self::respond(FALSE, $e->getMessage(), NULL, 503);
        }
    }

    private static function respond(bool $ok, string $message, $data = NULL, int $code = 200): void
    {
        self::output($ok, $message, $data, $code);
        exit;
    }

    private static function output(bool $ok, string $message, $data, int $code): string
    {
        http_response_code($code);
        $response = array('ok' => $ok, 'message' => $message);
        if ($data !== NULL)
            $response['data'] = $data;
        $json = json_encode($response, JSON_UNESCAPED_UNICODE);
        echo $json;
        return $json;
    }

    //Отвечает клиенту и продолжает работу: Sonarr не должен ждать torrent-клиент.
    private static function respondAndContinue(string $message): void
    {
        ignore_user_abort(TRUE);
        @set_time_limit(180);
        while (ob_get_level() > 0)
            ob_end_clean();
        ob_start();
        self::output(TRUE, $message, NULL, 202);
        header('Content-Length: '.ob_get_length());
        header('Connection: close');
        ob_end_flush();
        flush();
        if (function_exists('fastcgi_finish_request'))
            fastcgi_finish_request();
        if (session_status() === PHP_SESSION_ACTIVE)
            session_write_close();
    }

    private static function token(): string
    {
        $header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
        if (preg_match('/Bearer\s+(.+)$/i', $header, $m))
            return trim($m[1]);
        return isset($_GET['token']) ? (string)$_GET['token'] : '';
    }

    private static function body(): array
    {
        $type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
        if (strpos($type, 'application/json') !== FALSE)
        {
            $data = json_decode((string)file_get_contents('php://input'), TRUE);
            return is_array($data) ? $data : array();
        }
        return $_POST;
    }

    private static function flag(string $name): ?bool
    {
        if ( ! isset($_GET[$name]))
            return NULL;
        return in_array(strtolower((string)$_GET[$name]), array('1', 'true', 'yes'), TRUE);
    }

    //тема в виде, отдаваемом API
    private static function view(array $t): array
    {
        return array(
            'id' => (int)$t['id'], 'tracker' => $t['tracker'], 'name' => $t['name'], 'topicId' => $t['topicId'],
            'url' => $t['topicId'] !== '' && Registry::has($t['tracker']) ? Registry::get($t['tracker'])->topicUrl($t['topicId']) : '',
            'quality' => $t['quality'], 'episode' => $t['episode'], 'updatedAt' => $t['updatedAt'], 'autoRename' => (bool)$t['autoRename'],
            'path' => $t['path'], 'category' => $t['category'], 'script' => $t['script'], 'hash' => $t['hash'],
            'paused' => (bool)$t['paused'], 'error' => (bool)$t['error'], 'closed' => (bool)$t['closed'],
            'stopAfterDays' => $t['stopAfterDays'], 'addedAt' => $t['addedAt'], 'lastActivityAt' => $t['lastActivityAt'],
            'stopped' => Topics::expired($t, (int)Settings::get('stopAfterDays')),
        );
    }

    // ----------------------------------------------------------------- torrents

    private static function topic(int $id): void
    {
        $topic = Topics::get($id);
        if ($topic === NULL)
            self::respond(FALSE, 'Тема не найдена.', NULL, 404);
        self::respond(TRUE, 'OK', self::view($topic));
    }

    private static function topics(): void
    {
        $filter = isset($_GET['tracker']) ? (string)$_GET['tracker'] : '';
        $items = array();
        foreach (Topics::all() as $topic)
            if ($filter === '' || $topic['tracker'] === $filter)
                $items[] = self::view($topic);
        usort($items, function ($a, $b) { return strcmp($a['tracker'], $b['tracker']) ?: strcmp($a['name'], $b['name']) ?: $a['id'] - $b['id']; });
        self::respond(TRUE, count($items).' тем.', $items);
    }

    private static function add(): void
    {
        $b = self::body();
        $url = trim((string)(isset($b['url']) ? $b['url'] : ''));
        $name = trim((string)(isset($b['name']) ? $b['name'] : ''));
        $tracker = trim((string)(isset($b['tracker']) ? $b['tracker'] : ''));
        $common = array('path' => trim((string)(isset($b['path']) ? $b['path'] : '')), 'category' => trim((string)(isset($b['category']) ? $b['category'] : '')));
        if (isset($b['stopAfterDays']) && $b['stopAfterDays'] !== '')
            $common['stopAfterDays'] = max(0, (int)$b['stopAfterDays']);

        if ($url !== '')
        {
            $parsed = Registry::fromUrl($url);
            if ($parsed === NULL)
                self::respond(FALSE, 'Не удалось разобрать URL или трекер не поддерживается.', NULL, 422);
            if ( ! Registry::ready($parsed['tracker']))
                self::respond(FALSE, 'Нет учётных данных для трекера: '.$parsed['tracker'].'.', NULL, 422);
            if (Topics::find($parsed['tracker'], $parsed['topicId']) !== NULL)
                self::respond(FALSE, 'Тема уже отслеживается.', NULL, 409);
            $id = Topics::add($common + array('tracker' => $parsed['tracker'], 'topicId' => $parsed['topicId'], 'name' => $name, 'autoRename' => TRUE));
            self::respond(TRUE, 'Тема добавлена.', array('id' => $id) + $parsed, 201);
        }

        if ($tracker === '' || $name === '')
            self::respond(FALSE, 'Укажите url (тема форумного трекера) или tracker + name (сериал).', NULL, 422);
        if (Registry::kind($tracker) != 'series')
            self::respond(FALSE, 'Трекер '.$tracker.' не отслеживает сериалы по названию. Используйте параметр url.', NULL, 422);
        if ( ! Registry::ready($tracker))
            self::respond(FALSE, 'Нет учётных данных для трекера: '.$tracker.'.', NULL, 422);
        if ( ! Registry::get($tracker)->validName($name))
            self::respond(FALSE, 'Недопустимое название сериала.', NULL, 422);

        //качество: quality = sd | 720 | 1080; прежний параметр hd (0, 1, 2) тоже принимается
        if (isset($b['quality']) && $b['quality'] !== '')
            $quality = strtolower(rtrim((string)$b['quality'], 'pP'));
        else
        {
            $hd = isset($b['hd']) ? (int)$b['hd'] : 0;
            if ( ! in_array($hd, array(0, 1, 2), TRUE))
                self::respond(FALSE, 'Параметр hd должен быть 0, 1 или 2.', NULL, 422);
            $quality = Migrations::quality($tracker, $hd);
        }
        if ( ! in_array($quality, array('sd', '720', '1080'), TRUE))
            self::respond(FALSE, 'Параметр quality должен быть sd, 720 или 1080.', NULL, 422);
        if (Topics::findSeries($tracker, $name, $quality) !== NULL)
            self::respond(FALSE, 'Сериал уже отслеживается.', NULL, 409);

        $id = Topics::add($common + array('tracker' => $tracker, 'name' => $name, 'quality' => $quality));
        self::respond(TRUE, 'Сериал добавлен.', array('id' => $id, 'tracker' => $tracker, 'name' => $name, 'quality' => $quality), 201);
    }

    //DELETE /api/torrents/{id}?removeFromClient=1&deleteData=1|0
    private static function delete(?int $id): void
    {
        if ( ! $id)
            self::respond(FALSE, 'Укажите ID: DELETE /api/torrents/{id}', NULL, 422);
        $topic = Topics::get($id);
        if ($topic === NULL)
            self::respond(FALSE, 'Тема не найдена.', NULL, 404);

        $removed = self::flag('removeFromClient') && $topic['hash'] !== '' && Delivery::removeFromClient($topic['hash'], self::flag('deleteData'));
        Topics::delete($id);
        self::respond(TRUE, $removed ? 'Тема удалена, раздача удалена из торрент-клиента.' : 'Тема удалена.');
    }

    // ---------------------------------------------------------------------- run

    private static function run(): void
    {
        if (Lock::busy())
            self::respond(TRUE, 'Проверка уже выполняется.', NULL, 202);
        $problem = Launcher::start(self::body());
        if ($problem !== NULL)
            self::respond(FALSE, $problem, NULL, 500);
        self::respond(TRUE, 'Проверка запущена.', NULL, 202);
    }

    // ------------------------------------------------------------------- errors

    private static function errors(?int $topicId): void
    {
        if ($topicId !== NULL && Topics::get($topicId) === NULL)
            self::respond(FALSE, 'Тема не найдена.', NULL, 404);
        $source = isset($_GET['tracker']) && $_GET['tracker'] !== '' ? (string)$_GET['tracker'] : NULL;
        $rows = array();
        foreach ($topicId !== NULL ? Warnings::forTopic($topicId) : Warnings::all($source) as $w)
            $rows[] = array('time' => $w['time'], 'source' => $w['source'], 'code' => $w['code'], 'message' => Messages::text($w['code']), 'topic' => $w['topicId']);
        self::respond(TRUE, count($rows).' ошибок.', $rows);
    }

    // ------------------------------------------------------------------- sonarr

    //Webhook Sonarr. Grab: раздача, которую Sonarr передал в torrent-клиент, ставится на отслеживание
    //(адрес темы берётся из комментария torrent-файла). SeriesDelete: темы сериала удаляются, если это
    //включено в настройках. Остальные события принимаются и пропускаются.
    private static function sonarr(): void
    {
        $body = json_decode((string)file_get_contents('php://input'), TRUE);
        $body = is_array($body) ? $body : array();
        $event = isset($body['eventType']) ? (string)$body['eventType'] : '';
        $seriesId = isset($body['series']['id']) ? (int)$body['series']['id'] : NULL;

        if ($event == 'SeriesDelete')
        {
            if ( ! Settings::on('removeOnSeriesDelete') || $seriesId === NULL)
                self::respond(TRUE, 'Событие SeriesDelete пропущено.');
            $deleteData = isset($body['deletedFiles']) ? (bool)$body['deletedFiles'] : NULL;
            $count = 0;
            foreach (Topics::all() as $topic)
            {
                if ($topic['sonarrSeries'] === NULL || $topic['sonarrSeries'] != $seriesId)
                    continue;
                if ($topic['hash'] !== '')
                    Delivery::removeFromClient($topic['hash'], $deleteData);
                Topics::delete($topic['id']);
                $count++;
            }
            self::respond(TRUE, 'Удалено тем: '.$count.'.');
        }
        if ($event != 'Grab')
            self::respond(TRUE, $event == 'Test' ? 'OK' : 'Событие '.$event.' пропущено.');

        $hash = isset($body['downloadId']) ? (string)$body['downloadId'] : '';
        if ($hash === '')
        {
            Problems::report('api', 'api_no_download_id');
            self::respond(FALSE, 'Нет downloadId в запросе.', NULL, 422);
        }
        if (Clients::currentName() === NULL)
        {
            Problems::report('api', 'api_client_fail');
            self::respond(FALSE, 'В настройках TorrentMonitor не включён torrent-клиент.', NULL, 422);
        }

        $title = isset($body['series']['title']) ? (string)$body['series']['title'] : '';
        $category = isset($_GET['category']) ? (string)$_GET['category'] : NULL;
        self::respondAndContinue('Принято.');
        self::grab($hash, $title, $seriesId, $category);
        exit;
    }

    private static function grab(string $hash, string $title, ?int $seriesId, ?string $category): void
    {
        //раздача может появиться в клиенте не сразу после события
        $info = NULL;
        try
        {
            $client = Clients::current();
            for ($attempt = 0; $attempt < 5 && empty($info['comment']); $attempt++)
            {
                if ($attempt > 0)
                    sleep(3);
                $info = $client->info($hash);
            }
        }
        catch (ClientException $e)
        {
            $info = NULL;
        }
        if (empty($info['comment']))
        {
            Problems::report('api', 'api_client_fail');
            return;
        }

        $parsed = Registry::fromUrl($info['comment']);
        if ($parsed === NULL)
        {
            Problems::report('api', 'api_bad_url');
            return;
        }
        if ( ! Registry::ready($parsed['tracker']))
        {
            Problems::report('api', 'api_no_credentials');
            return;
        }

        //тема уже отслеживается: это не ошибка, запоминаем раздачу и сериал
        $known = Topics::find($parsed['tracker'], $parsed['topicId']);
        if ($known !== NULL)
        {
            $fields = array('hash' => strtolower($hash));
            if ($seriesId !== NULL)
                $fields['sonarrSeries'] = $seriesId;
            Topics::update($known['id'], $fields);
            return;
        }

        //каталог и категория раздачи сохраняются в теме: обновлённая раздача попадёт туда же
        Topics::add(array(
            'tracker' => $parsed['tracker'], 'topicId' => $parsed['topicId'], 'name' => $title, 'autoRename' => TRUE,
            'hash' => strtolower($hash), 'path' => $info['path'], 'sonarrSeries' => $seriesId,
            'category' => $category !== NULL && $category !== '' ? $category : $info['category'],
        ));
        Warnings::clear('api');
        Notifications::info('api', 'Тема «'.($title !== '' ? $title : $info['comment']).'» ('.$info['comment'].') добавлена на мониторинг через Sonarr.');
    }
}
