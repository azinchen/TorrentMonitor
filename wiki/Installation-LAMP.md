# Установка в LAMP

Классическая установка TorrentMonitor на собственный веб-сервер.
Если вы предпочитаете контейнеры — смотрите [Установку в Docker](Installation-Docker),
это проще и быстрее.

---

## Системные требования

| Компонент | Варианты |
|---|---|
| Веб-сервер | Apache, nginx или lighttpd |
| PHP | 8.0 или новее |
| База данных | не нужна: данные хранятся в JSON-файлах |

### Необходимые модули PHP

```
php-ctype php-curl php-mbstring php-pdo php-simplexml php-xml php-zip
```

Пример установки в Debian/Ubuntu:

```bash
sudo apt install nginx php-fpm php-curl php-mbstring php-xml php-zip
```

---

## Шаг 1 — Скачать TorrentMonitor

Скачайте архив последней версии со страницы [Releases](https://github.com/azinchen/TorrentMonitor/releases)
и распакуйте на сервере:

```bash
cd /var/www
unzip TorrentMonitor-*.zip
mv TorrentMonitor-*/ torrentmonitor
```

## Шаг 2 — Каталог данных

Все настройки, учётные данные трекеров и отслеживаемые темы TorrentMonitor хранит
в трёх JSON-файлах (`config.json`, `torrents.json`, `state.json`) в каталоге данных.
База данных не нужна, и файл `config.php` для работы не обязателен.

По умолчанию каталог данных — `data/` внутри каталога TorrentMonitor; он создаётся
при первом запуске. В файлах лежат пароли **в открытом виде**. Корень сайта —
каталог `public/` (см. шаг 5), поэтому `data/`, `config.php`, `torrents/` и сама
программа лежат выше него и из браузера недоступны.

- Каталог данных можно перенести в другое место: задайте переменную окружения
  `TM_DATA_DIR` (для веб-сервера **и** для cron) или создайте `config.php`
  из `config.php.example` со строкой
  `Config::write('data.dir', '/var/lib/torrentmonitor');`
- Раздел **Состояние** (блок «Проверка работы») предупреждает, если каталог данных
  лежит внутри каталога сайта.

Резервная копия установки — это копия каталога данных.

> Переходите с версии 3.x? См. [Обновление](Updating#переход-с-версии-3x).

## Шаг 3 — Необязательный config.php

`config.php` нужен, только если требуется одна из настроек из `config.php.example`:
другой каталог данных (`data.dir`), путь к CLI-интерпретатору PHP для запуска
проверки из интерфейса и через `POST /api/run` (`php.cli`), число одновременных запросов к одному трекеру (`curl.concurrency`; то же задаётся в интерфейсе,
в расширенных настройках, и значение из интерфейса важнее),
другой каталог для скачанных `.torrent`-файлов (`torrents.dir`) или адрес
проверки подключения к интернету (`connectivity.url`).

## Шаг 4 — Права на файлы

Каталог должен принадлежать пользователю, от которого работает веб-сервер и PHP
(`www-data` в Debian/Ubuntu, `apache` в RHEL/CentOS, `nginx` в некоторых сборках):

```bash
chown -R www-data:www-data /var/www/torrentmonitor
```

## Шаг 5 — Настроить веб-сервер

Корень сайта — каталог `public/` внутри каталога TorrentMonitor. Программа,
`config.php`, `data/`, `torrents/` и `src/` лежат выше него и из браузера недоступны.
Все запросы обрабатывает один файл — `public/index.php`.

### nginx

Пример server-блока (PHP-FPM):

```nginx
server {
    listen 80;
    server_name tm.example.com;
    root /var/www/torrentmonitor/public;
    index index.php;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

Если `root` указывает на каталог программы, а не на `public/`, вместо интерфейса
откроется страница «Нужно изменить корень сайта» с инструкцией.

### Apache

```apache
<VirtualHost *:80>
    ServerName tm.example.com
    DocumentRoot /var/www/torrentmonitor/public

    <Directory /var/www/torrentmonitor/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Файл `public/.htaccess` направляет все запросы в `index.php`. Для этого нужен модуль
`mod_rewrite` (`sudo a2enmod rewrite`); без него используется `FallbackResource`.

Если `DocumentRoot` указывает на каталог программы, а `mod_rewrite` включён,
`.htaccess` в каталоге программы сам передаёт запросы в `public/`.

### Встроенный сервер PHP

Чтобы быстро попробовать TorrentMonitor без веб-сервера:

```bash
cd /var/www/torrentmonitor
php -S 0.0.0.0:8080 -t public public/index.php
```

> ⚠️ Не открывайте TorrentMonitor напрямую в интернет без включённого входа по паролю
> (и желательно HTTPS): интерфейс управляет вашим торрент-клиентом и хранит учётные данные
> трекеров.

## Шаг 6 — Первый запуск

Откройте адрес TorrentMonitor в браузере. Новая установка показывает форму
**«Настройка TorrentMonitor»**: пароль для входа, адрес TorrentMonitor и, по желанию,
torrent-клиент. Пароля по умолчанию нет — вы задаёте его сами. Подробно —
[Первоначальная настройка](Getting-Started#шаг-1--первый-запуск).

Выполните настройку сразу: пока она не сделана, форму увидит любой, кто откроет
адрес TorrentMonitor.

## Шаг 7 — Настроить cron

Проверку трекеров выполняет скрипт `engine.php` — его нужно запускать по расписанию.
Добавьте в crontab пользователя веб-сервера (например, `sudo crontab -u www-data -e`):

```cron
*/10 * * * * php /var/www/torrentmonitor/engine.php >> /var/www/torrentmonitor/torrent_monitor_error.log 2>&1
```

Каждые 10 минут — разумный интервал; слишком частые запросы могут привести к бану
на трекерах. Ошибки будут копиться в `torrent_monitor_error.log`.

`engine.php` лежит в каталоге программы и из браузера не открывается.
Два запуска одновременно не работают: если предыдущая проверка ещё идёт,
новый запуск пропускается.

Проверить работу движка можно и вручную — из веб-интерфейса
(кнопка «Проверить сейчас») или из консоли:

```bash
sudo -u www-data php /var/www/torrentmonitor/engine.php
```

### Параметры engine.php

| Параметр | Что делает |
|---|---|
| `--tracker=<трекер>` | проверить только темы этого трекера, например `--tracker=rutracker.org` |
| `--topic=<id>` | проверить только одну тему; `<id>` — идентификатор темы из [API](API) |
| `--dry-run` | показать найденные обновления, ничего не скачивая и не меняя |
| `--help` | показать список параметров |

Код завершения: `0` — проверка выполнена; `1` — запуск отклонён (проверка уже
выполняется, данные прежней версии не перенесены, файл данных повреждён).
Если проверка уже идёт, выводится «TorrentMonitor уже выполняется: этот запуск пропущен.»

Перед опросом трекеров проверяется подключение к интернету
(`https://www.google.com/generate_204` и `https://ya.ru/`; достаточно любого ответа).
Если его нет, трекеры не опрашиваются, а в «Состояние → Ошибки» записывается одно
предупреждение «Нет подключения к интернету» вместо ошибки по каждому трекеру.
Адрес проверки можно изменить или отключить в `config.php`:

```php
Config::write('connectivity.url', 'https://example.org/'); // пустая строка отключает проверку
```

---

## Что дальше

Продолжайте с [Первоначальной настройкой](Getting-Started): учётные данные трекеров,
подключение торрент-клиента и добавление первой раздачи.
