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
        sort: ['new', 'old', 'az', 'za'].includes(localStorage.getItem('tm-sort')) ? localStorage.getItem('tm-sort') : 'new',   // order of the topics list
        pageSize: ['50', '100', '200', 'all'].includes(localStorage.getItem('tm-page-size')) ? localStorage.getItem('tm-page-size') : '50',   // topics on one page
        pageNo: 0,              // page of the topics list being shown, from 0
        journalKind: '',        // kind of events the journal shows ('' - all)
        compact: localStorage.getItem('tm-compact') === '1',   // topics list: one line per topic
        expanded: null,         // topic shown in full while the list is compact
        toasts: [],

        // data from the server
        topics: [], users: [], trackers: [], settings: {}, warnings: [], categories: [], sonarrTitles: [], clients: [], services: [], timezones: [], journal: [],
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
        runLogOpen: false,      // the log of the last run is unfolded
        runLogs: [],            // the detailed logs kept: [{n, time, timeUtc, size}], the newest first
        runLogN: 0,             // which of them is shown (0 - the last run)
        runLogCut: false,       // only the end of it is shown
        logTimer: null,

        // ---- startup
        async init() {
            this.applyTheme();
            // another search, filter, order or page size: the list starts from its first page again
            for (const what of ['filter.text', 'filter.tracker', 'filter.state', 'sort', 'pageSize'])
                this.$watch(what, () => { this.pageNo = 0; });
            this.$watch('sort', value => localStorage.setItem('tm-sort', value));
            this.$watch('pageSize', value => localStorage.setItem('tm-page-size', value));
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
            for (const key of ['topics', 'users', 'trackers', 'warnings', 'categories', 'sonarrTitles', 'clients', 'services', 'timezones', 'journal', 'status']) {
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
            if (id === 'status') return this.status.update ? 1 : 0;
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
        // the topics that pass the search and the filters, in the chosen order
        get foundTopics() {
            const text = this.filter.text.trim().toLowerCase();
            const when = t => t.updatedAtUtc || t.addedAtUtc || t.updatedAt || t.addedAt || '';
            const byName = (a, b) => (a.name || '').localeCompare(b.name || '', 'ru', { numeric: true, sensitivity: 'base' }) || a.id - b.id;
            const order = {
                new: (a, b) => when(b).localeCompare(when(a)) || b.id - a.id,
                old: (a, b) => when(a).localeCompare(when(b)) || a.id - b.id,
                az: byName,
                za: (a, b) => byName(b, a),
            }[this.sort];
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
                .sort(order);
        },
        // how many topics one page holds (0 - all of them) and how many pages there are
        get perPage() { return this.pageSize === 'all' ? 0 : Number(this.pageSize); },
        get pageCount() { return this.perPage ? Math.max(1, Math.ceil(this.foundTopics.length / this.perPage)) : 1; },
        // the page being shown: a list that got shorter may no longer have the page that was open
        get currentPage() { return Math.min(this.pageNo, this.pageCount - 1); },
        // only the topics of the current page are drawn
        get shownTopics() {
            if (!this.perPage) return this.foundTopics;
            return this.foundTopics.slice(this.currentPage * this.perPage, (this.currentPage + 1) * this.perPage);
        },
        // "1–50 из 1 234"
        get pageText() {
            const total = this.foundTopics.length;
            const from = this.currentPage * this.perPage + 1;
            return from + '–' + Math.min(total, from + this.perPage - 1) + ' из ' + total.toLocaleString('ru');
        },
        turnPage(step) {
            this.pageNo = Math.max(0, Math.min(this.pageCount - 1, this.currentPage + step));
            document.querySelector('section .filters')?.scrollIntoView({ block: 'nearest' });
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
            if (t.accountOptional && !t.login && !t.hasCookie) return { text: 'без входа', kind: 'muted' };
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
        async testNetwork() {
            if (await this.saveSettings()) await this.call('network.test');
        },
        async testClient() {
            if (await this.saveSettings()) await this.call('client.test');
        },
        async testNotifications() {
            if (await this.saveSettings()) await this.call('notify.check');
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
                this.runLogN = 0;
                this.showLog(data);
                if (!data.running) {
                    clearInterval(this.logTimer);
                    this.status.running = false;
                    await this.act('overview', {}, { silent: true });
                    this.toast('Проверка завершена.');
                }
            }, 1500);
        },
        async loadLog() {
            const data = await this.call('run.log', { n: this.runLogN }, { silent: true });
            if (data) this.showLog(data);
        },
        showLog(data) {
            this.runLogs = data.runs || [];
            //the log that was shown may have been rotated away
            if (!this.runLogs.some(r => r.n === this.runLogN)) this.runLogN = 0;
            this.runLogCut = !!data.cut;
            this.runLog = this.logLocal(data.log, data.offset);
        },
        kb(bytes) { return bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' МБ' : Math.max(1, Math.round(bytes / 1024)) + ' КБ'; },
        // saves the whole log that is shown as a file: the page itself shows only its end when it is long
        async saveLog() {
            const data = await this.call('run.log', { n: this.runLogN, full: true }, { silent: true });
            if (!data) return;
            const run = this.runLogs.find(r => r.n === this.runLogN);
            const link = document.createElement('a');
            link.href = URL.createObjectURL(new Blob([data.log], { type: 'text/plain;charset=utf-8' }));
            link.download = 'torrentmonitor-' + (run ? run.time.replace(/[: ]/g, '-') : 'log') + '.log';
            link.click();
            URL.revokeObjectURL(link.href);
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
        // ---- journal of events
        journalKinds: [
            { id: 'run', title: 'Проверки' }, { id: 'error', title: 'Ошибки' }, { id: 'update', title: 'Обновления' }, { id: 'settings', title: 'Настройки' },
            { id: 'tracker', title: 'Трекеры' }, { id: 'topic', title: 'Темы' }, { id: 'access', title: 'Вход' },
            { id: 'service', title: 'Подключения' }, { id: 'data', title: 'Данные' },
        ],
        journalTitle(kind) { return (this.journalKinds.find(k => k.id === kind) || { title: kind }).title; },
        get shownJournal() { return this.journal.filter(e => !this.journalKind || e.kind === this.journalKind).slice(0, 100); },
        // what TorrentMonitor runs with, to the clipboard: for a bug report
        async copyAbout() {
            const data = await this.call('about', {}, { silent: true });
            if (!data) return;
            try {
                await navigator.clipboard.writeText(data.text);
                this.toast('Сведения скопированы.');
            } catch (e) {
                // no access to the clipboard (a page opened without https): the text is shown to be copied by hand
                window.prompt('Скопируйте сведения:', data.text);
            }
        },
        async checkHealth() {
            const data = await this.call('health', {}, { silent: true });
            if (data) this.health = data.groups;
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
