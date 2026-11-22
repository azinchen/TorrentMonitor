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
php-ctype php-curl php-iconv php-mbstring php-pdo php-simplexml php-xml php-zip
```

Пример установки в Debian/Ubuntu:

```bash
sudo apt install nginx php-fpm php-curl php-mbstring php-xml php-zip
```

---

## Шаг 1 — Скачать TorrentMonitor

Скачайте архив последней версии со страницы [Releases](https://github.com/azinchen/TorrentMonitor/releases)
и распакуйте в каталог веб-сервера. Пока версий на этой странице нет, возьмите
текущее состояние репозитория:

```bash
cd /var/www
wget https://github.com/azinchen/TorrentMonitor/archive/refs/heads/master.zip
unzip master.zip
mv TorrentMonitor-master torrentmonitor
```

## Шаг 2 — Каталог данных

Все настройки, учётные данные трекеров и отслеживаемые темы TorrentMonitor хранит
в трёх JSON-файлах (`config.json`, `torrents.json`, `state.json`) в каталоге данных.
База данных не нужна, и файл `config.php` для работы не обязателен.

По умолчанию каталог данных — `data/` внутри каталога TorrentMonitor; он создаётся
при первом запуске. В файлах лежат пароли **в открытом виде**, поэтому:

- лучше вынести каталог за пределы каталога сайта — задайте переменную окружения
  `TM_DATA_DIR` (для веб-сервера **и** для cron) или создайте `config.php`
  из `config.php.example` со строкой
  `Config::write('data.dir', '/var/lib/torrentmonitor');`
- если каталог остаётся внутри сайта, закройте его в настройках веб-сервера
  (пример для nginx ниже; для Apache в каталоге автоматически создаётся `.htaccess`);
- страница **Тестирование** проверяет, что каталог данных не открывается из браузера.

Резервная копия установки — это копия каталога данных.

> Переходите с версии 3.x с базой данных? См. [Обновление](Updating#переход-с-версии-с-базой-данных).

## Шаг 3 — Необязательный config.php

`config.php` нужен, только если требуется одна из настроек из `config.php.example`:
другой каталог данных, путь к CLI-интерпретатору PHP для `POST /api/run`
или число одновременных запросов к трекерам.

## Шаг 4 — Права на файлы

Каталог должен принадлежать пользователю, от которого работает веб-сервер и PHP
(`www-data` в Debian/Ubuntu, `apache` в RHEL/CentOS, `nginx` в некоторых сборках):

```bash
chown -R www-data:www-data /var/www/torrentmonitor
```

## Шаг 5 — Настроить веб-сервер

Никаких особых правил переписывания адресов TorrentMonitor не требует —
достаточно обычного виртуального хоста с обработкой PHP.

Пример server-блока nginx (PHP-FPM):

```nginx
server {
    listen 80;
    server_name tm.example.com;
    root /var/www/torrentmonitor;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    # каталог данных содержит пароли
    location ^~ /data/ {
        deny all;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

Для Apache достаточно обычного VirtualHost с `DocumentRoot /var/www/torrentmonitor`.

> ⚠️ Не открывайте TorrentMonitor напрямую в интернет без включённого входа по паролю
> (и желательно HTTPS): интерфейс управляет вашим торрент-клиентом и хранит учётные данные
> трекеров.

## Шаг 6 — Первый вход

Откройте адрес TorrentMonitor в браузере. Пароль по умолчанию:

```
torrentmonitor
```

**Сразу смените его** в «Настройки → Смена пароля».

## Шаг 7 — Настроить cron

Проверку трекеров выполняет скрипт `engine.php` — его нужно запускать по расписанию.
Добавьте в crontab пользователя веб-сервера (например, `sudo crontab -u www-data -e`):

```cron
*/10 * * * * php /var/www/torrentmonitor/engine.php >> /var/www/torrentmonitor/torrent_monitor_error.log 2>&1
```

Каждые 10 минут — разумный интервал; слишком частые запросы могут привести к бану
на трекерах. Ошибки будут копиться в `torrent_monitor_error.log`.

Проверить работу движка можно и вручную — из веб-интерфейса
(раздел «Запуск») или из консоли:

```bash
sudo -u www-data php /var/www/torrentmonitor/engine.php
```

---

## Что дальше

Продолжайте с [Первоначальной настройкой](Getting-Started): учётные данные трекеров,
подключение торрент-клиента и добавление первой раздачи.
