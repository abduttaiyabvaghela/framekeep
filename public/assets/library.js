const get = id => document.getElementById(id);
const csrf = document.querySelector('meta[name="csrf-token"]');
const pageQuery = new URLSearchParams(location.search);
const platforms = {
    instagram: {name: 'Instagram', heading: 'Your Instagram library', intro: 'Browse public media or import posts, reels, and active stories from your own professional account.', label: 'Username or link', placeholder: '@username or Instagram link', hint: 'Use a public username or a direct post, reel, or story link.', action: 'Import media', filters: ['photo', 'video', 'reel', 'story']},
    x: {name: 'X', heading: 'Media from X profiles', intro: 'Browse public photos and videos. Choose from the available video qualities when downloading.', label: 'Public username or link', placeholder: '@username or profile link', hint: 'Enter the username or link of a public X profile.', action: 'Import profile', filters: ['photo', 'video']},
    pinterest: {name: 'Pinterest', heading: 'Pins from public boards', intro: 'Browse images and videos from public boards available to the connected Pinterest API.', label: 'Public board ID', placeholder: 'Board ID', hint: 'Enter a board ID approved in the Pinterest connection settings.', action: 'Import board', filters: ['photo', 'video']},
    youtube: {name: 'YouTube', heading: 'Explore channel uploads', intro: 'Find a channel and browse its public uploads. Select a video to watch on YouTube.', label: 'YouTube channel', placeholder: 'Channel name, @handle, or link', hint: 'Use the @handle or channel ID if several channels have the same name.', action: 'Find uploads', filters: []},
};
const state = {user: null, platform: 'instagram', instagram: null, imports: new Map(), rows: [], selected: new Map(), qualities: new Map(), id: null, cursor: null, importsCursor: null, version: 0, registration: false, catalog: {}};

async function request(path, method = 'GET', body) {
    const response = await fetch('/api/v1/' + path, {
        method, headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf.content},
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const message = Object.values(data.errors ?? {})[0]?.[0] ?? data.message ?? 'The request could not be completed.';
        throw Object.assign(new Error(message), {status: response.status});
    }
    return data;
}

function show(id, text) {
    const node = get(id);
    node.textContent = text;
    node.hidden = !text;
}

function element(tag, text, className) {
    const node = document.createElement(tag);
    if (text) node.textContent = text;
    if (className) node.className = className;
    return node;
}

function updateSource() {
    const platform = state.platform;
    const screen = platforms[platform];
    const instagram = platform === 'instagram';
    const connected = instagram && get('instagram-mode').value === 'instagram_pro' && Boolean(state.instagram);
    const ownerReady = state.catalog.instagram_pro?.available === true;
    const available = state.catalog[platform]?.available;
    const input = get('source');
    input.placeholder = screen.placeholder;
    input.required = !connected;
    input.closest('label').hidden = connected;
    get('instagram-edge-group').hidden = !connected;
    get('instagram-mode-group').hidden = !instagram;
    get('instagram-pro-option').disabled = !state.instagram || !ownerReady;
    if (!connected) get('instagram-mode').value = 'instagram';
    get('instagram-actions').hidden = !instagram;
    get('instagram-connect').disabled = !ownerReady;
    get('instagram-connect').hidden = Boolean(state.instagram);
    get('instagram-disconnect').hidden = !state.instagram;
    get('instagram-connection-title').textContent = state.instagram ? '@' + state.instagram : 'Instagram account';
    get('instagram-connection-note').textContent = state.instagram
        ? 'Your posts, reels, and active stories are available to import.'
        : ownerReady ? 'Connect a professional account to import your own media.' : 'Professional account sign-in requires Meta app setup.';
    get('workspace').dataset.platform = platform;
    get('platform-name').textContent = screen.name;
    get('platform-heading').textContent = screen.heading;
    get('platform-description').textContent = screen.intro;
    get('source-title').textContent = 'Import from ' + screen.name;
    get('source-label').textContent = screen.label;
    get('library-title').textContent = screen.name + ' library';
    get('source-hint').textContent = connected ? 'Choose which of your Instagram media to import.' : screen.hint;
    get('fetch-media').textContent = screen.action;
    get('fetch-media').disabled = available === false;
    show('provider-status', available === false ? 'API setup required' : available ? (instagram ? 'Public sources available' : 'API ready') : '');
    get('filter').closest('label').hidden = screen.filters.length === 0;
    for (const option of get('filter').options) option.hidden = Boolean(option.value) && !screen.filters.includes(option.value);
}

async function updateInstagram() {
    const user = state.user;
    const identity = user ? (await request('instagram/connection')).username : null;
    if (user === state.user) {
        state.instagram = identity;
        if (identity && pageQuery.get('instagram') === 'connected' && state.catalog.instagram_pro?.available) {
            get('instagram-mode').value = 'instagram_pro';
        }
        updateSource();
    }
}

function updateAccount() {
    get('account').textContent = !state.user ? 'Sign in' : state.user.admin ? 'Admin' : state.user.pro ? 'Pro account' : 'Account';
    get('account-form').hidden = Boolean(state.user);
    get('account-actions').hidden = !state.user;
    get('account-title').textContent = state.user ? 'Framekeep account' : state.registration ? 'Create your account' : 'Sign in to Framekeep';
    show('account-description', state.user?.email ?? '');
    get('change-auth-mode').textContent = state.registration ? 'Sign in instead' : 'Create account';
    get('submit-account').textContent = state.registration ? 'Create account' : 'Sign in';
    get('password').autocomplete = state.registration ? 'new-password' : 'current-password';
    get('password').minLength = state.registration ? 10 : 1;
}

function renderImports() {
    const options = Array.from(state.imports.values(), row => new Option(
        (row.label || row.source) + ' · ' + row.platform, row.id, false, row.id === state.id,
    ));
    get('imports').replaceChildren(...(options.length ? options : [new Option('No imports yet', '')]));
    get('more-imports').hidden = !state.importsCursor;
}

function renderButtons() {
    const visible = state.rows.filter(row => row.qualities.length);
    get('selection-count').textContent = state.selected.size;
    get('select-visible').disabled = !state.user?.full_access || !visible.length;
    get('select-visible').checked = visible.length > 0 && visible.every(row => state.selected.has(row.id));
    get('download-selected').disabled = !state.user?.full_access || !state.selected.size;
    const row = state.imports.get(state.id);
    get('download-all').hidden = state.platform === 'youtube';
    get('select-visible').closest('.selection-row').hidden = state.platform === 'youtube';
    get('download-all').disabled = !state.user?.full_access || row?.status !== 'completed' || row.platform === 'youtube';
}

function safeLink(value) {
    try {
        const url = new URL(value);
        if (url.protocol === 'https:' && !url.username && !url.password) return url.href;
    } catch {}
    return '';
}

function mediaCard(row) {
    const card = element('article', '', 'card');
    const thumbnail = element('div', '', 'media-thumb');
    const image = safeLink(row.thumbnail);
    if (image) {
        const img = element('img');
        img.src = image;
        img.loading = 'lazy';
        img.referrerPolicy = 'no-referrer';
        img.alt = '';
        img.onerror = () => thumbnail.replaceChildren(element('span', row.type));
        thumbnail.append(img);
    } else {
        thumbnail.textContent = row.type;
    }
    const title = element('a', row.title, 'title-link');
    const link = safeLink(row.permalink);
    if (link) {
        title.href = link;
        title.target = '_blank';
        title.rel = 'noopener noreferrer';
    }
    const info = element('div', '', 'card-info');
    info.append(title, element('small', row.type));
    card.append(thumbnail, info);
    if (!row.qualities.length) {
        if (state.platform === 'youtube' && link) {
            const watch = element('a', 'Watch on YouTube', 'button subtle');
            watch.href = link;
            watch.target = '_blank';
            watch.rel = 'noopener noreferrer';
            const actions = element('div', '', 'media-actions');
            actions.append(watch);
            card.append(actions);
        }
        return card;
    }

    const controls = element('div', '', 'media-actions');
    const select = element('select', '', 'quality');
    select.setAttribute('aria-label', 'Download quality for ' + row.title);
    const selectedQuality = state.qualities.get(row.id) ?? row.qualities[0].id;
    select.replaceChildren(...row.qualities.map(quality => new Option(
        quality.label, quality.id, false, quality.id === selectedQuality,
    )));
    const check = element('input');
    check.type = 'checkbox';
    check.checked = state.selected.has(row.id);
    check.setAttribute('aria-label', 'Select ' + row.title);
    check.addEventListener('change', () => {
        if (check.checked) state.selected.set(row.id, Number(select.value));
        else state.selected.delete(row.id);
        renderButtons();
    });
    select.addEventListener('change', () => {
        state.qualities.set(row.id, Number(select.value));
        if (check.checked) state.selected.set(row.id, Number(select.value));
    });
    const download = element('button', 'Download', 'button subtle');
    download.type = 'button';
    download.addEventListener('click', () => transfer(row.id, select.value));
    if (state.user?.full_access) controls.append(check);
    controls.append(select, download);
    card.append(controls);
    return card;
}

function transfer(id, quality) {
    const frame = element('iframe');
    frame.hidden = true;
    frame.name = 'download-' + String(Math.random()).slice(2);
    frame.addEventListener('load', () => {
        const content = frame.contentDocument?.body?.textContent?.trim();
        if (content) {
            try { show('notice', JSON.parse(content).message); }
            catch { show('notice', 'The download could not start.'); }
            frame.remove();
        }
    });
    const form = element('form');
    form.method = 'post';
    form.action = '/api/v1/media/' + id + '/download';
    form.target = frame.name;
    form.hidden = true;
    for (const [name, value] of Object.entries({quality, _token: csrf.content})) {
        const field = element('input');
        field.name = name;
        field.value = value;
        form.append(field);
    }
    document.body.append(frame, form);
    form.submit();
    form.remove();
    setTimeout(() => frame.remove(), 900000);
}

function renderMedia() {
    get('gallery').replaceChildren(...state.rows.map(mediaCard));
    get('load-more').hidden = !state.cursor;
    get('empty').hidden = state.rows.length > 0;
    const row = state.imports.get(state.id);
    const active = ['queued', 'running'].includes(row?.status);
    show('library-summary', !state.user ? 'Sign in and choose a source to start.' : row
        ? [active ? 'Import in progress' : row.status === 'failed' ? 'Import failed' : 'Import finished', row.message].filter(Boolean).join('. ')
        : 'Add a source to start building your library.');
    renderButtons();
}

function cursorQuery(cursor, type) {
    const params = new URLSearchParams();
    if (cursor) params.set('cursor', cursor);
    if (type) params.set('type', type);
    return params.size ? '?' + params : '';
}

async function loadRows(append = false) {
    if (!state.id) return;
    const version = ++state.version;
    const page = await request('imports/' + state.id + '/items' + cursorQuery(append ? state.cursor : null, get('filter').value));
    if (version !== state.version) return;
    state.rows = append ? state.rows.concat(page.data) : page.data;
    state.cursor = page.next_cursor;
    renderMedia();
}

async function listImports(append = false) {
    if (!state.user) return;
    const user = state.user;
    const platform = state.platform;
    const query = new URLSearchParams({platform});
    if (append && state.importsCursor) query.set('cursor', state.importsCursor);
    const page = await request('imports?' + query);
    if (user !== state.user || platform !== state.platform) return;
    if (!append) state.imports.clear();
    for (const row of page.data) state.imports.set(row.id, row);
    state.importsCursor = page.next_cursor;
    state.id ??= page.data[0]?.id ?? null;
    renderImports();
    if (!append) await loadRows();
}

async function monitor() {
    const row = state.imports.get(state.id);
    if (!state.user || !['queued', 'running'].includes(row?.status)) return;
    const current = await request('imports/' + row.id);
    if (row.id !== state.id || !state.user) return;
    state.imports.set(row.id, current);
    renderImports();
    if (current.pages !== row.pages && state.rows.length <= 30) await loadRows();
    renderMedia();
}

async function zip(selection) {
    show('export-message', 'Preparing your archive…');
    get('export-status').hidden = false;
    get('export-link').hidden = true;
    const user = state.user;
    try {
        let task = await request('exports', 'POST', selection);
        while (user === state.user && ['queued', 'running'].includes(task.status)) {
            await new Promise(resolve => setTimeout(resolve, 2500));
            if (user !== state.user) return;
            task = await request('exports/' + task.id);
        }
        if (user !== state.user) return;
        show('export-message', task.message ?? (task.status === 'completed' ? 'Your archive is ready.' : 'The export did not complete.'));
        get('export-link').href = '/api/v1/exports/' + task.id + '/download';
        get('export-link').hidden = task.status !== 'completed';
    } catch (error) {
        if (user === state.user) show('export-message', error.message);
    }
}

const report = error => show('notice', error.message);
get('instagram-mode').addEventListener('change', updateSource);
for (const tab of document.querySelectorAll('.platform-tab')) {
    tab.addEventListener('click', () => {
        if (state.platform === tab.dataset.platform) return;
        document.querySelector('.platform-tab[aria-current]').removeAttribute('aria-current');
        tab.setAttribute('aria-current', 'page');
        state.version++;
        Object.assign(state, {platform: tab.dataset.platform, id: null, rows: [], cursor: null, importsCursor: null});
        state.imports.clear();
        get('source').value = '';
        get('filter').value = '';
        show('source-result', '');
        updateSource();
        renderImports();
        renderMedia();
        listImports().catch(report);
    });
}
get('account').addEventListener('click', () => { updateAccount(); get('account-dialog').showModal(); });
get('plans').addEventListener('click', () => get('plans-dialog').showModal());
for (const close of document.querySelectorAll('[data-close]')) close.onclick = () => close.closest('dialog').close();
get('change-auth-mode').onclick = () => { state.registration = !state.registration; updateAccount(); show('account-message', ''); };
get('account-form').onsubmit = async event => {
    event.preventDefault();
    get('submit-account').disabled = true;
    try {
        const result = await request('session/' + (state.registration ? 'register' : 'login'), 'POST',
            {email: get('email').value, password: get('password').value});
        csrf.content = result.csrf_token;
        state.user = result.user;
        get('account-form').reset();
        show('account-message', '');
        updateAccount();
        get('account-dialog').close();
        await updateInstagram();
        await listImports();
        renderMedia();
    } catch (error) { show('account-message', error.message); }
    finally { get('submit-account').disabled = false; }
};
get('logout').onclick = async () => {
    try {
        csrf.content = (await request('session/logout', 'POST')).csrf_token;
        state.user = null;
        state.instagram = null;
        state.version++;
        state.imports.clear();
        state.selected.clear();
        state.qualities.clear();
        Object.assign(state, {rows: [], id: null, cursor: null, importsCursor: null});
        get('export-status').hidden = true;
        get('account-dialog').close();
        updateAccount();
        updateSource();
        renderImports();
        renderMedia();
    } catch (error) { show('account-message', error.message); }
};
get('source-form').onsubmit = async event => {
    event.preventDefault();
    if (!state.user) { get('account').click(); return; }
    const submit = event.submitter;
    submit.disabled = true;
    try {
        const workspace = state.platform;
        const platform = workspace === 'instagram' ? get('instagram-mode').value : workspace;
        const source = platform === 'instagram_pro' ? get('instagram-edge').value : get('source').value;
        const row = await request('imports', 'POST', {platform, source});
        if (state.platform !== workspace) return;
        state.imports.set(row.id, row);
        state.id = row.id;
        state.rows = [];
        state.cursor = null;
        show('source-result', 'Import requested.');
        renderImports();
        renderMedia();
        await loadRows();
    } catch (error) { show('source-result', error.message); }
    finally { submit.disabled = state.catalog[state.platform]?.available === false; }
};
get('imports').onchange = () => {
    state.id = Number(get('imports').value) || null;
    state.rows = [];
    state.cursor = null;
    renderMedia();
    loadRows().catch(report);
};
get('filter').onchange = () => loadRows().catch(report);
get('load-more').onclick = () => loadRows(true).catch(report);
get('more-imports').onclick = () => listImports(true).catch(report);
get('select-visible').onchange = event => {
    for (const row of state.rows) {
        if (!row.qualities.length) continue;
        if (event.target.checked) state.selected.set(row.id, state.qualities.get(row.id) ?? row.qualities[0].id);
        else state.selected.delete(row.id);
    }
    renderMedia();
};
get('download-selected').onclick = () => zip({items: Array.from(state.selected, ([id, quality]) => ({id, quality}))});
get('download-all').onclick = () => zip({import_id: state.id});
get('instagram-connect').onclick = async () => {
    if (!state.user) { get('account').click(); return; }
    try { location.assign((await request('session/instagram/connect', 'POST')).url); }
    catch (error) { show('source-result', error.message); }
};
get('instagram-disconnect').onclick = async () => {
    try { await request('session/instagram', 'DELETE'); await updateInstagram(); }
    catch (error) { show('source-result', error.message); }
};
for (const button of document.querySelectorAll('[data-gateway]')) button.onclick = async () => {
    if (!state.user) {
        get('plans-dialog').close();
        get('account').click();
        return;
    }
    button.disabled = true;
    try { location.assign((await request('checkout', 'POST', {plan: 'pro-annual', gateway: button.dataset.gateway})).url); }
    catch (error) { show('checkout-status', error.message); }
    finally { button.disabled = false; }
};

request('catalog').then(data => {
    state.catalog = Object.fromEntries(data.platforms.map(platform => [platform.id, platform]));
    get('checkout-gateways').hidden = !data.checkout_enabled;
    for (const button of document.querySelectorAll('[data-gateway]')) button.hidden = !data.test_gateways?.includes(button.dataset.gateway);
    show('checkout-status', data.checkout_enabled ? 'Test mode. Use test payment details.' : 'Checkout is currently disabled.');
    const instagramStatus = pageQuery.get('instagram');
    if (instagramStatus && state.catalog.instagram_pro.available) {
        const message = {
            connected: 'Instagram is connected.',
            cancelled: 'The connection was canceled.',
            expired: 'The connection expired. Start again.',
            failed: 'Instagram could not connect your account.',
        }[instagramStatus];
        if (message) show('source-result', message);
        if (instagramStatus === 'connected' && state.instagram) get('instagram-mode').value = 'instagram_pro';
    }
    updateSource();
}).catch(report);
if (pageQuery.get('payment') === 'awaiting') show('notice', 'Test checkout returned. Pro access updates after the gateway confirms payment.');
if (pageQuery.get('payment') === 'cancelled') show('notice', 'Test checkout was canceled.');
request('me').then(async user => {
    state.user = user;
    updateAccount();
    await updateInstagram();
    await listImports();
    renderMedia();
}).catch(error => { if (error.status !== 401) report(error); });
setInterval(() => monitor().catch(report), 3500);
