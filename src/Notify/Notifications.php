<?php
namespace TorrentMonitor\Notify;

use TorrentMonitor\Data\Settings;
use TorrentMonitor\Messages;

//Отправка уведомлений пользователю согласно настройкам.
final class Notifications
{
    //обновление темы
    public static function update(string $tracker, string $message, string $date, string $topicUrl = ''): void
    {
        self::deliver('sendUpdate', 'sendUpdateService', 'Обновление.', self::body($date, $tracker, $message, $topicUrl));
    }

    //ошибка работы
    public static function warning(string $source, string $code, string $topicUrl = ''): void
    {
        self::deliver('sendWarning', 'sendWarningService', 'Предупреждение.', self::body(date('Y-m-d H:i:s'), $source, Messages::text($code), $topicUrl));
    }

    //новость (вышла новая версия)
    public static function news(string $text): void
    {
        self::deliver('sendUpdate', 'sendUpdateService', 'Новость.', htmlspecialchars($text, ENT_QUOTES));
    }

    //произвольное сообщение об обновлении (тема добавлена через API)
    public static function info(string $source, string $message): void
    {
        self::deliver('sendUpdate', 'sendUpdateService', 'Обновление.', self::body(date('Y-m-d H:i:s'), $source, $message, ''));
    }

    private static function body(string $date, string $source, string $message, string $url): string
    {
        $body = '<i>Дата:</i>  '.htmlspecialchars($date, ENT_QUOTES)."\n"
            .'<i>Трекер:</i>  '.htmlspecialchars($source, ENT_QUOTES)."\n"
            .'<i>Сообщение:</i>  '.htmlspecialchars($message, ENT_QUOTES)."\n";
        if ($url !== '')
            $body .= 'Ссылка на тему: '.htmlspecialchars($url, ENT_QUOTES)."\n";
        return $body;
    }

    private static function deliver(string $switch, string $serviceSetting, string $title, string $html): void
    {
        if ( ! Settings::on('send') || ! Settings::on($switch))
            return;
        $target = Settings::notificationTarget($serviceSetting);
        if ($target['service'] === NULL || $target['address'] === '')
            return;
        $notifier = Services::get($target['service']);
        if ($notifier === NULL)
            return;
        try
        {
            $notifier->send($target['address'], $title, $html);
        }
        catch (\Throwable $e)
        {
            //уведомление не должно останавливать работу
        }
    }
}
