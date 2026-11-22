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
        toasts: [],

        // data from the server
        topics: [], users: [], trackers: [], settings: {}, warnings: [], news: [], categories: [], clients: [], services: [],
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
            for (const key of ['topics', 'users', 'trackers', 'warnings', 'news', 'categories', 'clients', 'services', 'status']) {
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
                .sort((a, b) => (b.updatedAt || b.addedAt).localeCompare(a.updatedAt || a.addedAt));
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
            };
        },
        editTopic(topic) {
            this.topicForm = {
                id: topic.id, kind: topic.kind === 'series' ? 'series' : 'topic', url: topic.url, tracker: topic.tracker, name: topic.name,
                quality: topic.quality, autoRename: topic.autoRename, path: topic.path, category: topic.category, script: topic.script,
                stopAfterDays: topic.stopAfterDays === null ? '' : topic.stopAfterDays, paused: topic.paused, reset: false,
                more: !!(topic.path || topic.category || topic.script || topic.stopAfterDays !== null),
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
            return { name: t.name, login: t.login, password: '', passkey: t.passkey, address: t.address, cookie: '', clearCookie: false,
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

        // ---- settings
        editableSettings(s) {
            const form = Object.assign({}, s, { torrentPassword: '', clearTorrentPassword: false });
            for (const key of ['auth', 'rss', 'debug', 'autoUpdate', 'proxy', 'useTorrent', 'deleteDistribution', 'deleteOldFiles',
                'deleteFilesOnRemove', 'removeOnSeriesDelete', 'send', 'sendUpdate', 'sendWarning']) {
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
                this.runLog = data.log;
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
            if (data) this.runLog = data.log;
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
        icon(tracker) { return 'img/' + tracker + '.ico'; },
    }));
});
