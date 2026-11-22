<?php
//Сведения о последнем выпуске TorrentMonitor из GitHub Releases.
//Ответ кешируется в файле release.cache.json, чтобы не обращаться к GitHub
//при каждом открытии страницы.
class Release
{
    //репозиторий, в котором публикуются выпуски
    const REPO = 'azinchen/TorrentMonitor';
    //время жизни кеша удачного ответа, сек.
    const CACHE_TTL = 21600;
    //время жизни кеша неудачного ответа, сек.
    const CACHE_TTL_FAIL = 1800;

    //адрес страницы выпусков
    public static function pageUrl()
    {
        return 'https://github.com/'.self::repo().'/releases';
    }

    //адрес вики
    public static function wikiUrl()
    {
        return 'https://github.com/'.self::repo().'/wiki';
    }

    //репозиторий можно переопределить в config.php: Config::write('update.repo', 'user/repo');
    public static function repo()
    {
        $repo = Config::read('update.repo');
        return ! empty($repo) ? $repo : self::REPO;
    }

    //адрес API можно переопределить в config.php (нужно для тестового стенда):
    //Config::write('update.api', 'http://localhost:8080');
    private static function apiUrl()
    {
        $api = Config::read('update.api');
        return rtrim( ! empty($api) ? $api : 'https://api.github.com', '/');
    }

    //установленная версия
    public static function currentVersion()
    {
        $version = json_decode(@file_get_contents(dirname(__FILE__).'/../version.txt'));
        return isset($version->system) ? strval($version->system) : '0';
    }

    //приводим тег выпуска (v3.4.0) к номеру версии (3.4.0)
    public static function normalizeVersion($tag)
    {
        return ltrim(trim(strval($tag)), 'vV');
    }

    //последний выпуск: array('version', 'notes', 'zip', 'url') или NULL, если выпусков нет
    //или GitHub недоступен. $force = TRUE - не использовать кеш: вернуть только то,
    //что GitHub ответил сейчас.
    public static function latest($force = FALSE)
    {
        $cache = json_decode(strval(@file_get_contents(self::cacheFile())), TRUE);
        if ( ! $force && is_array($cache) && isset($cache['checked'], $cache['ttl']))
        {
            $age = time() - intval($cache['checked']);
            if ($age >= 0 && $age < intval($cache['ttl']))
                return isset($cache['release']) && is_array($cache['release']) ? $cache['release'] : NULL;
        }

        $response = self::request(self::apiUrl().'/repos/'.self::repo().'/releases/latest');

        //404 - в репозитории ещё нет ни одного выпуска
        if ($response['code'] == 404)
        {
            self::saveCache(NULL, self::CACHE_TTL);
            return NULL;
        }

        $data = $response['code'] == 200 ? json_decode($response['body'], TRUE) : NULL;
        if ( ! is_array($data) || empty($data['tag_name']))
        {
            //GitHub недоступен: оставляем прежние сведения и повторяем попытку позже
            $release = is_array($cache) && isset($cache['release']) && is_array($cache['release']) ? $cache['release'] : NULL;
            self::saveCache($release, self::CACHE_TTL_FAIL);
            //при принудительной проверке (установка обновления) кешу не доверяем
            return $force ? NULL : $release;
        }

        //архив, собранный tools/release.sh; если его нет - архив исходного кода с GitHub
        $zip = isset($data['zipball_url']) ? $data['zipball_url'] : '';
        if ( ! empty($data['assets']) && is_array($data['assets']))
        {
            foreach ($data['assets'] as $asset)
            {
                if (isset($asset['name'], $asset['browser_download_url']) && preg_match('/\.zip$/i', $asset['name']))
                {
                    $zip = $asset['browser_download_url'];
                    break;
                }
            }
        }

        $release = array(
            'version' => self::normalizeVersion($data['tag_name']),
            'notes'   => isset($data['body']) ? trim(str_replace("\r\n", "\n", strval($data['body']))) : '',
            'zip'     => $zip,
            'url'     => isset($data['html_url']) ? $data['html_url'] : self::pageUrl(),
        );
        self::saveCache($release, self::CACHE_TTL);
        return $release;
    }

    //есть ли выпуск новее установленной версии
    public static function isNewer($release)
    {
        return is_array($release) && ! empty($release['version'])
            && version_compare($release['version'], self::currentVersion(), '>');
    }

    //скачиваем файл выпуска, возвращаем TRUE при успехе
    public static function download($url, $target)
    {
        $response = self::request($url, 300);
        if ($response['code'] != 200 || empty($response['body']))
            return FALSE;
        return file_put_contents($target, $response['body']) !== FALSE;
    }

    //кеш лежит в каталоге приложения, рядом с laststart.txt
    private static function cacheFile()
    {
        return dirname(__FILE__).'/../release.cache.json';
    }

    private static function saveCache($release, $ttl)
    {
        $file = self::cacheFile();
        $data = json_encode(array(
            'checked' => time(),
            'ttl'     => $ttl,
            'release' => $release,
        ));
        //веб-сервер и cron могут работать от разных пользователей
        if (@file_put_contents($file, $data, LOCK_EX) !== FALSE)
            @chmod($file, 0666);
    }

    private static function request($url, $timeout = 15)
    {
        $result = array('code' => 0, 'body' => '');
        if ( ! function_exists('curl_init'))
            return $result;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        //GitHub API отклоняет запросы без User-Agent
        curl_setopt($ch, CURLOPT_USERAGENT, 'TorrentMonitor/'.self::currentVersion());
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/vnd.github+json'));
        foreach (Sys::getProxyOptions($url) as $proxyOpt => $proxyVal)
            curl_setopt($ch, $proxyOpt, $proxyVal);

        $body = curl_exec($ch);
        $result['code'] = intval(curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
        $result['body'] = is_string($body) ? $body : '';
        curl_close($ch);
        return $result;
    }
}
?>
