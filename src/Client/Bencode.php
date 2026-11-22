<?php
namespace TorrentMonitor\Client;

//Минимальный разбор torrent-файла: info-hash и комментарий.
final class Bencode
{
    //info-hash раздачи (40 шестнадцатеричных символов, нижний регистр) или NULL, если это не torrent-файл
    public static function infoHash(string $torrent): ?string
    {
        $info = self::topLevel($torrent, 'info');
        if ($info === NULL)
            return NULL;
        //раздача только формата v2: клиенты используют первые 20 байт SHA-256
        if (strpos($info, '6:pieces') === FALSE && strpos($info, '12:meta versioni2e') !== FALSE)
            return substr(hash('sha256', $info), 0, 40);
        return sha1($info);
    }

    public static function comment(string $torrent): ?string
    {
        $raw = self::topLevel($torrent, 'comment');
        if ($raw === NULL || ! preg_match('/^(\d+):/', $raw, $m))
            return NULL;
        return substr($raw, strlen($m[0]));
    }

    //необработанное значение ключа верхнего уровня
    private static function topLevel(string $data, string $key): ?string
    {
        if ($data === '' || $data[0] != 'd')
            return NULL;
        $pos = 1;
        $length = strlen($data);
        while ($pos < $length && $data[$pos] != 'e')
        {
            $keyStart = $pos;
            $pos = self::skip($data, $pos);
            if ($pos === NULL)
                return NULL;
            $name = substr($data, $keyStart, $pos - $keyStart);
            $valueStart = $pos;
            $pos = self::skip($data, $pos);
            if ($pos === NULL)
                return NULL;
            if ($name === strlen($key).':'.$key)
                return substr($data, $valueStart, $pos - $valueStart);
        }
        return NULL;
    }

    //позиция сразу за элементом, начинающимся в $pos; NULL при ошибке формата
    private static function skip(string $data, int $pos): ?int
    {
        $length = strlen($data);
        if ($pos >= $length)
            return NULL;
        $c = $data[$pos];
        if ($c == 'i')
        {
            $end = strpos($data, 'e', $pos);
            return $end === FALSE ? NULL : $end + 1;
        }
        if ($c == 'l' || $c == 'd')
        {
            $pos++;
            while ($pos < $length && $data[$pos] != 'e')
            {
                $pos = self::skip($data, $pos);
                if ($pos === NULL)
                    return NULL;
            }
            return $pos < $length ? $pos + 1 : NULL;
        }
        if (ctype_digit($c))
        {
            $colon = strpos($data, ':', $pos);
            if ($colon === FALSE)
                return NULL;
            $end = $colon + 1 + (int)substr($data, $pos, $colon - $pos);
            return $end <= $length ? $end : NULL;
        }
        return NULL;
    }
}
