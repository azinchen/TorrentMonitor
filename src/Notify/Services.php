<?php
namespace TorrentMonitor\Notify;

use TorrentMonitor\App;
use TorrentMonitor\Http\Request;

// Implementations of the notification services.
final class Services
{
    public static function get(string $service): ?Notifier
    {
        switch ($service)
        {
            case 'E-mail':     return new Email();
            case 'Prowl':      return new Prowl();
            case 'Pushbullet': return new Pushbullet();
            case 'Pushover':   return new Pushover();
            case 'Pushall':    return new Pushall();
            case 'Telegram':   return new Telegram();
            case 'Gotify':     return new Gotify();
        }
        return NULL;
    }

    // Whether the last send succeeded (for checking the settings from the interface)
    public static bool $delivered = FALSE;

    public static function post(Request $request): void
    {
        $response = App::http()->send($request->plain()->timeout(30));
        self::$delivered = $response->status >= 200 && $response->status < 300;
    }

    public static function plain(string $html): string
    {
        return html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
    }
}

final class Email implements Notifier
{
    public function send(string $address, string $title, string $html): void
    {
        $headers = 'From: TorrentMonitor'."\r\n".'MIME-Version: 1.0'."\r\n".'Content-type: text/html; charset=utf-8'."\r\n";
        Services::$delivered = (bool)@mail($address, '=?UTF-8?B?'.base64_encode('TorrentMonitor: '.$title).'?=', nl2br($html), $headers);
    }
}

final class Prowl implements Notifier
{
    public function send(string $address, string $title, string $html): void
    {
        Services::post(Request::post('https://api.prowlapp.com/publicapi/add', array(
            'apikey' => $address, 'application' => 'TorrentMonitor', 'event' => $title, 'description' => Services::plain($html),
        )));
    }
}

final class Pushbullet implements Notifier
{
    public function send(string $address, string $title, string $html): void
    {
        Services::post(Request::post('https://api.pushbullet.com/v2/pushes', array('type' => 'note', 'title' => $title, 'body' => Services::plain($html)))
            ->auth($address, ''));
    }
}

// Address: "user key;application token"
final class Pushover implements Notifier
{
    public function send(string $address, string $title, string $html): void
    {
        $pieces = explode(';', $address);
        if (count($pieces) < 2)
            return;
        Services::post(Request::post('https://api.pushover.net/1/messages.json', array(
            'token' => trim($pieces[1]), 'user' => trim($pieces[0]), 'title' => $title, 'message' => Services::plain($html),
        )));
    }
}

// Address: "id;key"
final class Pushall implements Notifier
{
    public function send(string $address, string $title, string $html): void
    {
        $pieces = explode(';', $address);
        if (count($pieces) < 2)
            return;
        Services::post(Request::post('https://pushall.ru/api.php', array(
            'type' => 'self', 'id' => trim($pieces[0]), 'key' => trim($pieces[1]), 'title' => $title, 'text' => Services::plain($html),
        )));
    }
}

// Address: "bot token;chat[:topic];chat[:topic]..."
final class Telegram implements Notifier
{
    public function send(string $address, string $title, string $html): void
    {
        $pieces = explode(';', $address);
        $url = 'https://api.telegram.org/bot'.trim($pieces[0]).'/sendMessage';
        foreach (array_slice($pieces, 1) as $chat)
        {
            $chat = explode(':', trim($chat));
            if ($chat[0] === '')
                continue;
            $fields = array('chat_id' => $chat[0], 'text' => '<b>'.htmlspecialchars($title).'</b>'."\n".$html, 'disable_web_page_preview' => 1, 'parse_mode' => 'HTML');
            if (isset($chat[1]))
                $fields['message_thread_id'] = $chat[1];
            Services::post(Request::post($url, $fields));
        }
    }
}

// Address: "server address;application token"
final class Gotify implements Notifier
{
    public function send(string $address, string $title, string $html): void
    {
        $pieces = explode(';', $address);
        if (count($pieces) < 2)
            return;
        Services::post(Request::post(rtrim(trim($pieces[0]), '/').'/message?token='.rawurlencode(trim($pieces[1])),
            array('title' => $title, 'message' => Services::plain($html), 'priority' => 5)));
    }
}
