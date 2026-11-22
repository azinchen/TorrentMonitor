<?php
namespace TorrentMonitor\Http;

//Ответ на HTTP-запрос. Тело страниц уже переведено в UTF-8.
final class Response
{
    public int $status = 0;
    public string $body = '';
    //заголовки всех ответов цепочки переадресаций, как пришли
    public string $headers = '';
    /** @var array<string,string> cookie, установленные сервером (и решателем Cloudflare) */
    public array $cookies = array();
    //ошибка соединения (пусто, если ответ получен)
    public string $error = '';
    //сайт ответил проверкой Cloudflare, и обойти её не удалось
    public bool $challenged = FALSE;
    //тело получено через решатель Cloudflare (браузер), а не напрямую
    public bool $solved = FALSE;
    //cookie Cloudflare, с которыми был отправлен запрос (служебное поле клиента)
    public ?string $sentClearance = NULL;

    public function ok(): bool
    {
        return $this->error === '' && $this->status >= 200 && $this->status < 400 && ! $this->challenged;
    }

    public function cookie(string $name): ?string
    {
        return isset($this->cookies[$name]) ? $this->cookies[$name] : NULL;
    }

    //"a=1; b=2" из установленных cookie; $names - только перечисленные
    public function cookieString(array $names = array()): string
    {
        $pairs = array();
        foreach ($this->cookies as $name => $value)
            if (empty($names) || in_array($name, $names, TRUE))
                $pairs[] = $name.'='.$value;
        return implode('; ', $pairs);
    }

    public function header(string $name): ?string
    {
        if (preg_match_all('/^'.preg_quote($name, '/').':\s*(.*?)\s*$/mi', $this->headers, $m))
            return end($m[1]);
        return NULL;
    }

    public function json()
    {
        return json_decode($this->body, TRUE);
    }
}
