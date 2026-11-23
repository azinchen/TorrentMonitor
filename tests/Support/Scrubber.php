<?php
// Removes account-specific values from recorded tracker pages before they are
// saved as fixtures, so that nothing private ends up in the repository.
class Scrubber
{
    // $secrets: name => value (from tests/env/secrets); every occurrence of a value is replaced
    public static function scrub($text, $secrets = array())
    {
        foreach ($secrets as $name => $value)
        {
            if (strlen($value) < 4)
                continue;
            foreach (array_unique(array($value, urlencode($value), htmlspecialchars($value), mb_convert_encoding($value, 'windows-1251', 'UTF-8'))) as $variant)
                if ($variant !== false && $variant !== '')
                    $text = str_replace($variant, '{{'.$name.'}}', $text);
        }

        $patterns = array(
            // keys that identify the account in links and forms
            '/((?:passkey|authkey|rsskey|torrent_pass|uk|pk)["\']?\s*[=:]\s*["\']?)[0-9A-Za-z]{8,}/i' => '$1SCRUBBED',
            // session cookies echoed into pages or headers
            '/\b(bb_session|bb_data|phpbb2mysql_data|PHPSESSID|pass|uid|cf_clearance)=[^;&"\'\s<]+/' => '$1=SCRUBBED',
            // announce URLs with a personal key
            '#(https?://[^\s"\'<>]+/(?:announce|ann)(?:\.php)?[?/])[^\s"\'<>]+#i' => '$1SCRUBBED',
            // e-mail addresses
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/' => 'user@example.invalid',
            // IPv4 addresses (trackers print the visitor address)
            '/\b(?:\d{1,3}\.){3}\d{1,3}\b/' => '0.0.0.0',
        );
        foreach ($patterns as $pattern => $replacement)
            $text = preg_replace($pattern, $replacement, $text);

        return $text;
    }

    // names of the secrets whose values are still present in $text
    public static function leaks($text, $secrets)
    {
        $found = array();
        foreach ($secrets as $name => $value)
            if (strlen($value) >= 4 && strpos($text, $value) !== false)
                $found[] = $name;
        return $found;
    }
}
