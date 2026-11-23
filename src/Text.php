<?php
namespace TorrentMonitor;

// Text encoding helpers. They use mbstring only: the iconv extension is not needed
// (iconv of musl-based systems such as Alpine does not support //IGNORE).
final class Text
{
    // Converts between encodings; bytes and characters that cannot be converted are dropped
    public static function convert(string $text, string $to, string $from): string
    {
        if ($text === '')
            return '';
        $previous = mb_substitute_character();
        mb_substitute_character('none');
        try
        {
            $result = (string)mb_convert_encoding($text, $to, $from);
            // older PHP versions turn an undefined byte into U+FFFD instead of dropping it
            return strcasecmp($to, 'UTF-8') == 0 ? str_replace("\u{FFFD}", '', $result) : $result;
        }
        catch (\Throwable $e)
        {
            // unknown encoding name
            return '';
        }
        finally
        {
            mb_substitute_character($previous);
        }
    }

    public static function toUtf8(string $text, string $from): string
    {
        return self::convert($text, 'UTF-8', $from);
    }

    public static function fromUtf8(string $text, string $to): string
    {
        return self::convert($text, $to, 'UTF-8');
    }

    // Drops broken byte sequences from a UTF-8 string (e.g. after cutting it by bytes)
    public static function cleanUtf8(string $text): string
    {
        return self::convert($text, 'UTF-8', 'UTF-8');
    }
}
