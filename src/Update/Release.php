<?php
namespace TorrentMonitor\Update;

use TorrentMonitor\App;
use TorrentMonitor\Http\Request;
use TorrentMonitor\Storage\Files;

// Information about the latest TorrentMonitor release from GitHub Releases.
// The response is cached in the release.cache.json file to avoid contacting GitHub
// every time a page is opened.
class Release
{
    // Repository where the releases are published
    const REPO = 'azinchen/TorrentMonitor';
    // Cache lifetime of a successful response, sec.
    const CACHE_TTL = 21600;
    // Cache lifetime of a failed response, sec.
    const CACHE_TTL_FAIL = 1800;

    // Address of the releases page
    public static function pageUrl()
    {
        return 'https://github.com/'.self::repo().'/releases';
    }

    // Wiki address
    public static function wikiUrl()
    {
        return 'https://github.com/'.self::repo().'/wiki';
    }

    // The repository can be overridden in config.php: Config::write('update.repo', 'user/repo');
    public static function repo()
    {
        $repo = \Config::read('update.repo');
        return ! empty($repo) ? $repo : self::REPO;
    }

    // The API address can be overridden in config.php (needed for the test bench):
    //Config::write('update.api', 'http://localhost:8080');
    private static function apiUrl()
    {
        $api = \Config::read('update.api');
        return rtrim( ! empty($api) ? $api : 'https://api.github.com', '/');
    }

    // Installed version
    public static function currentVersion()
    {
        return App::version();
    }

    // Convert the release tag (v3.4.0) to a version number (3.4.0)
    public static function normalizeVersion($tag)
    {
        return ltrim(trim(strval($tag)), 'vV');
    }

    // Latest release: array('version', 'notes', 'zip', 'url') or NULL if there are no releases
    // or GitHub is unavailable. $force = TRUE - do not use the cache: return only what
    // GitHub answered just now.
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

        // 404 - the repository has no releases yet
        if ($response['code'] == 404)
        {
            self::saveCache(NULL, self::CACHE_TTL);
            return NULL;
        }

        $data = $response['code'] == 200 ? json_decode($response['body'], TRUE) : NULL;
        if ( ! is_array($data) || empty($data['tag_name']))
        {
            // GitHub is unavailable: keep the previous information and retry later
            $release = is_array($cache) && isset($cache['release']) && is_array($cache['release']) ? $cache['release'] : NULL;
            self::saveCache($release, self::CACHE_TTL_FAIL);
            // On a forced check (installing an update) the cache is not trusted
            return $force ? NULL : $release;
        }

        // The archive built by tools/release.sh; if it is missing - the source code archive from GitHub
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

    // Whether there is a release newer than the installed version
    public static function isNewer($release)
    {
        return is_array($release) && ! empty($release['version'])
            && version_compare($release['version'], self::currentVersion(), '>');
    }

    // Download the release file, return TRUE on success
    public static function download($url, $target)
    {
        $response = self::request($url, 300);
        if ($response['code'] != 200 || empty($response['body']))
            return FALSE;
        return file_put_contents($target, $response['body']) !== FALSE;
    }

    // The cache lives in the data directory
    private static function cacheFile()
    {
        Files::prepare();
        return Files::dir().'/release.cache.json';
    }

    private static function saveCache($release, $ttl)
    {
        $file = self::cacheFile();
        $data = json_encode(array(
            'checked' => time(),
            'ttl'     => $ttl,
            'release' => $release,
        ));
        if (@file_put_contents($file, $data, LOCK_EX) !== FALSE)
            Files::protect($file);
    }

    private static function request($url, $timeout = 15)
    {
        // The GitHub API rejects requests without a User-Agent: the client always sends one
        $response = App::http()->send(Request::get($url)->follow()->timeout($timeout)->binary()
            ->header('Accept: application/vnd.github+json'));
        return array('code' => $response->status, 'body' => $response->body);
    }
}
