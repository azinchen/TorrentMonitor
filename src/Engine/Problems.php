<?php
namespace TorrentMonitor\Engine;

use TorrentMonitor\Data\Topics;
use TorrentMonitor\Data\Warnings;
use TorrentMonitor\Notify\Notifications;
use TorrentMonitor\Tracker\Registry;

//Запись ошибок работы: в список ошибок, в тему и (первая ошибка источника) в уведомление.
final class Problems
{
    //$source - трекер, torrent-клиент, api или system; $topic - тема, к которой относится ошибка
    public static function report(string $source, string $code, ?array $topic = NULL): void
    {
        $first = Warnings::add($source, $code, $topic === NULL ? NULL : $topic['id']);
        if ($topic !== NULL)
            Topics::update($topic['id'], array('error' => TRUE));
        if ( ! $first)
            return;
        $url = '';
        if ($topic !== NULL && $topic['topicId'] !== '' && Registry::has($topic['tracker']))
            $url = Registry::get($topic['tracker'])->topicUrl($topic['topicId']);
        Notifications::warning($source, $code, $url);
    }
}
