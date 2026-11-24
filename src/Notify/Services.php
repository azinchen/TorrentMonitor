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
    // Why it did not: what the service answered, or why it could not be reached ('' after a success)
    public static string $reason = '';

    public static function post(Request $request): void
    {
        $response = App::http()->send($request->plain()->timeout(30));
        $reason = self::refusal($response->status, $response->body, $response->failureText());
        //a message to several addresses (Telegram chats) is delivered only if every one was accepted
        if ($reason !== '')
        {
            self::$delivered = FALSE;
            self::$reason = $reason;
        }
        elseif (self::$reason === '')
            self::$delivered = TRUE;
    }

    // Why a service did not take the message, '' if it did. The status alone is not enough: Pushall
    // answers 200 with an error inside, and the others explain a refusal in the body.
    public static function refusal(int $status, string $body, string $failure = ''): string
    {
        if ($status == 0)
            return 'сервис не отвечает'.($failure !== '' ? ': '.$failure : '');
        $said = '';
        $data = json_decode($body, TRUE);
        if (is_array($data))
        {
            if (isset($data['ok']) && $data['ok'] === FALSE)                      // Telegram
                $said = isset($data['description']) ? (string)$data['description'] : 'отказ';
            elseif (isset($data['errors']) && is_array($data['errors']))          // Pushover
                $said = implode('; ', array_map('strval', $data['errors']));
            elseif (isset($data['error']))                                        // Pushall, Pushbullet, Gotify
                $said = is_array($data['error']) ? (isset($data['error']['message']) ? (string)$data['error']['message'] : 'отказ')
                    : (string)$data['error'].(isset($data['errorDescription']) ? ': '.$data['errorDescription'] : '');
        }
        elseif (preg_match('~<error[^>]*>([^<]+)</error>~', $body, $m))            // Prowl
            $said = trim($m[1]);
        $refused = $said !== '' || $status < 200 || $status >= 300;
        if ( ! $refused)
            return '';
        $said = trim(preg_replace('/\s+/u', ' ', $said));
        if (function_exists('mb_substr') && mb_strlen($said) > 200)
            $said = mb_substr($said, 0, 200).'…';
        return 'HTTP '.$status.($said !== '' ? ': '.$said : '');
    }

    //the address lacks a part the service needs
    public static function incomplete(): void
    {
        self::$delivered = FALSE;
        self::$reason = 'адрес указан не полностью';
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
        Services::$reason = Services::$delivered ? '' : 'почтовая программа сервера не приняла сообщение';
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
        {
            Services::incomplete();
            return;
        }
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
        {
            Services::incomplete();
            return;
        }
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
        if (count(array_filter(array_map('trim', array_slice($pieces, 1)), 'strlen')) == 0)
        {
            Services::incomplete();
            return;
        }
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
        {
            Services::incomplete();
            return;
        }
        Services::post(Request::post(rtrim(trim($pieces[0]), '/').'/message?token='.rawurlencode(trim($pieces[1])),
            array('title' => $title, 'message' => Services::plain($html), 'priority' => 5)));
    }
}
