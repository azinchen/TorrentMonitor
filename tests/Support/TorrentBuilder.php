<?php
// Builds small deterministic .torrent files and their payload.
// Used by the fake tracker (to serve dl.php) and by tests (to know the expected hash and data).
class TorrentBuilder
{
    const PIECE_LENGTH = 16384;

    public static function bencode($value)
    {
        if (is_int($value))
            return 'i'.$value.'e';
        if (is_string($value))
            return strlen($value).':'.$value;
        if (is_array($value))
        {
            $isList = array_keys($value) === range(0, count($value) - 1);
            if ($isList)
                return 'l'.implode('', array_map(array(__CLASS__, 'bencode'), $value)).'e';
            ksort($value, SORT_STRING);
            $out = 'd';
            foreach ($value as $k => $v)
                $out .= self::bencode((string)$k).self::bencode($v);
            return $out.'e';
        }
        throw new InvalidArgumentException('cannot bencode '.gettype($value));
    }

    // file name of the payload for a topic revision
    public static function fileName($topicId, $revision)
    {
        return 'tm-test-'.$topicId.'-r'.$revision.'.bin';
    }

    // payload: 40 KB of bytes derived from topic id and revision
    public static function payload($topicId, $revision)
    {
        $data = '';
        for ($i = 0; strlen($data) < 40000; $i++)
            $data .= hash('sha256', $topicId.'/'.$revision.'/'.$i, true);
        return substr($data, 0, 40000);
    }

    private static function info($topicId, $revision)
    {
        $payload = self::payload($topicId, $revision);
        $pieces = '';
        foreach (str_split($payload, self::PIECE_LENGTH) as $piece)
            $pieces .= sha1($piece, true);
        return array(
            'length'       => strlen($payload),
            'name'         => self::fileName($topicId, $revision),
            'piece length' => self::PIECE_LENGTH,
            'pieces'       => $pieces,
            'private'      => 1,
        );
    }

    // contents of the .torrent file; $comment is the topic URL, as real trackers set it
    public static function torrent($topicId, $revision, $comment)
    {
        return self::bencode(array(
            'announce' => 'http://tracker.invalid/announce',
            'comment'  => $comment,
            'info'     => self::info($topicId, $revision),
        ));
    }

    public static function infoHash($topicId, $revision)
    {
        return sha1(self::bencode(self::info($topicId, $revision)));
    }
}
