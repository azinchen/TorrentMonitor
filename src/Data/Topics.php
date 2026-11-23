<?php
namespace TorrentMonitor\Data;

use TorrentMonitor\Storage\Files;

// Watched topics and series (torrents.json).
// Topic fields: id, tracker, name, topicId (topic number or path on the tracker; empty for series),
// quality (sd|720|1080, for series), episode (S01E02), updatedAt (torrent date on the tracker),
// autoRename (take the name from the tracker), hash (the torrent in the torrent client), path, category, script,
// paused, error, closed, stopAfterDays (NULL - as in the settings), sonarrSeries (series number in Sonarr,
// if the topic was added by it or is already matched by title), sonarrTitle (title of the series in Sonarr:
// set by the webhook, or by the user to link a topic added by hand to a series), sonarrSeasons (season
// numbers of the series the topic holds),
// addedAt, lastActivityAt.
final class Topics
{
    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private static function index(array $list, $id): ?int
    {
        foreach ($list as $i => $item)
            if ($item['id'] == $id)
                return $i;
        return NULL;
    }

    /** @return array[] */
    public static function all(): array
    {
        return array_map(array(__CLASS__, 'complete'), Files::torrents()->read()['topics']);
    }

    // fields added after the topic was saved get their default values
    private static function complete(array $topic): array
    {
        return $topic + array('sonarrSeasons' => array(), 'sonarrTitle' => '');
    }

    public static function get($id): ?array
    {
        $topics = self::all();
        $i = self::index($topics, $id);
        return $i === NULL ? NULL : $topics[$i];
    }

    // A forum tracker topic by its number on the tracker
    public static function find(string $tracker, string $topicId): ?array
    {
        foreach (self::all() as $topic)
            if ($topic['tracker'] == $tracker && $topic['topicId'] !== '' && $topic['topicId'] == $topicId)
                return $topic;
        return NULL;
    }

    public static function findSeries(string $tracker, string $name, string $quality): ?array
    {
        foreach (self::all() as $topic)
            if ($topic['tracker'] == $tracker && $topic['topicId'] === '' && $topic['name'] == $name && $topic['quality'] == $quality)
                return $topic;
        return NULL;
    }

    public static function findByHash(string $hash): ?array
    {
        if ($hash === '')
            return NULL;
        foreach (self::all() as $topic)
            if (strcasecmp($topic['hash'], $hash) == 0)
                return $topic;
        return NULL;
    }

    // Adds a topic, returns its id
    public static function add(array $fields): int
    {
        $id = 0;
        Files::torrents()->update(function (&$data) use ($fields, &$id) {
            $id = $data['nextId']++;
            $data['topics'][] = array('id' => $id) + array_merge(self::blank(), array_intersect_key($fields, self::blank()));
        });
        return $id;
    }

    private static function blank(): array
    {
        return array(
            'tracker' => '', 'name' => '', 'topicId' => '', 'quality' => 'sd', 'episode' => '', 'updatedAt' => '',
            'autoRename' => FALSE, 'hash' => '', 'path' => '', 'category' => '', 'script' => '', 'paused' => FALSE,
            'error' => FALSE, 'closed' => FALSE, 'stopAfterDays' => NULL, 'sonarrSeries' => NULL, 'sonarrTitle' => '', 'sonarrSeasons' => array(),
            'addedAt' => self::now(), 'lastActivityAt' => '',
        );
    }

    // Changes the topic's fields; FALSE if there is no such topic
    public static function update($id, array $fields): bool
    {
        return self::updateMany(array($id => $fields)) > 0;
    }

    // Changes several topics in a single file write: id => fields. Returns the number of topics found.
    public static function updateMany(array $updates): int
    {
        $found = 0;
        if (empty($updates))
            return 0;
        Files::torrents()->update(function (&$data) use ($updates, &$found) {
            $changed = FALSE;
            foreach ($updates as $id => $fields)
            {
                $i = self::index($data['topics'], $id);
                if ($i === NULL)
                    continue;
                $found++;
                $before = self::complete($data['topics'][$i]);
                $fields = array_intersect_key($fields, $before);
                unset($fields['id']);
                // A change of the torrent date counts as a topic update
                if (isset($fields['updatedAt']) && $fields['updatedAt'] !== '' && $fields['updatedAt'] != $before['updatedAt'])
                    $fields['lastActivityAt'] = self::now();
                $data['topics'][$i] = array_merge($before, $fields);
                $changed = $changed || $data['topics'][$i] !== $before;
            }
            return $changed;
        });
        return $found;
    }

    public static function delete($id): bool
    {
        $found = FALSE;
        Files::torrents()->update(function (&$data) use ($id, &$found) {
            $i = self::index($data['topics'], $id);
            if ($i === NULL)
                return FALSE;
            array_splice($data['topics'], $i, 1);
            return $found = TRUE;
        });
        if ($found)
            Pending::remove($id);
        return $found;
    }

    // ------------------------------------------------------------- link to Sonarr

    // Titles of Sonarr series known from the topics (for suggestions in the form)
    /** @return string[] */
    public static function sonarrTitles(): array
    {
        $titles = array_values(array_unique(array_filter(array_column(self::all(), 'sonarrTitle'), 'strlen')));
        sort($titles);
        return $titles;
    }

    // Season numbers from what the user or a script gave: an array or a text like "1, 2".
    // NULL if something in it is not a season number.
    /** @return int[]|null */
    public static function seasons($value): ?array
    {
        $parts = is_array($value) ? $value : preg_split('/[\s,;]+/', trim((string)$value), -1, PREG_SPLIT_NO_EMPTY);
        $seasons = array();
        foreach ($parts as $part)
        {
            if ( ! is_int($part) && ! (is_string($part) && ctype_digit($part)))
                return NULL;
            if ((int)$part > 999)
                return NULL;
            $seasons[(int)$part] = (int)$part;
        }
        sort($seasons);
        return array_values($seasons);
    }

    // Topic fields that link it to a series in Sonarr, from the title and the seasons given by the
    // user or a script. Both empty - no link. Returns the fields, or a text saying what is wrong.
    // $topic - the topic being edited (NULL for a new one): a changed title drops the series number
    // found for the previous one.
    /** @return array|string */
    public static function sonarrLink(string $title, $seasons, ?array $topic = NULL)
    {
        $title = self::sonarrName($title);
        // a topic Sonarr added before titles were stored has a series number and no title: the form
        // then sends an empty title, which must not be taken as a broken link
        if ($title === '' && $topic !== NULL && $topic['sonarrSeries'] !== NULL && $topic['sonarrTitle'] === '')
            return array();
        $seasons = self::seasons($seasons);
        if ($seasons === NULL)
            return 'Сезоны указываются номерами, например: 1 или 1, 2.';
        if ($title === '' && empty($seasons))
            return array('sonarrTitle' => '', 'sonarrSeasons' => array(), 'sonarrSeries' => NULL);
        if ($title === '')
            return 'Укажите название сериала в Sonarr или очистите поле сезона.';
        if (empty($seasons))
            return 'Укажите номер сезона: без него тема не может быть заменена загрузкой из Sonarr.';
        $fields = array('sonarrTitle' => $title, 'sonarrSeasons' => $seasons);
        if ($topic === NULL || self::sameTitle($title, (string)$topic['sonarrTitle']) === FALSE)
            $fields['sonarrSeries'] = NULL;
        return $fields;
    }

    // What the user may put into the "series in Sonarr" field: the title as Sonarr shows it, or
    // the address of the series page in Sonarr (its last part is the name Sonarr sends in events).
    public static function sonarrName(string $text): string
    {
        $text = trim($text);
        if (preg_match('~^https?://~i', $text))
        {
            $parts = array_values(array_filter(explode('/', (string)parse_url($text, PHP_URL_PATH)), 'strlen'));
            if ( ! empty($parts))
                return rawurldecode(end($parts));
        }
        return $text;
    }

    // array(words of the title in lower case, year or NULL): "The Kitchen (2012)" and
    // "the-kitchen-2012" both give array('the kitchen', '2012')
    private static function titleKey(string $text): array
    {
        // an apostrophe does not split a word: "Marvel's" is "marvels"
        $text = str_replace(array("'", '’', '`'), '', mb_strtolower($text, 'UTF-8'));
        $text = trim((string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
        // Sonarr adds the year to tell apart series with the same title
        if (preg_match('/^(.+) ((?:19|20)\d{2})$/u', $text, $m))
            return array($m[1], $m[2]);
        return array($text, NULL);
    }

    // Titles are compared without regard to case, punctuation and extra spaces. The year at the
    // end may be left out on either side; two different years are two different series.
    public static function sameTitle(string $a, string $b): bool
    {
        list($titleA, $yearA) = self::titleKey($a);
        list($titleB, $yearB) = self::titleKey($b);
        return $titleA !== '' && $titleA === $titleB && ($yearA === NULL || $yearB === NULL || $yearA === $yearB);
    }

    // Sonarr named a series ($title, and $slug - its name in the addresses of Sonarr): the topics
    // linked to it by the user get its number, so that later events (which are matched by the
    // number) find them, and the title as Sonarr writes it. Returns how many topics were linked.
    public static function bindSonarr(int $seriesId, string $title, string $slug = ''): int
    {
        $updates = array();
        foreach (self::all() as $topic)
        {
            if ($topic['sonarrSeries'] !== NULL || $topic['sonarrTitle'] === '')
                continue;
            if (self::sameTitle($topic['sonarrTitle'], $title) || ($slug !== '' && self::sameTitle($topic['sonarrTitle'], $slug)))
                $updates[$topic['id']] = array('sonarrSeries' => $seriesId) + ($title !== '' ? array('sonarrTitle' => $title) : array());
        }
        return self::updateMany($updates);
    }

    /** @return string[] */
    public static function categories(): array
    {
        $categories = array_values(array_unique(array_filter(array_column(self::all(), 'category'), 'strlen')));
        sort($categories);
        return $categories;
    }

    // Whether checking of the topic has stopped: it has not been updated for a long time.
    // $globalDays - the stopAfterDays setting; a topic may have its own value (0 - always check).
    public static function expired(array $topic, int $globalDays, ?int $now = NULL): bool
    {
        $days = $topic['stopAfterDays'] === NULL ? $globalDays : (int)$topic['stopAfterDays'];
        if ($days <= 0)
            return FALSE;
        $last = $topic['lastActivityAt'] !== '' ? $topic['lastActivityAt'] : $topic['addedAt'];
        $time = $last === '' ? FALSE : strtotime($last);
        if ($time === FALSE)
            return FALSE;
        return ($now === NULL ? time() : $now) - $time > $days * 86400;
    }
}
