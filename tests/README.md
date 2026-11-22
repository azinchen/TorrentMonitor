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
| `tests/run.sh live` | real trackers with real accounts | `secrets/trackers.env` |
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
- **qbittorrent**, **transmission** – real clients, to check what TorrentMonitor
  adds to them and removes from them.
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

The integration suite has 93 tests.

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

Not covered yet: Deluge, SynologyDS, TorrServer, notifications, every tracker
except rutracker.org (offline) and kinozal (live only).

## Screenshots of the interface

Not part of the suite; a manual step for visual checks. Start the stack with
`tests/run.sh up` (http://127.0.0.1:8088) and take screenshots with headless
Chrome from the `zenika/alpine-chrome` image pointed at that address.
`tests/run.sh down` removes the stack.

## Secrets

Real logins live only in `tests/env/secrets/`, which git ignores (except the
`*.example` templates):

- `trackers.env` – tracker accounts for live tests. Copy `trackers.env.example`.
- `vpn.env` – settings of the `azinchen/nordvpn-wg` container. When the file
  exists, live tests and `reach` go through the VPN (`TM_VPN_COUNTRY`, default
  Portugal). Copy `vpn.env.example`.

Run `tests/run.sh hooks` once: the pre-push hook refuses a push that contains a
secrets file or any value from one.

## Live tests

For each tracker with a login in `trackers.env`: log in, read one topic, download
its `.torrent`, run again and check nothing is downloaded twice. Nothing is sent
to a torrent client. A tracker without a login, password or topic in `trackers.env`
is skipped.

`TM_SOLVER` chooses the Cloudflare solver and must be a full address:

| Solver | Without VPN | Through the VPN |
|---|---|---|
| Byparr (default) | `http://byparr:8191` | `http://127.0.0.1:8191` |
| FlareSolverr | `http://flaresolverr:8192` | `http://127.0.0.1:8192` |

FlareSolverr listens on port 8192 in the live stack, not on its usual 8191:
`TM_SOLVER=http://flaresolverr:8193 tests/run.sh live`.

`TM_KEEP=1 tests/run.sh live` leaves the live stack running afterwards
(needed for `record`); `tests/run.sh down` removes it.

Results so far:

- kinozal.me passes with both Byparr and FlareSolverr: login, topic page and
  `.torrent` download behind Cloudflare.
- rutracker.org is skipped until a topic number and a login without a captcha
  are available.

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
