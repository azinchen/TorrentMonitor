<?php
namespace TorrentMonitor\Update;

use TorrentMonitor\App;
use TorrentMonitor\Data\News;
use TorrentMonitor\Data\Settings;
use TorrentMonitor\Data\State;
use TorrentMonitor\Data\Warnings;
use TorrentMonitor\Engine\Problems;
use TorrentMonitor\Notify\Notifications;

//Обновление системы из архива выпуска GitHub Releases.
final class Updater
{
    //файлы и каталоги установки, которые обновление не трогает
    private static $keep = array('config.php', 'data', 'torrents', 'scripts');
    //файлы и каталоги прежних версий, которых в новых выпусках нет: удаляются после обновления
    private static $obsolete = array('class', 'trackers', 'include', 'pages', 'api', 'rss', 'assets', 'img', 'action.php', 'db_schema', 'config.xml', 'changelog.txt');
    //служебные файлы и каталоги репозитория, которые не нужны в установке
    private static $skip = array('.git', '.github', '.gitignore', '.gitattributes', '.claude', 'wiki', 'tests', 'dist');

    private static $NL;
    private static $isCLI;

    private static function delTree($dir)
    {
        if ( ! is_dir($dir))
            return TRUE;
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file)
            (is_dir($dir.'/'.$file) && ! is_link($dir.'/'.$file)) ? self::delTree($dir.'/'.$file) : unlink($dir.'/'.$file);
        return rmdir($dir);
    }

    //выводим строку простого текста: в браузере экранируем, в консоли печатаем как есть
    private static function say($text)
    {
        echo (self::$isCLI ? $text : nl2br(htmlspecialchars($text))).self::$NL;
    }

    //каталог с файлами системы внутри распакованного архива: либо сам архив,
    //либо единственный вложенный каталог (так упакованы архивы GitHub)
    private static function findSourceRoot($dir)
    {
        if (is_file($dir.'/version.txt') && is_file($dir.'/index.php'))
            return $dir;

        $entries = array_values(array_diff(scandir($dir), array('.', '..')));
        if (count($entries) == 1 && is_dir($dir.'/'.$entries[0]))
        {
            $nested = $dir.'/'.$entries[0];
            if (is_file($nested.'/version.txt') && is_file($nested.'/index.php'))
                return $nested;
        }
        return NULL;
    }

    //копируем файлы выпуска поверх установки, возвращаем список файлов, которые не удалось записать
    private static function copyTree($from, $to, $top = TRUE)
    {
        $failed = array();
        foreach (array_diff(scandir($from), array('.', '..')) as $entry)
        {
            if ($top && in_array($entry, self::$skip))
                continue;
            //настройки пользователя не перезаписываем, но создаём, если их ещё нет
            if ($top && in_array($entry, self::$keep) && file_exists($to.'/'.$entry))
                continue;
            //version.txt записывается последним, после успешного копирования
            if ($top && $entry == 'version.txt')
                continue;

            $src = $from.'/'.$entry;
            $dst = $to.'/'.$entry;
            if (is_dir($src))
            {
                if ( ! is_dir($dst) && ! @mkdir($dst, 0755, TRUE))
                {
                    $failed[] = $entry.'/';
                    continue;
                }
                foreach (self::copyTree($src, $dst, FALSE) as $file)
                    $failed[] = $entry.'/'.$file;
            }
            elseif ( ! @copy($src, $dst))
                $failed[] = $entry;
        }
        return $failed;
    }

    //Удаляет файлы прежней версии, которых нет в новом выпуске: в каталогах программы
    //и устаревшие каталоги целиком. Данные пользователя не затрагиваются.
    private static function removeStale($source, $root)
    {
        foreach (self::$obsolete as $entry)
        {
            if (file_exists($source.'/'.$entry) || ! file_exists($root.'/'.$entry))
                continue;
            is_dir($root.'/'.$entry) ? self::delTree($root.'/'.$entry) : @unlink($root.'/'.$entry);
        }
        foreach (array_diff(scandir($source), array('.', '..')) as $entry)
        {
            if (in_array($entry, self::$skip) || in_array($entry, self::$keep) || ! is_dir($source.'/'.$entry))
                continue;
            self::mirror($source.'/'.$entry, $root.'/'.$entry);
        }
    }

    private static function mirror($from, $to)
    {
        if ( ! is_dir($to))
            return;
        foreach (array_diff(scandir($to), array('.', '..')) as $entry)
        {
            if ( ! file_exists($from.'/'.$entry))
                (is_dir($to.'/'.$entry) && ! is_link($to.'/'.$entry)) ? self::delTree($to.'/'.$entry) : @unlink($to.'/'.$entry);
            elseif (is_dir($from.'/'.$entry) && is_dir($to.'/'.$entry))
                self::mirror($from.'/'.$entry, $to.'/'.$entry);
        }
    }

    private static function fail($text)
    {
        self::say($text);
        Problems::report('system', 'update_fail');
        return FALSE;
    }

    public static function main()
    {
        self::$isCLI = (php_sapi_name() == 'cli');
        self::$NL = self::$isCLI ? "\r\n" : '<br />'."\r\n";
        $root = rtrim(str_replace('\\', '/', App::root()), '/');

        //сведения о выпуске всегда запрашиваем заново, не из кеша
        $release = Release::latest(TRUE);
        $current = Release::currentVersion();
        self::say('Текущая версия системы: '.$current);

        if ( ! is_array($release))
        {
            self::say('Не удалось получить сведения о выпусках или выпусков ещё нет: '.Release::pageUrl());
            return FALSE;
        }

        self::say('Актуальная версия: '.$release['version']);
        if ( ! Release::isNewer($release))
        {
            self::say('Обновление не требуется.');
            return TRUE;
        }

        if ( ! empty($release['notes']))
            self::say($release['notes']);

        if ( ! class_exists('ZipArchive'))
            return self::fail('Для обновления необходимо расширение PHP zip.');
        if (empty($release['zip']))
            return self::fail('В выпуске нет архива для обновления.');
        if ( ! is_writable($root) || ! is_writable($root.'/version.txt'))
            return self::fail('Нет прав на запись в каталог системы, обновление прервано.');

        $archive = $root.'/update.zip';
        $tmp = $root.'/tmp_update';

        if ( ! Release::download($release['zip'], $archive))
            return self::fail('Не удалось скачать архив выпуска.');

        self::delTree($tmp);
        $zip = new \ZipArchive;
        if ($zip->open($archive) !== TRUE || ! $zip->extractTo($tmp))
        {
            @unlink($archive);
            self::delTree($tmp);
            return self::fail('Не удалось распаковать архив выпуска.');
        }
        $zip->close();
        @unlink($archive);

        $source = self::findSourceRoot($tmp);
        if ($source === NULL)
        {
            self::delTree($tmp);
            return self::fail('Архив выпуска не содержит файлов системы.');
        }

        $failed = self::copyTree($source, $root);
        if (empty($failed))
            self::removeStale($source, $root);
        self::delTree($tmp);

        if ( ! empty($failed))
        {
            foreach ($failed as $file)
                self::say('Не удалось записать файл: '.$file);
            return self::fail('Обновление до версии: '.$release['version'].' не выполнено.');
        }

        $version = array('system' => $release['version'], 'database' => $release['version']);
        if (file_put_contents($root.'/version.txt', json_encode($version)) === FALSE)
            return self::fail('Не удалось записать version.txt.');

        if (function_exists('opcache_reset'))
            @opcache_reset();

        $msg = 'Обновление до версии: '.$release['version'].' выполнено успешно.';
        self::say($msg);
        Warnings::clear('system');
        State::setUpdateNotified(FALSE);

        $newsId = 'updated_'.$release['version'];
        if ( ! News::exists($newsId))
        {
            $newsHtml = '<b>'.htmlspecialchars($msg).'</b>';
            if ( ! empty($release['notes']))
                $newsHtml .= '<br />'.nl2br(htmlspecialchars($release['notes']));
            News::put($newsId, $newsHtml);
        }

        if (self::$isCLI)
            Notifications::news($msg.( ! empty($release['notes']) ? "\n\n".$release['notes'] : ''));
        else
            echo 'Перейти на <a href="'.htmlspecialchars((string)Settings::get('serverAddress')).'">главную страницу</a>.<br />';

        return TRUE;
    }
}
