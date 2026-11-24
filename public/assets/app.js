// TorrentMonitor web interface. A single page; data comes from the server via POST /ui/<action> requests.
// Uses Alpine.js (assets/vendor/alpine.min.js); the markup is in src/Web/page.html.

const QUALITY = { sd: 'SD', 720: '720p', 1080: '1080p' };
const PAGES = [
    { id: 'topics', title: 'Торренты' },
    { id: 'users', title: 'Пользователи' },
    { id: 'trackers', title: 'Трекеры' },
    { id: 'settings', title: 'Настройки' },
    { id: 'status', title: 'Состояние' },
];

document.addEventListener('alpine:init', () => {
    Alpine.data('app', () => ({
        // ---- state
        loading: true,          // first load
        busy: '',               // the action currently running (to disable buttons)
        fatal: '',              // the server cannot work (corrupted data, etc.)
        csrf: '',
        mode: 'app',            // setup | login | app
        page: 'topics',
        pages: PAGES,
        theme: localStorage.getItem('tm-theme') || 'auto',
        advanced: localStorage.getItem('tm-advanced') === '1',  // settings: show the ones that rarely need changing
        compact: localStorage.getItem('tm-compact') === '1',   // topics list: one line per topic
        expanded: null,         // topic shown in full while the list is compact
        toasts: [],

        // data from the server
        topics: [], users: [], trackers: [], settings: {}, warnings: [], categories: [], sonarrTitles: [], clients: [], services: [], timezones: [],
        status: { version: '', lastRun: '', running: false, client: null, update: null, authRequired: true, wiki: '' },

        // forms
        login: { password: '', remember: true },
        setup: { password: '', password2: '', noPassword: false, serverAddress: '', torrentClient: '', torrentAddress: '', torrentLogin: '', torrentPassword: '', pathToDownload: '' },
        filter: { text: '', tracker: '', state: '' },
        topicForm: null,        // topic being added or edited
        deleteForm: null,       // topic deletion confirmation
        userForm: { tracker: '', name: '' },
        openTracker: '',        // tracker expanded for editing
        trackerForm: {},
        settingsForm: {},
        passwordForm: { current: '', password: '', password2: '' },
        apiKey: '',
        health: null,
        runLog: '',
        logTimer: null,

        // ---- startup
        async init() {
            this.applyTheme();
            const fromHash = location.hash.slice(1);
            if (PAGES.some(p => p.id === fromHash)) this.page = fromHash;
            window.addEventListener('hashchange', () => {
                const id = location.hash.slice(1);
                if (PAGES.some(p => p.id === id)) this.open(id);
            });
            const data = await this.call('boot', {}, { silent: true });
            this.loading = false;
            if (!data) return;
            this.csrf = data.csrf;
            if (data.setup) {
                this.mode = 'setup';
                this.setup.serverAddress = data.suggestedAddress;
            } else if (!data.in) {
                this.mode = 'login';
            } else {
                this.enter(data);
            }
        },

        enter(data) {
            this.mode = 'app';
            this.apply(data);
            this.open(this.page);
            if (this.status.running) this.watchRun();
        },

        // distributes the server response into fields
        apply(data) {
            for (const key of ['topics', 'users', 'trackers', 'warnings', 'categories', 'sonarrTitles', 'clients', 'services', 'timezones', 'status']) {
                if (data[key] !== undefined) this[key] = data[key];
            }
            if (data.settings !== undefined) {
                this.settings = data.settings;
                this.settingsForm = this.editableSettings(data.settings);
            }
            if (this.openTracker) {
                const tracker = this.trackers.find(t => t.name === this.openTracker);
                if (tracker) this.trackerForm = this.editableTracker(tracker);
            }
        },

        // ---- server requests
        async call(action, body = {}, options = {}) {
            if (!options.silent) this.busy = action;
            try {
                const response = await fetch('ui/' + action, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf },
                    body: JSON.stringify(body),
                    credentials: 'same-origin',
                });
                let data = null;
                try { data = await response.json(); } catch (e) { /* not JSON: server error */ }
                if (response.status === 401) { this.mode = 'login'; return null; }
                if (response.status === 503 && data) { this.fatal = data.message; return null; }
                if (!data) { this.toast('Сервер ответил ошибкой (' + response.status + ').', true); return null; }
                if (!data.ok) { this.toast(data.message, true); return null; }
                if (data.message && !options.quiet) this.toast(data.message);
                return data;
            } catch (e) {
                this.toast('Нет связи с сервером.', true);
                return null;
            } finally {
                if (!options.silent) this.busy = '';
            }
        },

        // an action after which the server returns fresh data
        async act(action, body = {}, options = {}) {
            const data = await this.call(action, body, options);
            if (data) this.apply(data);
            return data;
        },

        toast(text, error = false) {
            const item = { id: Date.now() + Math.random(), text, error };
            this.toasts.push(item);
            setTimeout(() => { this.toasts = this.toasts.filter(t => t.id !== item.id); }, error ? 8000 : 4000);
        },

        // ---- appearance and navigation
        applyTheme() {
            const dark = this.theme === 'dark' || (this.theme === 'auto' && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.dataset.theme = dark ? 'dark' : 'light';
        },
        nextTheme() {
            this.theme = { auto: 'light', light: 'dark', dark: 'auto' }[this.theme];
            localStorage.setItem('tm-theme', this.theme);
            this.applyTheme();
        },
        saveAdvanced() { localStorage.setItem('tm-advanced', this.advanced ? '1' : '0'); },
        saveCompact() { localStorage.setItem('tm-compact', this.compact ? '1' : '0'); this.expanded = null; },
        themeTitle() { return { auto: 'Тема: как в системе', light: 'Тема: светлая', dark: 'Тема: тёмная' }[this.theme]; },

        open(id) {
            this.page = id;
            if (location.hash.slice(1) !== id) history.replaceState(null, '', '#' + id);
            if (id === 'status') {
                this.loadLog();
                if (!this.health) this.checkHealth();
            }
        },
        badge(id) {
            if (id === 'status') return this.warnings.length + (this.status.update ? 1 : 0);
            if (id === 'users') return this.users.reduce((n, u) => n + u.found.length, 0);
            if (id === 'trackers') return this.trackers.filter(t => t.needsAccount && !t.ready && this.topics.some(x => x.tracker === t.name)).length;
            return 0;
        },

        // ---- login and initial setup
        async doLogin() {
            const data = await this.call('login', this.login);
            this.login.password = '';
            if (data) this.enter(data);
        },
        async doLogout() {
            await this.call('logout');
            location.reload();
        },
        async doSetup() {
            if (!this.setup.noPassword && this.setup.password !== this.setup.password2) {
                this.toast('Пароли не совпадают.', true);
                return;
            }
            const data = await this.call('setup', this.setup);
            if (data) {
                this.enter(data);
                this.toast('Готово. Теперь укажите учётные данные трекеров.');
                this.open('trackers');
            }
        },

        // ---- topics
        get shownTopics() {
            const text = this.filter.text.trim().toLowerCase();
            return this.topics
                .filter(t => !this.filter.tracker || t.tracker === this.filter.tracker)
                .filter(t => !text || (t.name + ' ' + t.tracker + ' ' + t.category).toLowerCase().includes(text))
                .filter(t => {
                    switch (this.filter.state) {
                        case 'error': return t.error;
                        case 'paused': return t.paused;
                        case 'stopped': return t.stopped;
                        default: return true;
                    }
                })
                .sort((a, b) => (b.updatedAtUtc || b.addedAtUtc || b.updatedAt || b.addedAt).localeCompare(a.updatedAtUtc || a.addedAtUtc || a.updatedAt || a.addedAt));
        },
        get usedTrackers() { return [...new Set(this.topics.map(t => t.tracker))].sort(); },
        get topicTrackers() { return this.trackers.filter(t => t.kind === 'topic'); },
        get seriesTrackers() { return this.trackers.filter(t => t.kind === 'series'); },
        get userTrackers() { return this.trackers.filter(t => t.watchesUsers); },
        quality(q) { return QUALITY[q] || q; },
        topicWarnings(topic) { return this.warnings.filter(w => w.topic === topic.id); },

        newTopic(kind) {
            this.topicForm = {
                id: 0, kind, url: '', tracker: this.seriesTrackers.length ? this.seriesTrackers[0].name : '', name: '', quality: 'sd',
                autoRename: true, path: '', category: '', script: '', stopAfterDays: '', paused: false, reset: false, more: false,
                sonarrTitle: '', sonarrSeasons: '',
            };
        },
        editTopic(topic) {
            this.topicForm = {
                id: topic.id, kind: topic.kind === 'series' ? 'series' : 'topic', url: topic.url, tracker: topic.tracker, name: topic.name,
                quality: topic.quality, autoRename: topic.autoRename, path: topic.path, category: topic.category, script: topic.script,
                stopAfterDays: topic.stopAfterDays === null ? '' : topic.stopAfterDays, paused: topic.paused, reset: false,
                sonarrTitle: topic.sonarrTitle || '', sonarrSeasons: (topic.sonarrSeasons || []).join(', '),
                more: !!(topic.path || topic.category || topic.script || topic.stopAfterDays !== null || topic.sonarrTitle),
            };
        },
        async saveTopic() {
            const data = await this.act(this.topicForm.id ? 'topic.save' : 'topic.add', this.topicForm);
            if (data) this.topicForm = null;
        },
        togglePause(topic) { this.act('topic.pause', { id: topic.id, paused: !topic.paused }); },
        async checkTopic(topic) {
            const data = await this.call('topic.check', { id: topic.id });
            if (data) this.afterRunStarted(data);
        },
        askDelete(topic) {
            this.deleteForm = { id: topic.id, name: topic.name, inClient: topic.inClient && !!this.status.client, removeFromClient: false,
                deleteData: this.settings.deleteFilesOnRemove === '1' };
        },
        async deleteTopic() {
            const data = await this.act('topic.delete', this.deleteForm);
            if (data) this.deleteForm = null;
        },

        // ---- users
        async addUser() {
            if (!this.userForm.tracker && this.userTrackers.length) this.userForm.tracker = this.userTrackers[0].name;
            const data = await this.act('user.add', this.userForm);
            if (data) this.userForm.name = '';
        },
        deleteUser(user) {
            if (confirm('Перестать следить за пользователем ' + user.name + '?')) this.act('user.delete', { id: user.id });
        },

        // ---- trackers
        editableTracker(t) {
            return { name: t.name, login: t.login, password: '', passkey: t.passkey, address: t.address, timezone: t.timezone || '', cookie: '', clearCookie: false,
                proxy: t.proxy, proxyAddress: t.proxyAddress, proxyType: t.proxyType || 'SOCKS5', showCookie: false };
        },
        toggleTracker(t) {
            this.openTracker = this.openTracker === t.name ? '' : t.name;
            if (this.openTracker) this.trackerForm = this.editableTracker(t);
        },
        trackerState(t) {
            if (t.warnings) return { text: 'ошибки: ' + t.warnings, kind: 'bad' };
            if (!t.needsAccount) return { text: 'вход не нужен', kind: 'muted' };
            if (t.ready) return { text: t.login ? 'учётная запись задана' : 'вход по cookie', kind: 'good' };
            return { text: 'не настроен', kind: 'muted' };
        },
        topicCount(t) { return this.topics.filter(x => x.tracker === t.name).length; },
        saveTracker() { return this.act('tracker.save', this.trackerForm); },
        async testTracker() {
            if (await this.saveTracker()) await this.act('tracker.test', { name: this.trackerForm.name });
        },

        forgetTracker(t) {
            const used = this.topicCount(t);
            const note = used ? ' Тем на этом трекере: ' + used + ' — они перестанут проверяться.' : '';
            if (confirm('Удалить логин, пароль и сохранённую сессию ' + t.name + '?' + note)) this.act('tracker.forget', { name: t.name });
        },

        // ---- settings
        editableSettings(s) {
            const form = Object.assign({}, s, { torrentPassword: '', clearTorrentPassword: false });
            for (const key of ['auth', 'rss', 'debug', 'autoUpdate', 'proxy', 'useTorrent', 'deleteDistribution', 'deleteOldFiles',
                'deleteFilesOnRemove', 'removeOnSeriesDelete', 'sonarrReplaceTopic', 'sonarrReplaceTorrent', 'send', 'sendUpdate', 'sendWarning']) {
                form[key] = s[key] === '1';
            }
            return form;
        },
        saveSettings() { return this.act('settings.save', this.settingsForm); },
        async testClient() {
            if (await this.saveSettings()) await this.call('client.test');
        },
        testNotify(what) {
            this.call('notify.test', { service: this.settingsForm['notify' + what + 'Service'], address: this.settingsForm['notify' + what + 'Address'] });
        },
        async changePassword() {
            if (this.passwordForm.password !== this.passwordForm.password2) {
                this.toast('Пароли не совпадают.', true);
                return;
            }
            const data = await this.act('settings.password', this.passwordForm);
            if (data) this.passwordForm = { current: '', password: '', password2: '' };
        },
        async showApiKey() {
            const data = await this.call('settings.showkey');
            if (data) this.apiKey = data.key;
        },
        async newApiKey() {
            if (this.settings.hasApiKey && !confirm('Создать новый ключ API? Прежний перестанет действовать, его нужно будет заменить в Sonarr и скриптах.')) return;
            const data = await this.act('settings.apikey');
            if (data) this.apiKey = data.key;
        },
        get webhookUrl() {
            return (this.settings.serverAddress || location.origin + '/') + 'api/sonarr?token=' + (this.apiKey || '<ключ API>');
        },
        async copy(text) {
            try { await navigator.clipboard.writeText(text); this.toast('Скопировано.'); } catch (e) { this.toast('Не удалось скопировать.', true); }
        },
        addressHint(service) {
            return {
                'E-mail': 'адрес почты',
                'Telegram': 'токен бота;id чата (несколько чатов через ;, тема чата — id:тема)',
                'Pushover': 'ключ пользователя;токен приложения',
                'Pushall': 'id;ключ',
                'Gotify': 'адрес сервера;токен приложения',
                'Prowl': 'ключ API',
                'Pushbullet': 'токен доступа',
            }[service] || '';
        },

        // ---- status
        async run() {
            const data = await this.call('run');
            if (data) this.afterRunStarted(data);
        },
        afterRunStarted(data) {
            if (data.running) {
                this.status.running = true;
                this.watchRun();
            } else {
                this.apply(data);
                this.loadLog();
            }
        },
        // while a check is running, refreshes the log; when it finishes, reloads the data
        watchRun() {
            clearInterval(this.logTimer);
            this.logTimer = setInterval(async () => {
                const data = await this.call('run.log', {}, { silent: true });
                if (!data) return;
                this.runLog = this.logLocal(data.log, data.offset);
                if (!data.running) {
                    clearInterval(this.logTimer);
                    this.status.running = false;
                    await this.act('overview', {}, { silent: true });
                    this.toast('Проверка завершена.');
                }
            }, 1500);
        },
        async loadLog() {
            const data = await this.call('run.log', {}, { silent: true });
            if (data) this.runLog = this.logLocal(data.log, data.offset);
        },
        // The log is stamped on the clock of the server ("[2026-11-24 00:04:42] ..."): the stamps are
        // shown on the clock of this browser. $offset - seconds the server's clock is ahead of UTC.
        logLocal(log, offset) {
            if (typeof offset !== 'number') return log;
            const pad = n => String(n).padStart(2, '0');
            return String(log || '').replace(/^\[(\d{4})-(\d\d)-(\d\d) (\d\d):(\d\d):(\d\d)\]/gm, (all, y, mo, d, h, mi, s) => {
                const t = new Date(Date.UTC(+y, mo - 1, +d, +h, +mi, +s) - offset * 1000);
                return '[' + t.getFullYear() + '-' + pad(t.getMonth() + 1) + '-' + pad(t.getDate()) + ' ' + pad(t.getHours()) + ':' + pad(t.getMinutes()) + ':' + pad(t.getSeconds()) + ']';
            });
        },
        async checkHealth() {
            const data = await this.call('health', {}, { silent: true });
            if (data) this.health = data.groups;
        },
        get warningGroups() {
            const groups = {};
            for (const w of this.warnings) (groups[w.source] = groups[w.source] || []).push(w);
            return Object.keys(groups).sort().map(source => ({ source, items: groups[source] }));
        },
        warningTopic(w) {
            const topic = this.topics.find(t => t.id === w.topic);
            return topic ? topic.name : '';
        },
        async installUpdate() {
            if (!confirm('Установить версию ' + this.status.update.version + '?')) return;
            const data = await this.call('update.install');
            if (data && data.reload) setTimeout(() => location.reload(), 1500);
        },

        // ---- helpers
        date(value) {
            if (!value) return '';
            const m = value.match(/^(\d{4})-(\d\d)-(\d\d)(?: (\d\d):(\d\d))?/);
            if (!m) return value;
            const months = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
            const year = Number(m[1]) === new Date().getFullYear() ? '' : ' ' + m[1];
            return Number(m[3]) + ' ' + months[Number(m[2]) - 1] + year + (m[4] ? ', ' + m[4] + ':' + m[5] : '');
        },
        // A release description as HTML. Only a small part of Markdown is understood: headings,
        // "-" lists, **bold**, `code` and [text](https://address). The text is escaped first, so
        // whatever else a description holds is shown as it is written and never becomes markup.
        // $repository - address of the repository on GitHub: "(#15)" and "(1a2b3c4)" at the end of an
        // item become links to that pull request or commit
        markdown(text, repository) {
            const escape = s => s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
            const inline = s => escape(s)
                .replace(/`([^`]+)`/g, '<code>$1</code>')
                .replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>')
                .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>')
                .replace(/\(((?:#\d+|[0-9a-f]{7,40})(?:,\s*(?:#\d+|[0-9a-f]{7,40}))*)\)/g, (all, refs) => !repository ? all
                    : '(' + refs.split(/,\s*/).map(ref => '<a href="' + repository + (ref[0] === '#' ? '/pull/' + ref.slice(1) : '/commit/' + ref)
                        + '" target="_blank" rel="noopener">' + ref + '</a>').join(', ') + ')');
            const out = [];
            let list = false, paragraph = [];
            const flush = () => {
                if (paragraph.length) out.push('<p>' + paragraph.join(' ') + '</p>');
                paragraph = [];
                if (list) out.push('</ul>');
                list = false;
            };
            for (const raw of String(text || '').replace(/\r/g, '').split('\n')) {
                const line = raw.trim();
                const heading = line.match(/^(#{1,4})\s+(.+)$/);
                const item = line.match(/^[-*]\s+(.+)$/);
                if (heading) {
                    flush();
                    out.push('<h' + (heading[1].length <= 2 ? 3 : 4) + '>' + inline(heading[2]) + '</h' + (heading[1].length <= 2 ? 3 : 4) + '>');
                } else if (item) {
                    if (paragraph.length) { out.push('<p>' + paragraph.join(' ') + '</p>'); paragraph = []; }
                    if (!list) { out.push('<ul>'); list = true; }
                    out.push('<li>' + inline(item[1]) + '</li>');
                } else if (line === '') {
                    flush();
                } else if (list && /^\s+\S/.test(raw)) {
                    // a wrapped line of a list item
                    out[out.length - 1] = out[out.length - 1].replace(/<\/li>$/, ' ' + inline(line) + '</li>');
                } else {
                    if (list) flush();
                    paragraph.push(inline(line));
                }
            }
            flush();
            return out.join('');
        },
        // a moment (UTC, 2026-11-23T11:23:00Z) on the clock of this browser; $fallback - the stored text, if there is no moment
        local(utc, fallback) {
            const time = utc ? new Date(utc) : null;
            if (!time || isNaN(time)) return this.date(fallback);
            const pad = n => String(n).padStart(2, '0');
            return this.date(time.getFullYear() + '-' + pad(time.getMonth() + 1) + '-' + pad(time.getDate()) + ' ' + pad(time.getHours()) + ':' + pad(time.getMinutes()));
        },
        // the release date as the tracker shows it, when that differs from the browser's clock
        trackerTime(t) {
            if (!t.updatedAt || !t.updatedAtUtc) return '';
            const own = this.date(t.updatedAt);
            return own === this.local(t.updatedAtUtc, t.updatedAt) ? '' : 'на трекере ' + own + ' ' + t.zone;
        },
        // address of the repository a version comes from: https://github.com/owner/name
        repository() {
            const m = this.status.update && String(this.status.update.url || '').match(/^https:\/\/github\.com\/[^\/]+\/[^\/]+/);
            return m ? m[0] : '';
        },
        icon(tracker) { return 'img/' + tracker + '.ico'; },
    }));
});
