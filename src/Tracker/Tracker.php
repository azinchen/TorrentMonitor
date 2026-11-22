<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\Data\Accounts;
use TorrentMonitor\Http\Client;
use TorrentMonitor\Http\Request;
use TorrentMonitor\Http\Response;

//Трекер. Один объект создаётся на один запуск и хранит сессию.
//
//Порядок работы движка:
//  login()                    - один раз за запуск; исключение останавливает работу с трекером
//  request($topic)            - какую страницу получить для темы (страницы всех тем загружаются параллельно)
//  check($topic, $response)   - разбор страницы; возвращает найденные новые раздачи
abstract class Tracker
{
    //адреса сайта: основной и запасные (используется первый доступный).
    //Адрес из настроек трекера ставится перед ними.
    const ADDRESSES = array();
    //кодировка страниц сайта (NULL - UTF-8)
    const CHARSET = NULL;
    //нужны ли логин и пароль
    const NEEDS_ACCOUNT = TRUE;

    protected string $name;
    protected Client $http;
    /** @var array{login:string,password:string,passkey:string,address:string} */
    protected array $account;
    //пробный запуск: torrent-файлы не скачиваются (у трекеров бывает суточный лимит)
    public bool $probe = FALSE;
    //cookie сессии
    protected string $session = '';
    private bool $loggedIn = FALSE;
    /** @var string[] */
    private array $addresses;
    //получен ли хоть один ответ от текущего адреса
    private bool $reached = FALSE;
    //последний ответ сайта был непройденной проверкой Cloudflare
    private bool $blocked = FALSE;

    public function __construct(string $name, Client $http)
    {
        $this->name = $name;
        $this->http = $http;
        $this->account = Accounts::get($name);
        $this->addresses = $this->defaultAddresses();
        foreach (array_reverse(preg_split('/[\s,;]+/', $this->account['address'], -1, PREG_SPLIT_NO_EMPTY)) as $address)
        {
            if (strpos($address, '://') === FALSE)
                $address = 'https://'.$address;
            array_unshift($this->addresses, rtrim($address, '/'));
        }
        $this->addresses = array_values(array_unique($this->addresses));
    }

    /** @return string[] адреса сайта по умолчанию */
    protected function defaultAddresses(): array
    {
        return static::ADDRESSES;
    }

    public function name(): string
    {
        return $this->name;
    }

    //адрес сайта без / на конце
    public function base(): string
    {
        return $this->addresses[0];
    }

    // ------------------------------------------------------- для движка и API

    //Входит на трекер, если ещё не вошёл. TrackerException с кодом ошибки при неудаче.
    public function login(): void
    {
        if ($this->loggedIn)
            return;
        if (static::NEEDS_ACCOUNT)
        {
            if ( ! Accounts::filled($this->name))
                throw new TrackerException('credential_miss');
            $saved = Accounts::cookie($this->name);
            if ($saved !== '' && $this->sessionAlive($saved))
                $this->session = $saved;
            else
            {
                try
                {
                    $this->session = $this->signIn();
                }
                catch (TrackerException $e)
                {
                    throw $this->blocked ? new TrackerException('cloudflare') : $e;
                }
                Accounts::setCookie($this->name, $this->session);
            }
        }
        $this->loggedIn = TRUE;
    }

    //запрос страницы, по которой проверяется тема; NULL, если страница не нужна
    abstract public function request(array $topic): ?Request;

    //Разбирает страницу темы. Возвращает новые раздачи (пусто - обновлений нет).
    //TrackerException с кодом ошибки, если проверить тему не удалось.
    /** @return Found[] */
    abstract public function check(array $topic, Response $response): array;

    //название темы на трекере (для добавления темы без названия); NULL, если получить не удалось
    public function title(string $topicId): ?string
    {
        return NULL;
    }

    //адрес темы на трекере для ссылок в интерфейсе и уведомлениях ('' - ссылки нет)
    public function topicUrl(string $topicId): string
    {
        return '';
    }

    //номер темы из её адреса; NULL, если адрес не подходит
    public function topicIdFromUrl(string $url): ?string
    {
        return NULL;
    }

    //допустимо ли такое название сериала (для сериальных трекеров)
    public function validName(string $name): bool
    {
        return TRUE;
    }

    // ---------------------------------------------- отслеживание пользователей

    //Темы пользователя трекера: array of array(section, topicId, title, date 'Y-m-d').
    //NULL, если трекер этого не умеет.
    public function userTopics(string $user): ?array
    {
        return NULL;
    }

    //torrent-файл темы; TrackerException при неудаче
    public function download(string $topicId): string
    {
        throw new TrackerException('torrent_file_fail');
    }

    // ------------------------------------------------- для классов трекеров

    //действует ли сохранённая cookie сессии
    protected function sessionAlive(string $cookie): bool
    {
        return FALSE;
    }

    //Авторизация: возвращает cookie сессии. TrackerException с кодом ошибки при неудаче.
    protected function signIn(): string
    {
        throw new TrackerException('credential_miss');
    }

    //логин в кодировке сайта, готовый для подстановки в тело POST
    protected function encodedLogin(): string
    {
        $login = $this->account['login'];
        if (static::CHARSET !== NULL)
            $login = (string)@iconv('utf-8', static::CHARSET, $login);
        return rawurlencode($login);
    }

    protected function get(string $path, ?string $cookie = NULL): Request
    {
        return $this->prepare(Request::get($this->url($path)), $cookie);
    }

    /** @param string|array $body */
    protected function post(string $path, $body = '', ?string $cookie = NULL): Request
    {
        return $this->prepare(Request::post($this->url($path), $body), $cookie);
    }

    private function prepare(Request $request, ?string $cookie): Request
    {
        return $request->cookie($cookie === NULL ? $this->session : $cookie)->charset(static::CHARSET)->tracker($this->name);
    }

    //полный адрес: путь относительно адреса сайта или готовый адрес
    protected function url(string $path): string
    {
        return preg_match('~^https?://~i', $path) ? $path : $this->base().$path;
    }

    //Выполняет запрос. Пока сайт ни разу не ответил, при ошибке соединения пробует запасные адреса.
    protected function send(Request $request): Response
    {
        $response = $this->http->send($request);
        while ($response->status == 0 && ! $this->reached && count($this->addresses) > 1
            && strncmp($request->url, $this->base(), strlen($this->base())) === 0)
        {
            $path = substr($request->url, strlen($this->base()));
            array_shift($this->addresses);
            $request->url = $this->base().$path;
            $response = $this->http->send($request);
        }
        if ($response->status != 0)
            $this->reached = TRUE;
        $this->blocked = $response->challenged;
        return $response;
    }

    //похоже ли содержимое на torrent-файл
    public static function isTorrent(string $data): bool
    {
        return strlen($data) > 100 && strpos($data, 'announce') !== FALSE && $data[0] == 'd';
    }

    //Скачивает torrent-файл. TrackerException('torrent_file_fail'), если пришло что-то другое.
    protected function torrent(Request $request): string
    {
        if ($this->probe)
            return '';
        $response = $this->send($request->binary()->charset(NULL));
        if ( ! self::isTorrent($response->body))
            throw new TrackerException('torrent_file_fail');
        return $response->body;
    }

    //месяц словом (янв, Feb, марта...) -> номер '01'..'12'; NULL, если не распознан
    public static function monthNumber(string $month): ?string
    {
        $names = array('янв' => '01', 'фев' => '02', 'мар' => '03', 'апр' => '04', 'мая' => '05', 'май' => '05', 'июн' => '06',
            'июл' => '07', 'авг' => '08', 'сен' => '09', 'окт' => '10', 'ноя' => '11', 'дек' => '12',
            'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04', 'may' => '05', 'jun' => '06',
            'jul' => '07', 'aug' => '08', 'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12');
        $key = mb_substr(mb_strtolower(trim($month), 'UTF-8'), 0, 3, 'UTF-8');
        return isset($names[$key]) ? $names[$key] : NULL;
    }

    //номер месяца -> 'Янв'..'Дек'
    public static function monthName($number): string
    {
        $names = array('', 'Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек');
        return isset($names[(int)$number]) ? $names[(int)$number] : '';
    }

    //содержимое <title> страницы с раскодированными HTML-сущностями
    protected static function pageTitle(string $page): ?string
    {
        if ( ! preg_match('/<title>(.*)<\/title>/sU', $page, $m))
            return NULL;
        return trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
    }
}
