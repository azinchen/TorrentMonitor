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
| `tests/run.sh reach` | which trackers answer from here, and which show Cloudflare | – |
| `tests/run.sh up` / `down` | start the stack for manual work at http://127.0.0.1:8088 / remove it | – |
| `tests/run.sh hooks` | install the pre-push hook that blocks pushing secrets | – |

`TM_PHP=8.0 tests/run.sh integration` runs the stack on PHP 8.0 (default 8.5).
Extra arguments go to PHPUnit: `tests/run.sh integration --filter Cloudflare`.

## What the integration stack contains

`tests/env/compose.yml` + `compose.offline.yml`:

- **app** – a copy of the working tree served by PHP, with a fresh SQLite database.
  The repository is mounted read-only; tests cannot write into it.
- **fake** – a fake internet: rutracker.org (login, topic page, torrent download,
  optional Cloudflare challenge), the GitHub Releases API with a release archive,
  and a FlareSolverr/Byparr-compatible solver. The tracker name resolves to this
  container and is served over TLS with a certificate created at start.
- **qbittorrent**, **transmission** – real clients, to check what TorrentMonitor
  adds to them and removes from them.

Tests change the behaviour of the fake through `Env::fake()` and read the state of
the application through `AppState`. `AppState` is the only class that knows how
the application stores data, so the tests survive a storage change.

Not covered yet: Deluge, SynologyDS, TorrServer, notifications, MySQL and
PostgreSQL, every tracker except rutracker.org (offline) and kinozal (live only).

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
to a torrent client. Cloudflare is handled by Byparr by default;
`TM_SOLVER=http://flaresolverr:8192 tests/run.sh live` uses FlareSolverr
(through the VPN the addresses are `http://127.0.0.1:8191` and `:8192`).

## Recording tracker pages

`tests/run.sh record <tracker> <url> <name>` saves a page into
`tests/Fixtures/<tracker>/<name>.html` with account data removed
(`Support/Scrubber.php`), and refuses to save if a secret is still present.
Read the file before committing it anyway.
