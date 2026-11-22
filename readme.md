# TorrentMonitor
Приложение мониторит изменения на популярных торрент-трекерах рунета и автоматизирует закачку обновлений (сериалы, раздачи которые ведутся *путем добавления новых серий/новых версий*, перезалитые торрент-файлы и т.д.)

Это продолжение проекта [ElizarovEugene/TorrentMonitor](https://github.com/ElizarovEugene/TorrentMonitor) Евгения Елизарова.

### Документация и версии:
* [Вики](https://github.com/azinchen/TorrentMonitor/wiki)
* [Версии и список изменений](https://github.com/azinchen/TorrentMonitor/releases)

### Список возможностей приложения:
- Слежение за темами
  - anidub.com
  - animelayer.ru
  - baibako.tv
  - booktracker.org
  - kinozal.me
  - lostfilm.tv
  - newstudio.tv
  - nnmclub.to
  - pornolab.net
  - riperam.org
  - rustorka.com
  - rutor.is
  - rutracker.org
  - tfile.cc
- Слежение за релизерами
  - booktracker.org
  - nnmclub.to
  - pornolab.net
  - rutracker.org
  - tfile.cc
- Поиск новых серий (SD/HD 720/HD 1080 версии на выбор)
  - baibako.tv 
  - lostfilm.tv (+ собственное зеркало)
  - newstudio.tv
- Работа через proxy (SOCKS5/HTTP), зеркала трекеров, FlareSolverr/Byparr для Cloudflare
- Управление торрент-клиентами (добавление/удаление раздач и файлов)
  - Transmission
  - Deluge
  - qBittorrent
  - Synology Download Station
  - TorrServer
- REST API и webhook для Sonarr
- Сервисы уведомлений:
  - E-mail
  - Prowl
  - Pushbullet
  - Pushover
  - Pushall
  - Telegram 
- RSS-лента
- Выполенение собственных скриптов после обновления раздачи

### Требования для установки:
* Веб-сервер (Apache, nginx, lighttpd); корень сайта — каталог `public/`
* PHP 8.0 или выше с расширениями cURL, mbstring, SimpleXML и zip
* База данных не нужна: настройки и темы хранятся в JSON-файлах в каталоге `data/`

### Docker hub:
https://hub.docker.com/r/alfonder/torrentmonitor

### Скриншоты:
 ![Screenshot0](https://habrastorage.org/webt/yy/xq/2g/yyxq2gn8o5-b68zr-m_acdv78w8.png "Screenshot0")
 ![Screenshot1](https://habrastorage.org/webt/do/fl/cd/doflcdnaxhg4elpis4jyg30tzik.png "Screenshot1")
 ![Screenshot2](https://habrastorage.org/webt/ad/m5/tk/adm5tktyrelde8fur565aprrpia.png "Screenshot2")
 ![Screenshot3](https://habrastorage.org/webt/5v/9n/ww/5v9nww4n2ahujooewnichz3emoa.png "Screenshot3")
 ![Screenshot4](https://habrastorage.org/webt/qs/i7/y5/qsi7y53vb8qnl0y0ifcrxbcvv78.png "Screenshot4")
 ![Screenshot5](https://habrastorage.org/webt/nz/n9/zd/nzn9zdlnhje6blm7dsbk7nnzxnk.png "Screenshot5")
 ![Screenshot6](https://habrastorage.org/webt/ta/wl/pz/tawlpzlptcv1frusl8lv_tyyc-u.png "Screenshot6")

### Лицензия:
[MIT](LICENSE). Сведения о стороннем коде — в файле [NOTICE](NOTICE).
