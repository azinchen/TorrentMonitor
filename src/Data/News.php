<?php
namespace TorrentMonitor\Data;

use TorrentMonitor\Storage\Files;

//Новости для пользователя (state.json): сообщения о новых версиях. Поля: id, text, unread.
final class News
{
    /** @return array[] от новых к старым */
    public static function all(): array
    {
        $news = Files::state()->read()['news'];
        usort($news, function ($a, $b) { return strcmp($b['id'], $a['id']); });
        return $news;
    }

    public static function unreadCount(): int
    {
        return count(array_filter(Files::state()->read()['news'], function ($n) { return ! empty($n['unread']); }));
    }

    public static function exists(string $id): bool
    {
        foreach (Files::state()->read()['news'] as $n)
            if ($n['id'] === $id)
                return TRUE;
        return FALSE;
    }

    //добавляет новость или заменяет текст существующей
    public static function put(string $id, string $text): void
    {
        Files::state()->update(function (&$data) use ($id, $text) {
            foreach ($data['news'] as $i => $n)
            {
                if ($n['id'] === $id)
                {
                    $data['news'][$i]['text'] = $text;
                    return TRUE;
                }
            }
            $data['news'][] = array('id' => $id, 'text' => $text, 'unread' => TRUE);
            return TRUE;
        });
    }

    public static function markRead(string $id): void
    {
        Files::state()->update(function (&$data) use ($id) {
            foreach ($data['news'] as $i => $n)
            {
                if ($n['id'] === $id && ! empty($n['unread']))
                {
                    $data['news'][$i]['unread'] = FALSE;
                    return TRUE;
                }
            }
            return FALSE;
        });
    }
}
