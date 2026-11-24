<?php
namespace TorrentMonitor\Update;

use TorrentMonitor\App;
use TorrentMonitor\Data\Settings;
use TorrentMonitor\Data\State;
use TorrentMonitor\Http\Request;
use TorrentMonitor\Storage\Files;

// Information about the newest TorrentMonitor version of the chosen channel, from GitHub.
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

    // The oldest version a change of the channel may go back to: the first one whose data files
    // work with any version of the program
    const FLOOR = '4.0.0-beta2';

    // What is installed: stable - regular releases, beta - releases and pre-releases, whichever is
    // newer, dev - the current state of the repository's main branch.
    // The choice is part of automatic updates: without them the regular releases are offered.
    public static function channel()
    {
        $channel = strval(Settings::get('updateChannel'));
        return Settings::on('autoUpdate') && in_array($channel, array('beta', 'dev'), TRUE) ? $channel : 'stable';
    }

    // Branch the dev channel follows; can be overridden in config.php: Config::write('update.branch', 'main');
    private static function branch()
    {
        $branch = \Config::read('update.branch');
        return ! empty($branch) ? $branch : 'master';
    }

    // Commit of the installed copy, if it was installed from the dev channel ('' for a release)
    public static function currentCommit()
    {
        $version = json_decode(strval(@file_get_contents(App::root().'/version.txt')), TRUE);
        return isset($version['commit']) ? strval($version['commit']) : '';
    }

    // The newest version of the chosen channel: array('version', 'notes', 'zip', 'url', 'commit')
    // ('commit' is set for the dev channel only) or NULL if there is none or GitHub is unavailable.
    // $force = TRUE - do not use the cache: return only what GitHub answered just now.
    public static function latest($force = FALSE)
    {
        $channel = self::channel();
        $cache = json_decode(strval(@file_get_contents(self::cacheFile())), TRUE);
        // an answer about another channel is of no use
        if (is_array($cache) && (isset($cache['channel']) ? $cache['channel'] : 'stable') != $channel)
            $cache = NULL;
        if ( ! $force && is_array($cache) && isset($cache['checked'], $cache['ttl']))
        {
            $age = time() - intval($cache['checked']);
            if ($age >= 0 && $age < intval($cache['ttl']))
                return isset($cache['release']) && is_array($cache['release']) ? $cache['release'] : NULL;
        }

        $found = $channel == 'dev' ? self::head() : self::newest($channel == 'beta');

        // FALSE - GitHub is unavailable: keep the previous information and retry later
        if ($found === FALSE)
        {
            $release = is_array($cache) && isset($cache['release']) && is_array($cache['release']) ? $cache['release'] : NULL;
            self::saveCache($release, self::CACHE_TTL_FAIL, $channel);
            // On a forced check (installing an update) the cache is not trusted
            return $force ? NULL : $release;
        }
        self::saveCache($found, self::CACHE_TTL, $channel);
        return $found;
    }

    // The release with the highest version number: regular releases only, or pre-releases too.
    // NULL - there is none, FALSE - GitHub is unavailable.
    // Its notes are followed by the notes of every release between the installed version and it,
    // so that a copy that skips versions is still told what changed in each.
    private static function newest($preReleases)
    {
        $response = self::request(self::apiUrl().'/repos/'.self::repo().'/releases?per_page=30');
        $list = $response['code'] == 200 ? json_decode($response['body'], TRUE) : NULL;
        if ( ! is_array($list))
            return FALSE;
        $releases = array();
        foreach ($list as $data)
        {
            if ( ! is_array($data) || empty($data['tag_name']) || ! empty($data['draft']) || ( ! $preReleases && ! empty($data['prerelease'])))
                continue;
            $releases[] = self::describe($data);
        }
        if (empty($releases))
            return NULL;
        usort($releases, function ($a, $b) { return version_compare($b['version'], $a['version']); });
        $best = $releases[0];

        $between = array();
        foreach ($releases as $release)
            if (version_compare($release['version'], self::currentVersion(), '>') && $release['notes'] !== '')
                $between[] = $release;
        if (count($between) > 1)
        {
            $notes = array();
            foreach ($between as $release)
                $notes[] = '## '.$release['version']."\n\n".$release['notes'];
            $best['notes'] = implode("\n\n", $notes);
        }
        return $best;
    }

    // The sentence a release description starts with: everything before its first heading or list,
    // as plain text. It is what a notification says about the release.
    public static function summary($notes)
    {
        $lines = array();
        foreach (explode("\n", strval($notes)) as $line)
        {
            $line = trim($line);
            if (preg_match('/^(#|[-*] )/', $line))
            {
                // the heading of a version comes before its summary
                if (empty($lines))
                    continue;
                break;
            }
            if ($line === '' && ! empty($lines))
                break;
            if ($line !== '')
                $lines[] = $line;
        }
        $text = implode(' ', $lines);
        $text = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $text);
        return trim(str_replace(array('**', '`'), '', $text));
    }

    // The newest commit of the main branch
    private static function head()
    {
        $repo = self::apiUrl().'/repos/'.self::repo();
        $response = self::request($repo.'/commits/'.rawurlencode(self::branch()));
        $data = $response['code'] == 200 ? json_decode($response['body'], TRUE) : NULL;
        if ( ! is_array($data) || empty($data['sha']) || ! preg_match('/^[0-9a-f]{7,64}$/', strval($data['sha'])))
            return FALSE;
        $sha = strval($data['sha']);

        // what changed since the installed commit: the first line of every commit, newest first
        $titles = array();
        $installed = self::currentCommit();
        if ($installed !== '' && $installed != $sha)
        {
            $compare = self::request($repo.'/compare/'.rawurlencode($installed).'...'.rawurlencode($sha));
            $list = $compare['code'] == 200 ? json_decode($compare['body'], TRUE) : NULL;
            if (is_array($list) && isset($list['commits']) && is_array($list['commits']))
                foreach (array_reverse($list['commits']) as $commit)
                    if (isset($commit['commit']['message']))
                        $titles[] = '- '.strtok(strval($commit['commit']['message']), "\r\n");
        }
        if (empty($titles) && isset($data['commit']['message']))
            $titles[] = '- '.strtok(strval($data['commit']['message']), "\r\n");

        return array(
            'version' => 'dev '.substr($sha, 0, 7),
            'notes'   => implode("\n", $titles),
            'zip'     => $repo.'/zipball/'.$sha,
            'url'     => isset($data['html_url']) ? $data['html_url'] : self::pageUrl(),
            'commit'  => $sha,
        );
    }

    // A release as GitHub describes it -> what the program needs of it
    private static function describe(array $data)
    {
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
        return array(
            'version' => self::normalizeVersion($data['tag_name']),
            'notes'   => isset($data['body']) ? trim(str_replace("\r\n", "\n", strval($data['body']))) : '',
            'zip'     => $zip,
            'url'     => isset($data['html_url']) ? $data['html_url'] : self::pageUrl(),
        );
    }

    // Whether the channel's version is to be installed. Normally only a newer one is. Right after
    // the channel was changed, any other version of the new channel is: also a lower one.
    public static function isNewer($release)
    {
        if ( ! is_array($release) || empty($release['version']))
            return FALSE;
        if ( ! empty($release['commit']))
            return $release['commit'] != self::currentCommit();
        if (version_compare($release['version'], self::currentVersion(), '>'))
            return TRUE;
        return self::isDowngrade($release);
    }

    // The version is offered because the channel was changed, and it is not above the installed one
    public static function isDowngrade($release)
    {
        if ( ! is_array($release) || empty($release['version']) || ! empty($release['commit']) || ! State::channelChanged())
            return FALSE;
        if (version_compare($release['version'], self::currentVersion(), '>') || version_compare($release['version'], self::FLOOR, '<'))
            return FALSE;
        // the same number is still another version when the installed copy came from the dev channel
        return $release['version'] != self::currentVersion() || self::currentCommit() !== '';
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

    private static function saveCache($release, $ttl, $channel = 'stable')
    {
        $file = self::cacheFile();
        $data = json_encode(array(
            'checked' => time(),
            'ttl'     => $ttl,
            'channel' => $channel,
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
