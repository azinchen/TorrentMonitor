<?php
namespace TorrentMonitor\Data;

use TorrentMonitor\Storage\Files;

//Отслеживаемые пользователи трекеров (torrents.json) и найденные у них темы (state.json).
final class Users
{
    /** @return array[] array(id, tracker, name) */
    public static function all(): array
    {
        return Files::torrents()->read()['users'];
    }

    public static function get($id): ?array
    {
        foreach (self::all() as $user)
            if ($user['id'] == $id)
                return $user;
        return NULL;
    }

    public static function exists(string $tracker, string $name): bool
    {
        foreach (self::all() as $user)
            if ($user['tracker'] == $tracker && $user['name'] == $name)
                return TRUE;
        return FALSE;
    }

    public static function add(string $tracker, string $name): int
    {
        $id = 0;
        Files::torrents()->update(function (&$data) use ($tracker, $name, &$id) {
            $id = $data['nextUserId']++;
            $data['users'][] = array('id' => $id, 'tracker' => $tracker, 'name' => $name);
        });
        return $id;
    }

    public static function delete($id): void
    {
        Files::torrents()->update(function (&$data) use ($id) {
            $before = count($data['users']);
            $data['users'] = array_values(array_filter($data['users'], function ($u) use ($id) { return $u['id'] != $id; }));
            return count($data['users']) != $before;
        });
        Files::state()->update(function (&$data) use ($id) {
            $before = count($data['found']);
            $data['found'] = array_values(array_filter($data['found'], function ($f) use ($id) { return $f['userId'] != $id; }));
            return count($data['found']) != $before;
        });
    }

    // ---- найденные темы: id, userId, tracker, section, topicId, title, date, accepted, downloaded, unread

    //запоминает тему пользователя; FALSE, если она уже известна
    public static function addFound($userId, string $tracker, string $section, string $topicId, string $title, string $date): bool
    {
        $section = html_entity_decode($section, ENT_QUOTES, 'UTF-8');
        $title = html_entity_decode(str_replace('<wbr>', '', $title), ENT_QUOTES, 'UTF-8');
        return (bool)Files::state()->update(function (&$data) use ($userId, $tracker, $section, $topicId, $title, $date) {
            foreach ($data['found'] as $item)
                if ($item['userId'] == $userId && $item['topicId'] == $topicId)
                    return FALSE;
            $data['found'][] = array(
                'id' => $data['nextFoundId']++, 'userId' => (int)$userId, 'tracker' => $tracker, 'section' => $section,
                'topicId' => $topicId, 'title' => $title, 'date' => $date, 'accepted' => FALSE, 'downloaded' => FALSE, 'unread' => TRUE,
            );
            return TRUE;
        });
    }

    /** @return array[] */
    public static function found(?callable $filter = NULL): array
    {
        $found = Files::state()->read()['found'];
        return $filter === NULL ? $found : array_values(array_filter($found, $filter));
    }

    public static function changeFound(callable $filter, array $fields): void
    {
        Files::state()->update(function (&$data) use ($filter, $fields) {
            $changed = FALSE;
            foreach ($data['found'] as $i => $item)
            {
                if ( ! $filter($item))
                    continue;
                $data['found'][$i] = array_merge($item, $fields);
                $changed = $changed || $data['found'][$i] !== $item;
            }
            return $changed;
        });
    }
}
