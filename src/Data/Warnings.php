<?php
namespace TorrentMonitor\Data;

use TorrentMonitor\Storage\Files;

//Ошибки работы (state.json). Поля: id, time, source (трекер, torrent-клиент, api, system),
//code (см. Messages), topicId (id темы или NULL).
final class Warnings
{
    //ошибки от новых к старым
    /** @return array[] */
    public static function all(?string $source = NULL): array
    {
        $list = Files::state()->read()['warnings'];
        if ($source !== NULL)
            $list = array_values(array_filter($list, function ($w) use ($source) { return $w['source'] == $source; }));
        usort($list, function ($a, $b) { return strcmp($b['time'], $a['time']) ?: $b['id'] - $a['id']; });
        return $list;
    }

    public static function forTopic($topicId): array
    {
        return array_values(array_filter(self::all(), function ($w) use ($topicId) { return $w['topicId'] !== NULL && $w['topicId'] == $topicId; }));
    }

    public static function count(?string $source = NULL): int
    {
        return count(self::all($source));
    }

    /** @return array<string,int> источник => число ошибок */
    public static function counts(): array
    {
        $counts = array();
        foreach (Files::state()->read()['warnings'] as $w)
            $counts[$w['source']] = (isset($counts[$w['source']]) ? $counts[$w['source']] : 0) + 1;
        ksort($counts);
        return $counts;
    }

    //добавляет ошибку; возвращает TRUE, если она первая для этого источника
    public static function add(string $source, string $code, $topicId = NULL): bool
    {
        $first = FALSE;
        Files::state()->update(function (&$data) use ($source, $code, $topicId, &$first) {
            $first = TRUE;
            foreach ($data['warnings'] as $w)
                if ($w['source'] == $source)
                    $first = FALSE;
            $data['warnings'][] = array('id' => $data['nextWarningId']++, 'time' => date('Y-m-d H:i:s'), 'source' => $source,
                'code' => $code, 'topicId' => $topicId === NULL ? NULL : (int)$topicId);
        });
        return $first;
    }

    public static function clear(string $source): void
    {
        Files::state()->update(function (&$data) use ($source) {
            $before = count($data['warnings']);
            $data['warnings'] = array_values(array_filter($data['warnings'], function ($w) use ($source) { return $w['source'] != $source; }));
            return count($data['warnings']) != $before;
        });
    }
}
