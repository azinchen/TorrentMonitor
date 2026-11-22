<?php
//Этот файл виден, только если корень сайта указывает на каталог программы, а не на public,
//и веб-сервер не выполняет .htaccess (nginx и другие).
http_response_code(503);
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><meta charset="utf-8"><title>TorrentMonitor</title>
<body style="font-family:sans-serif;max-width:46em;margin:3em auto;line-height:1.5">
<h1>Нужно изменить корень сайта</h1>
<p>Начиная с версии 4.0 корнем сайта TorrentMonitor должен быть каталог <b><?= htmlspecialchars(__DIR__.DIRECTORY_SEPARATOR.'public') ?></b>.
Так настройки, пароли и данные программы недоступны из браузера.</p>
<p>Измените в настройках веб-сервера путь к сайту (root в nginx, DocumentRoot в Apache) на этот каталог
и направьте все запросы к несуществующим файлам на <code>index.php</code>. Пример для nginx:</p>
<pre>root <?= htmlspecialchars(__DIR__.'/public') ?>;
location / { try_files $uri /index.php$is_args$args; }</pre>
<p>Подробности — в вики, раздел «Обновление».</p>
</body>
