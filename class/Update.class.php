<?php
//Обновление системы из архива выпуска GitHub Releases.
class Update
{
    //файлы и каталоги установки, которые обновление не трогает
    private static $keep = array('config.php', 'config.xml', 'torrents', 'laststart.txt', 'release.cache.json');
    //служебные файлы и каталоги репозитория, которые не нужны в установке
    private static $skip = array('.git', '.github', '.gitignore', '.gitattributes', '.claude', 'wiki', 'tests', 'tools', 'dist');

    private static $NL;
    private static $isCLI;

    private static function delTree($dir)
    {
        if ( ! is_dir($dir))
            return TRUE;
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file)
            (is_dir($dir.'/'.$file) && ! is_link($dir.'/'.$file)) ? Update::delTree($dir.'/'.$file) : unlink($dir.'/'.$file);
        return rmdir($dir);
    }

    //выводим строку простого текста: в браузере экранируем, в консоли печатаем как есть
    private static function say($text)
    {
        echo (Update::$isCLI ? $text : nl2br(htmlspecialchars($text))).Update::$NL;
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
            if ($top && in_array($entry, Update::$skip))
                continue;
            //настройки пользователя не перезаписываем, но создаём, если их ещё нет
            if ($top && in_array($entry, Update::$keep) && file_exists($to.'/'.$entry))
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
                foreach (Update::copyTree($src, $dst, FALSE) as $file)
                    $failed[] = $entry.'/'.$file;
            }
            elseif ( ! @copy($src, $dst))
                $failed[] = $entry;
        }
        return $failed;
    }

    private static function fail($text)
    {
        Update::say($text);
        Errors::setWarnings('system', 'update_fail');
        return FALSE;
    }

    public static function main()
    {
        Update::$isCLI = (php_sapi_name() == 'cli');
        Update::$NL = Update::$isCLI ? "\r\n" : '<br />'."\r\n";
        $root = rtrim(str_replace('\\', '/', dirname(dirname(__FILE__))), '/');

        //сведения о выпуске всегда запрашиваем заново, не из кеша
        $release = Release::latest(TRUE);
        $current = Release::currentVersion();
        Update::say('Текущая версия системы: '.$current);

        if ( ! is_array($release))
        {
            Update::say('Не удалось получить сведения о выпусках или выпусков ещё нет: '.Release::pageUrl());
            return FALSE;
        }

        Update::say('Актуальная версия: '.$release['version']);
        if ( ! Release::isNewer($release))
        {
            Update::say('Обновление не требуется.');
            return TRUE;
        }

        if ( ! empty($release['notes']))
            Update::say($release['notes']);

        if ( ! class_exists('ZipArchive'))
            return Update::fail('Для обновления необходимо расширение PHP zip.');
        if (empty($release['zip']))
            return Update::fail('В выпуске нет архива для обновления.');
        if ( ! is_writable($root) || ! is_writable($root.'/version.txt'))
            return Update::fail('Нет прав на запись в каталог системы, обновление прервано.');

        $archive = $root.'/update.zip';
        $tmp = $root.'/tmp_update';

        if ( ! Release::download($release['zip'], $archive))
            return Update::fail('Не удалось скачать архив выпуска.');

        Update::delTree($tmp);
        $zip = new ZipArchive;
        if ($zip->open($archive) !== TRUE || ! $zip->extractTo($tmp))
        {
            @unlink($archive);
            Update::delTree($tmp);
            return Update::fail('Не удалось распаковать архив выпуска.');
        }
        $zip->close();
        @unlink($archive);

        $source = Update::findSourceRoot($tmp);
        if ($source === NULL)
        {
            Update::delTree($tmp);
            return Update::fail('Архив выпуска не содержит файлов системы.');
        }

        $failed = Update::copyTree($source, $root);
        Update::delTree($tmp);

        if ( ! empty($failed))
        {
            foreach ($failed as $file)
                Update::say('Не удалось записать файл: '.$file);
            return Update::fail('Обновление до версии: '.$release['version'].' не выполнено.');
        }

        $version = array('system' => $release['version'], 'database' => $release['version']);
        if (file_put_contents($root.'/version.txt', json_encode($version)) === FALSE)
            return Update::fail('Не удалось записать version.txt.');

        if (function_exists('opcache_reset'))
            @opcache_reset();

        $msg = 'Обновление до версии: '.$release['version'].' выполнено успешно.';
        Update::say($msg);
        Database::clearWarnings('system');
        Database::setUpdateNotification(0);

        $newsId = 'updated_'.$release['version'];
        if ( ! Database::checkNewsExist($newsId))
        {
            $newsHtml = '<b>'.htmlspecialchars($msg).'</b>';
            if ( ! empty($release['notes']))
                $newsHtml .= '<br />'.nl2br(htmlspecialchars($release['notes']));
            Database::insertNews($newsId, $newsHtml);
        }

        if (Update::$isCLI)
        {
            $notifyMsg = $msg;
            if ( ! empty($release['notes']))
                $notifyMsg .= "\n\n".$release['notes'];
            Notification::sendNotification('news', date('r'), 0, $notifyMsg, 0);
        }
        else
            echo 'Перейти на <a href="'.htmlspecialchars(strval(Database::getSetting('serverAddress'))).'">главную страницу</a>.<br />';

        return TRUE;
    }
}
?>
