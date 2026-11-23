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

//HTTP API: /api/torrents, /api/errors, /api/run, /api/sonarr. Described in wiki/API.md.
final class Api
{
    //$segments - path parts after /api
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

    //Responds to the client and keeps working: Sonarr must not wait for the torrent client.
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

    //a topic in the form returned by the API
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

        //quality: quality = sd | 720 | 1080; the former hd parameter (0, 1, 2) is accepted too
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

    //Sonarr webhook. Grab: the torrent that Sonarr sent to the torrent client is added to monitoring
    //(the topic URL is taken from the torrent file's comment). SeriesDelete: the series' topics are deleted if this
    //is enabled in the settings. Other events are accepted and skipped.
    //A Grab of a season pack can also replace the older topic of the same series and season (see replaced()).
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
        //seasons of the grabbed episodes; a season pack is told by the release type (newer Sonarr) or by several episodes
        $episodes = isset($body['episodes']) && is_array($body['episodes']) ? $body['episodes'] : array();
        $seasons = array();
        foreach ($episodes as $episode)
            if (is_array($episode) && isset($episode['seasonNumber']) && is_numeric($episode['seasonNumber']))
                $seasons[(int)$episode['seasonNumber']] = (int)$episode['seasonNumber'];
        $seasons = array_values($seasons);
        $type = isset($body['release']['releaseType']) ? (string)$body['release']['releaseType'] : '';
        $pack = $type !== '' && strcasecmp($type, 'Unknown') != 0 ? strcasecmp($type, 'SeasonPack') == 0 : count($episodes) > 1;

        self::respondAndContinue('Принято.');
        self::grab($hash, $title, $seriesId, $category, $seasons, $pack);
        exit;
    }

    private static function grab(string $hash, string $title, ?int $seriesId, ?string $category, array $seasons = array(), bool $pack = FALSE): void
    {
        //the torrent may not appear in the client right after the event
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

        //the topic is already monitored: not an error, remember the torrent and the series
        $known = Topics::find($parsed['tracker'], $parsed['topicId']);
        if ($known !== NULL)
        {
            $fields = array('hash' => strtolower($hash));
            if ($seriesId !== NULL)
            {
                $fields['sonarrSeries'] = $seriesId;
                //seasons add up only while the topic belongs to the same series
                $before = $known['sonarrSeries'] !== NULL && $known['sonarrSeries'] == $seriesId ? $known['sonarrSeasons'] : array();
                $fields['sonarrSeasons'] = array_values(array_unique(array_merge($before, $seasons)));
            }
            Topics::update($known['id'], $fields);
            if ($pack)
                self::replaced($known['id'], strtolower($hash), $seriesId, $seasons);
            return;
        }

        //the torrent's directory and category are saved in the topic: the updated torrent will go to the same place
        $id = Topics::add(array(
            'tracker' => $parsed['tracker'], 'topicId' => $parsed['topicId'], 'name' => $title, 'autoRename' => TRUE,
            'hash' => strtolower($hash), 'path' => $info['path'], 'sonarrSeries' => $seriesId, 'sonarrSeasons' => $seriesId !== NULL ? $seasons : array(),
            'category' => $category !== NULL && $category !== '' ? $category : $info['category'],
        ));
        Warnings::clear('api');
        Notifications::info('api', 'Тема «'.($title !== '' ? $title : $info['comment']).'» ('.$info['comment'].') добавлена на мониторинг через Sonarr.');
        if ($pack)
            self::replaced($id, strtolower($hash), $seriesId, $seasons);
    }

    //Sonarr grabbed a season pack and its topic ($newId, torrent $newHash) is monitored now: the older topics
    //of the same series and season are deleted, if this is enabled in the settings. Only topics that Sonarr
    //itself added (they carry its series number and seasons) can be replaced, never the ones added by hand.
    private static function replaced(int $newId, string $newHash, ?int $seriesId, array $seasons): void
    {
        if ( ! Settings::on('sonarrReplaceTopic') || $seriesId === NULL || empty($seasons))
            return;
        foreach (Topics::all() as $old)
        {
            if ($old['id'] == $newId || $old['sonarrSeries'] === NULL || $old['sonarrSeries'] != $seriesId
                || empty(array_intersect((array)$old['sonarrSeasons'], $seasons)))
                continue;
            $removed = FALSE;
            if (Settings::on('sonarrReplaceTorrent') && $old['hash'] !== '' && strcasecmp($old['hash'], $newHash) != 0)
                $removed = Delivery::removeFromClient($old['hash'], Settings::on('deleteFilesOnRemove') && ! self::sharedContent($old['hash'], $newHash));
            Topics::delete($old['id']);
            $name = $old['name'] !== '' ? $old['name'] : 'Тема '.$old['topicId'];
            Notifications::info('api', 'Тема «'.$name.'» ('.$old['tracker'].') удалена: Sonarr загрузил другую раздачу этого сезона.'
                .($removed ? ' Её раздача удалена из torrent-клиента.' : ''));
        }
    }

    //Whether deleting the files of the old torrent could delete files of the new one: both are downloaded
    //to the same place. Unknown counts as shared, so that files are kept when in doubt.
    private static function sharedContent(string $oldHash, string $newHash): bool
    {
        try
        {
            $client = Clients::current();
            $old = $client->info($oldHash);
            $new = $client->info($newHash);
        }
        catch (ClientException $e)
        {
            return TRUE;
        }
        if ($old === NULL)
            return FALSE;
        if ($new === NULL || empty($old['content']) || empty($new['content']))
            return TRUE;
        $a = rtrim(str_replace('\\', '/', $old['content']), '/');
        $b = rtrim(str_replace('\\', '/', $new['content']), '/');
        return $a === $b || strncmp($a.'/', $b.'/', strlen($b) + 1) === 0 || strncmp($b.'/', $a.'/', strlen($a) + 1) === 0;
    }
}
