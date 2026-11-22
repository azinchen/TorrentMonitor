<?php
namespace TorrentMonitor\Http;

//Описание одного HTTP-запроса.
final class Request
{
    public string $method = 'GET';
    public string $url;
    /** @var string|array|null тело POST: строка (как есть) или массив полей */
    public $body = NULL;
    /** @var string[] заголовки вида "Имя: значение" */
    public array $headers = array();
    public string $cookie = '';
    public ?string $referer = NULL;
    public bool $follow = FALSE;
    //кодировка страницы на сайте: тело ответа будет переведено из неё в UTF-8
    public ?string $charset = NULL;
    //логин:пароль для HTTP Basic
    public ?string $auth = NULL;
    public ?int $timeout = NULL;
    //имя трекера: по нему выбирается прокси; NULL - запрос не к трекеру
    public ?string $tracker = NULL;
    //ответ - файл, а не страница: решатель Cloudflare для такого адреса вызывать нельзя
    public bool $binary = FALSE;
    //не обходить Cloudflare и не использовать прокси (запросы к torrent-клиентам, уведомлениям)
    public bool $plain = FALSE;

    private function __construct(string $method, string $url)
    {
        $this->method = $method;
        $this->url = $url;
    }

    public static function get(string $url): self
    {
        return new self('GET', $url);
    }

    /** @param string|array $body */
    public static function post(string $url, $body = ''): self
    {
        $request = new self('POST', $url);
        $request->body = $body;
        return $request;
    }

    public static function json(string $url, $data): self
    {
        $request = self::post($url, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $request->headers[] = 'Content-Type: application/json';
        return $request;
    }

    //POST multipart/form-data. $files: имя поля => array(имя файла, содержимое)
    public static function multipart(string $url, array $fields, array $files = array()): self
    {
        $boundary = '----tm'.bin2hex(random_bytes(12));
        $body = '';
        foreach ($fields as $name => $value)
            $body .= '--'.$boundary."\r\nContent-Disposition: form-data; name=\"".$name."\"\r\n\r\n".$value."\r\n";
        foreach ($files as $name => $file)
            $body .= '--'.$boundary."\r\nContent-Disposition: form-data; name=\"".$name."\"; filename=\""
                .str_replace(array('"', "\r", "\n"), '_', $file[0])."\"\r\nContent-Type: application/octet-stream\r\n\r\n".$file[1]."\r\n";
        $request = self::post($url, $body.'--'.$boundary."--\r\n");
        $request->headers[] = 'Content-Type: multipart/form-data; boundary='.$boundary;
        return $request;
    }

    public function cookie(string $cookie): self { $this->cookie = $cookie; return $this; }
    public function referer(string $referer): self { $this->referer = $referer; return $this; }
    public function header(string $header): self { $this->headers[] = $header; return $this; }
    public function follow(bool $follow = TRUE): self { $this->follow = $follow; return $this; }
    public function charset(?string $charset): self { $this->charset = $charset; return $this; }
    public function auth(string $login, string $password): self { $this->auth = $login.':'.$password; return $this; }
    public function timeout(int $seconds): self { $this->timeout = $seconds; return $this; }
    public function tracker(?string $tracker): self { $this->tracker = $tracker; return $this; }
    public function binary(bool $binary = TRUE): self { $this->binary = $binary; return $this; }
    public function plain(bool $plain = TRUE): self { $this->plain = $plain; return $this; }

    public function host(): string
    {
        return strtolower((string)parse_url($this->url, PHP_URL_HOST));
    }

    //схема://хост[:порт]
    public function origin(): string
    {
        $parts = parse_url($this->url);
        return (isset($parts['scheme']) ? $parts['scheme'] : 'http').'://'.(isset($parts['host']) ? $parts['host'] : '')
            .(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    public function bodyString(): string
    {
        if (is_array($this->body))
            return http_build_query($this->body);
        return (string)$this->body;
    }
}
