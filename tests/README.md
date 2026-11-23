# Tests

Everything runs locally in Docker. Nothing runs in GitHub Actions, nothing is
sent anywhere, and no test touches a production server.

## Requirements

Docker with Compose v2. PHP is not needed on the host.

## Commands

| Command | What it does | Needs |
|---|---|---|
| `tests/run.sh lint` | syntax check of every PHP file on PHP 8.0 and 8.5 | – |
| `tests/run.sh unit` | unit tests on PHP 8.0 and 8.5 | – |
| `tests/run.sh integration` | the application against a fake tracker and real torrent clients | – |
| `tests/run.sh all` | the three above; **must pass before a merge** | – |
| `tests/run.sh live` | real trackers, notification services and a Synology NAS with real accounts | `secrets/trackers.env`, `secrets/services.env` |
| `tests/run.sh reach` | which trackers answer from here, which show Cloudflare, and whether the solver passes it | – |
| `tests/run.sh record <tracker> <path> <name>` | save a scrubbed tracker page as a fixture | a running live stack |
| `tests/run.sh up` / `down` | start the stack for manual work at http://127.0.0.1:8088 / remove it | – |
| `tests/run.sh hooks` | install the pre-push hook that blocks pushing secrets | – |

`TM_PHP=8.0 tests/run.sh integration` runs the stack on PHP 8.0 (default 8.5).
Extra arguments go to PHPUnit: `tests/run.sh integration --filter Cloudflare`.

## What the integration stack contains

`tests/env/compose.yml` + `compose.offline.yml`:

- **app** – a copy of the working tree with an empty data directory
  (`TM_DATA_DIR=/data`). PHP serves only the `public/` folder, through the front
  controller (`php -S 0.0.0.0:80 -t /app/public /app/public/index.php`), as a real
  installation does. The repository is mounted read-only; tests cannot write into it.
- **fake** – a fake internet: rutracker.org (login, topic page, torrent download,
  optional Cloudflare challenge), the GitHub Releases API with a release archive,
  and a FlareSolverr/Byparr-compatible solver. The `cloudflareStrict` switch makes
  the clearance cookie useless for direct requests, so only the solver's browser
  passes the challenge. The tracker name resolves to this
  container and is served over TLS with a certificate created at start.
- **qbittorrent**, **transmission**, **deluge**, **torrserver** – real clients, to
  check what TorrentMonitor adds to them and removes from them.
- **gotify** – a real notification server; tests create an application in it and
  read back the message that arrived.
- e-mail – `mail()` in the app container hands messages to
  `tests/env/app/sendmail.sh`, which saves them to `/tmp/tm-mail` instead of sending.
- **mariadb**, **postgres** – hold old-format databases for the tests of
  `tools/convert-db.php` (the SQLite one is a file). The schemas of version 3.3.6
  are in `tests/Fixtures/legacy/`.

Tests change the behaviour of the fake through `Env::fake()` and read the state of
the application through `AppState`. `AppState` is the only class that knows how
the application stores data, so the tests survive a storage change.

The web interface is one page that talks to the server through `POST /ui/<action>`
with a JSON body. Tests send the same requests through three helpers:

- `Env::uiOpen()` – opens a new browser session (cookie jar + CSRF token) and
  returns the answer to the page's first request, `boot`;
- `Env::ui($action, $body)` – one action, e.g. `Env::ui('topic.add', array(...))`;
  opens a session first if there is none;
- `Env::uiClose()` – forgets the session.

The integration suite has 100 tests.

What the suites cover on top of the older tests:

- **unit** – `Unit/CoreTest.php`: the HTTP client (Cloudflare challenge detection,
  cookies, proxy selection, multipart bodies), bencode and info-hash, data file
  migrations, stop-after-days, recognising a tracker and topic by a URL, date parsing.
- **integration** – `Integration/CoreTest.php`: the run lock (a second run is
  skipped), `--dry-run`, `--topic` / `--tracker`, stop-after-days, no internet,
  the closed web root (only `public/` is served), `/torrents/<file>.torrent` and
  `/rss`, a solver whose cookies do not work directly. `Integration/SonarrWebhookTest.php`: Grab, folder and category
  of the torrent kept in the topic, other events accepted and ignored, SeriesDelete,
  Grab of an already tracked topic, Grab of an unknown torrent, Grab without a hash.
  `Integration/WebInterfaceTest.php`: the page and its files (nothing is loaded
  from other sites), setup of a new installation with and without a password,
  login, logout and "remember me", adding, editing and deleting a topic, adding a
  series, saving a tracker account and checking the login, starting a check and
  reading its log, errors shown with their topic and cleared, watched users
  (track, download once, hide), unknown actions refused.
  `Integration/SettingsTest.php` (rewritten for the new interface): the CSRF
  token, torrent client settings, only known settings accepted, notification
  service and address, the client connection check, the API key shown on request
  and replaced.

  `Integration/ServicesTest.php`: Deluge and TorrServer (a topic is sent and
  replaced on update, a wrong password or address is reported), Gotify (the test
  message arrives, a wrong token is refused), e-mail (the message handed to the
  mail system).

Covered only by live tests, because they need real accounts: every tracker except
rutracker.org, the notification services other than Gotify and e-mail, SynologyDS.

## Screenshots of the interface

Not part of the suite; a manual step for visual checks. Start the stack with
`tests/run.sh up` (http://127.0.0.1:8088) and take screenshots with headless
Chrome from the `zenika/alpine-chrome` image pointed at that address.
`tests/run.sh down` removes the stack.

## Secrets

Real logins live only in `tests/env/secrets/`, which git ignores (except the
`*.example` templates):

- `trackers.env` – tracker accounts for live tests. Copy `trackers.env.example`.
- `services.env` – keys of notification services and the account of a Synology
  NAS for live tests. Copy `services.env.example`.
- `vpn.env` – settings of the `azinchen/nordvpn-wg` container. When the file
  exists, live tests and `reach` go through the VPN (`TM_VPN_COUNTRY`, default
  Portugal); `TM_VPN=0` runs them without it. Copy `vpn.env.example`.

Run `tests/run.sh hooks` once: the pre-push hook refuses a push that contains a
secrets file or any value from one.

## Live tests

Live tests are run by hand only. They use real accounts and really download
`.torrent` files, which counts against the download limits of an account: two
files per tracker and run (more if the first topics picked turn out unusable).

**Trackers** (`Live/TrackerLiveTest.php`). For every tracker that has an account
in `trackers.env`, and for the trackers that need none:

1. log in and choose what to watch – see below;
2. run the engine: the release date and the name are read, one `.torrent` is saved
   and handed to qBittorrent, which keeps it stopped (nothing is downloaded from peers);
3. run again: nothing is downloaded, the saved session is used again;
4. turn the stored mark back (the update time of a topic, the last episode of a
   series) and run again: the `.torrent` is downloaded and delivered again, and
   qBittorrent still holds one torrent.

What to watch is chosen automatically. For a forum tracker the test opens the
tracker's own listing of fresh torrents, collects the links that lead to topics
and tries the newest ones (up to three: not every topic has a torrent). For a
series tracker it takes the newest suitable item of the RSS feed and watches that
series from the episode before it, so exactly one episode is new. `<NAME>_TOPIC`
in `trackers.env` fixes the topic or series instead; `TM_LIVE_AUTO=1` ignores
those fixed values.

Two more checks per tracker: `<NAME>_WATCH_USER` makes the test look up the topics
of that user (watching users), and `TM_LIVE_WRONG_PASSWORD=1` checks that a wrong
password is reported as such. The second is off by default, because repeated
failed logins make some trackers ask for a captcha.

**Services** (`Live/ServicesLiveTest.php`). Every notification service filled in
`services.env` gets one test message and must refuse a spoiled key. A Synology NAS
gets one torrent by link (the Debian netinst image unless `SYNOLOGY_TORRENT_URL`
is set), must refuse the same link twice, and the task is removed at once.

One tracker or service at a time: `tests/run.sh live --filter nnmclub`,
`tests/run.sh live --filter Telegram`.

`TM_SOLVER` chooses the Cloudflare solver and must be a full address:

| Solver | Without VPN | Through the VPN |
|---|---|---|
| Byparr (default) | `http://byparr:8191` | `http://127.0.0.1:8191` |
| FlareSolverr | `http://flaresolverr:8192` | `http://127.0.0.1:8192` |

FlareSolverr listens on port 8192 in the live stack, not on its usual 8191:
`TM_SOLVER=http://flaresolverr:8192 tests/run.sh live`.

`TM_KEEP=1 tests/run.sh live` leaves the live stack running afterwards
(needed for `record`); `tests/run.sh down` removes it.

Results so far (23.11.2026):

- rutracker.org, kinozal.me, rutor.is and lostfilm-mirror pass the whole tracker test
  with an automatically chosen topic or series, through the VPN.
  kinozal.me also passes with FlareSolverr.
- The kinozal.tv domain resolves to 127.0.0.1 on every DNS server; its entry logs in
  through kinozal.me (checked with the real account).
- rustorka.com shows its own captcha to every visitor: login by password is impossible.
- rutor.is answers from most networks tried, not from all. newstudio.tv answers from
  some networks only. baibako.tv answered from none and was removed from the program.
- tfile.cc answered with a parking page on every address and was removed from the program.
- The other trackers, the notification services and SynologyDS wait for accounts.

## Reachability

`tests/run.sh reach` starts the live stack (through the VPN when `vpn.env` exists)
and prints the exit country and, for every tracker, its address and the result:
the HTTP status, `no answer`, or `Cloudflare challenge` with `solved` / `NOT solved`.
The challenge is retried through the solver from `TM_SOLVER` (Byparr by default).
`TM_REACH_SOLVER=0` does not start Byparr.

## Recording tracker pages

`tests/run.sh record <tracker> <path> <name>` saves a page into
`tests/Fixtures/<tracker>/<name>.html` with account data removed
(`Support/Scrubber.php`). `<tracker>` is the tracker name (`rutracker.org`),
`<path>` is the path on the site, not a full URL:

```bash
TM_KEEP=1 tests/run.sh live
tests/run.sh record rutracker.org '/forum/viewtopic.php?t=1' topic
```

It needs a running live stack in which the tracker account is already saved, so
run the live tests with `TM_KEEP=1` first. The tool logs in as the application
does and prints the HTTP status and size to stderr.
Read the file before committing it.
