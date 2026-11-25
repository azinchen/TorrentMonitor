<?php
namespace TorrentMonitor\Client;

use TorrentMonitor\App;
use TorrentMonitor\Data\Journal;
use TorrentMonitor\Data\Settings;
use TorrentMonitor\Privacy;
use TorrentMonitor\Storage\Files;

// The torrent clients TorrentMonitor passes torrent files to (config.json, section "clients").
// There may be any number of them, of one kind or of several: a client is a record
//   array('id' => 3, 'name' => 'NAS', 'type' => 'qBittorrent', 'address', 'login', 'password',
//         'path' => default download folder, 'category' => default category).
// A topic remembers its client by the id, so a client can be renamed freely. One client is the
// default: it serves the topics that name none, the REST API and Sonarr unless they say otherwise.
// The setting useTorrent switches the passing of torrents off for all of them at once.
final class Clients
{
    const CLASSES = array(
        'qBittorrent'  => QBittorrent::class,
        'Transmission' => Transmission::class,
        'Deluge'       => Deluge::class,
        'SynologyDS'   => SynologyDS::class,
        'TorrServer'   => TorrServer::class,
    );

    /** @return array[] every client, in the order they were added */
    public static function all(): array
    {
        return Files::config()->read()['clients'];
    }

    // whether torrents are passed to clients at all
    public static function enabled(): bool
    {
        return Settings::on('useTorrent') && ! empty(self::all());
    }

    public static function get($id): ?array
    {
        foreach (self::all() as $client)
            if ($id !== NULL && $id !== '' && (int)$client['id'] === (int)$id)
                return $client;
        return NULL;
    }

    // a client by its name, whatever the case of the letters
    public static function byName(string $name): ?array
    {
        $name = mb_strtolower(trim($name));
        foreach (self::all() as $client)
            if ($name !== '' && mb_strtolower($client['name']) === $name)
                return $client;
        return NULL;
    }

    // a client as the API and the Sonarr webhook name it: by its number or by its name
    public static function resolve($which): ?array
    {
        $which = trim((string)$which);
        if ($which === '')
            return NULL;
        if (ctype_digit($which) && self::get((int)$which) !== NULL)
            return self::get((int)$which);
        return self::byName($which);
    }

    // The default client: the one marked so, otherwise the first one. NULL when there is none.
    public static function fallback(): ?array
    {
        $all = self::all();
        if (empty($all))
            return NULL;
        $marked = self::get(Files::config()->read()['defaultClient']);
        return $marked !== NULL ? $marked : $all[0];
    }

    // The client of a topic: its own, or the default one when it names none (or names one that
    // is gone). NULL when torrents are not passed to clients.
    public static function forTopic(array $topic): ?array
    {
        if ( ! self::enabled())
            return NULL;
        $own = isset($topic['client']) ? self::get($topic['client']) : NULL;
        return $own !== NULL ? $own : self::fallback();
    }

    public static function open(array $client): TorrentClient
    {
        $class = self::CLASSES[$client['type']];
        return new $class(App::http(), (string)$client['address'], (string)$client['login'], (string)$client['password']);
    }

    // A name for a new client of this kind that no client has yet: "qBittorrent", "qBittorrent 2", ...
    public static function suggestName(string $type): string
    {
        $base = isset(self::CLASSES[$type]) ? ($type == 'SynologyDS' ? 'Synology' : $type) : 'Клиент';
        for ($n = 1; ; $n++)
        {
            $name = $base.($n > 1 ? ' '.$n : '');
            if (self::byName($name) === NULL)
                return $name;
        }
    }

    // Adds a client (no id) or changes one. $fields: name, type, address, login, path, category and,
    // to change it, password ('password' absent - the saved one is kept). Returns the id, or the
    // reason it cannot be saved, as text.
    /** @return int|string */
    public static function save(array $fields, $id = NULL)
    {
        $before = $id !== NULL ? self::get($id) : NULL;
        if ($id !== NULL && $before === NULL)
            return 'Такого torrent-клиента нет.';
        $record = ($before !== NULL ? $before : array('name' => '', 'type' => '', 'address' => '', 'login' => '', 'password' => '', 'path' => '', 'category' => ''));
        foreach (array('name', 'type', 'address', 'login', 'password', 'path', 'category') as $key)
            if (array_key_exists($key, $fields))
                $record[$key] = $key == 'password' ? (string)$fields[$key] : trim((string)$fields[$key]);
        if ( ! isset(self::CLASSES[$record['type']]))
            return 'Неизвестный torrent-клиент.';
        if ($record['name'] === '')
            return 'У torrent-клиента должно быть название.';
        $same = self::byName($record['name']);
        if ($same !== NULL && ($before === NULL || (int)$same['id'] !== (int)$before['id']))
            return 'Torrent-клиент с названием «'.$same['name'].'» уже есть.';
        if ($record['address'] === '')
            return 'Укажите адрес torrent-клиента.';
        $record['address'] = rtrim($record['address'], '/');
        if ($record['path'] !== '' && ! preg_match('~[/\\\\]$~', $record['path']))
            $record['path'] .= '/';

        $saved = 0;
        Files::config()->update(function (&$data) use ($record, $before, &$saved) {
            if ($before === NULL)
            {
                $record = array('id' => (int)$data['nextClientId']++) + $record;
                $data['clients'][] = $record;
                //the first client is the default one
                if (count($data['clients']) == 1)
                    $data['defaultClient'] = $record['id'];
            }
            else
                foreach ($data['clients'] as $i => $client)
                    if ((int)$client['id'] === (int)$before['id'])
                        $data['clients'][$i] = $record;
            $saved = (int)$record['id'];
        });

        if ($before === NULL)
            Journal::add('service', 'Torrent-клиент «'.$record['name'].'» добавлен'.self::by().': '.$record['type'].', '.Privacy::address(self::shown($record['address'])));
        else
        {
            $changes = array();
            foreach (array('name' => 'название', 'type' => 'вид', 'category' => 'категория') as $key => $title)
                if ($before[$key] !== $record[$key])
                    $changes[] = $title.': '.($before[$key] === '' ? 'пусто' : $before[$key]).' → '.($record[$key] === '' ? 'пусто' : $record[$key]);
            if ($before['address'] !== $record['address'])
                $changes[] = 'адрес: '.Privacy::address(self::shown($before['address'])).' → '.Privacy::address(self::shown($record['address']));
            if ($before['path'] !== $record['path'])
                $changes[] = 'каталог загрузки: '.($before['path'] === '' ? 'пусто' : Privacy::folder($before['path'])).' → '.($record['path'] === '' ? 'пусто' : Privacy::folder($record['path']));
            foreach (array('login' => 'логин', 'password' => 'пароль') as $key => $title)
                if ($before[$key] !== $record[$key])
                    $changes[] = $title.($record[$key] === '' ? ' удалён' : ' изменён');
            if ( ! empty($changes))
                Journal::add('service', 'Torrent-клиент «'.$before['name'].'»'.self::by().': '.implode('; ', $changes));
        }
        return $saved;
    }

    public static function setDefault($id): bool
    {
        $client = self::get($id);
        if ($client === NULL)
            return FALSE;
        $changed = Files::config()->update(function (&$data) use ($client) {
            if ((int)$data['defaultClient'] === (int)$client['id'])
                return FALSE;
            $data['defaultClient'] = (int)$client['id'];
            return TRUE;
        });
        if ($changed !== FALSE)
            Journal::add('service', 'Основной torrent-клиент'.self::by().': «'.$client['name'].'»');
        return TRUE;
    }

    // Removes a client. The caller has made sure no topic uses it.
    public static function delete($id): bool
    {
        $client = self::get($id);
        if ($client === NULL)
            return FALSE;
        Files::config()->update(function (&$data) use ($client) {
            $data['clients'] = array_values(array_filter($data['clients'], function ($c) use ($client) { return (int)$c['id'] !== (int)$client['id']; }));
            if ((int)$data['defaultClient'] === (int)$client['id'])
                $data['defaultClient'] = empty($data['clients']) ? NULL : (int)$data['clients'][0]['id'];
        });
        Journal::add('service', 'Torrent-клиент «'.$client['name'].'» удалён'.self::by());
        return TRUE;
    }

    //who makes the change, as the journal names it
    private static function by(): string
    {
        return Journal::$by !== '' ? ' ('.Journal::$by.')' : '';
    }

    //an address as the logs show it: without the login it may carry
    private static function shown(string $address): string
    {
        return \TorrentMonitor\Engine\About::withoutLogin($address);
    }
}
