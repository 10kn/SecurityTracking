/*
 * Project Security Manager — Admin prototype v2.
 * Dựng sidebar/topbar dùng chung, bảng chọn tone màu và các tương tác nhỏ.
 * Không gọi backend.
 */

const icon = (path) =>
    `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${path}</svg>`;

const icons = {
    shield: '<path d="M12 3 4.5 6v5.5c0 4.6 3.2 8.4 7.5 9.5 4.3-1.1 7.5-4.9 7.5-9.5V6L12 3Z"/>',
    home: '<path d="M4 10.5 12 4l8 6.5V20H4v-9.5Z"/>',
    inbox: '<path d="M4 13.5 6.5 5h11l2.5 8.5V19H4v-5.5Z"/><path d="M4 13.5h4.5l1.5 2h4l1.5-2H20"/>',
    runtime: '<rect x="3.5" y="4" width="17" height="12" rx="2"/><path d="M8 20h8"/>',
    news: '<path d="M5 4h14v16H5z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
    project: '<path d="M3.5 7.5A2.5 2.5 0 0 1 6 5h4l2 2.5h6a2.5 2.5 0 0 1 2.5 2.5v7.5A2.5 2.5 0 0 1 18 20H6a2.5 2.5 0 0 1-2.5-2.5v-10Z"/>',
    service: '<rect x="4" y="4" width="16" height="6" rx="1.5"/><rect x="4" y="14" width="16" height="6" rx="1.5"/>',
    asset: '<rect x="5" y="3.5" width="14" height="17" rx="2"/><path d="M12 16.5h.01"/>',
    team: '<circle cx="9" cy="8.5" r="3"/><path d="M3.5 19c.6-3 2.8-4.5 5.5-4.5s4.9 1.5 5.5 4.5"/><path d="M16 5.5a3 3 0 0 1 0 6M18 14.6c1.4.5 2.3 1.9 2.5 4.4"/>',
    health: '<path d="M3 12h4l2.5-6 4 12 2.5-6h5"/>',
    search: '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
    menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
    check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
    alert: '<circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 16h.01"/>',
};

const navigation = [
    {
        items: [
            { id: 'dashboard', label: 'Tổng quan', href: 'dashboard.html', icon: 'home' },
            { id: 'findings', label: 'Findings', icon: 'inbox', count: '14', alert: true },
            { id: 'runtimes', label: 'Runtimes', icon: 'runtime' },
            { id: 'news', label: 'Tin bảo mật', icon: 'news' },
        ],
    },
    {
        label: 'Danh mục',
        items: [
            { id: 'projects', label: 'Projects', href: 'project.html', icon: 'project' },
            { id: 'services', label: 'Services', icon: 'service' },
            { id: 'assets', label: 'Assets', icon: 'asset' },
        ],
    },
    {
        label: 'Cài đặt',
        items: [
            { id: 'teams', label: 'Teams', href: 'teams.html', icon: 'team' },
            { id: 'health', label: 'Sức khỏe hệ thống', icon: 'health' },
        ],
    },
];

/** 4 theme người dùng chọn được; tương ứng enum Theme khi dựng bằng Filament. */
const themes = [
    { id: 'graphite', label: 'Graphite', hint: 'Trắng – đen, ít màu nhất', color: '#18181b' },
    { id: 'ocean', label: 'Ocean', hint: 'Nhấn xanh dương', color: '#2563eb' },
    { id: 'forest', label: 'Forest', hint: 'Nhấn teal, nền ấm', color: '#0f766e' },
    { id: 'night', label: 'Night', hint: 'Giao diện tối', color: '#4f46e5' },
];

const paletteEntries = [
    { label: 'Tổng quan', hint: 'Trang', href: 'dashboard.html' },
    { label: 'Teams', hint: 'Trang', href: 'teams.html' },
    { label: 'Commerce', hint: 'Team', href: 'team.html' },
    { label: 'Shop Platform', hint: 'Project · Commerce', href: 'project.html' },
    { label: 'shop-api', hint: 'Service · PHP 8.3', href: 'project.html' },
    { label: 'legacy-cms', hint: 'Service · PHP 8.1', href: 'project.html' },
    { label: 'DEMO-GHSA-0002', hint: 'Finding · Critical' },
    { label: 'realtime-gateway', hint: 'Service · Node.js 20' },
];

const body = document.body;
const root = document.documentElement;

/* ----------------------------------------------------------------- Theme */

function storedTheme() {
    try {
        return localStorage.getItem('psm-theme');
    } catch {
        return null;
    }
}

function setTheme(id, persist = true) {
    root.dataset.theme = id;

    try {
        if (persist) {
            localStorage.setItem('psm-theme', id);
        }
    } catch {
        /* Không lưu được thì chỉ đổi trong phiên hiện tại. */
    }

    document.querySelectorAll('[data-theme-option]').forEach((button) => {
        button.setAttribute('aria-checked', String(button.dataset.themeOption === id));
    });
}

/* ----------------------------------------------------------------- Shell */

function renderShell() {
    const current = body.dataset.page;
    const page = document.querySelector('main.page');

    const nav = navigation
        .map((group) => {
            const label = group.label ? `<p class="nav-group">${group.label}</p>` : '';
            const items = group.items
                .map((item) => {
                    const count = item.count
                        ? `<span class="nav-count${item.alert ? ' alert' : ''}">${item.count}</span>`
                        : '';
                    const inner = `${icon(icons[item.icon])}<span>${item.label}</span>${count}`;

                    if (!item.href) {
                        return `<span class="nav-item disabled" title="Chưa có trong prototype" data-soon="${item.label}">${inner}</span>`;
                    }

                    const active = item.id === current ? ' active" aria-current="page' : '';

                    return `<a class="nav-item${active}" href="${item.href}">${inner}</a>`;
                })
                .join('');

            return label + items;
        })
        .join('');

    const shell = document.createElement('div');
    shell.innerHTML = `
        <aside class="sidebar" data-sidebar>
            <a class="brand" href="dashboard.html"><span class="brand-mark">${icon(icons.shield)}</span>Security Manager</a>
            <nav class="nav" aria-label="Điều hướng">${nav}</nav>
            <div class="sidebar-user"><span class="avatar">GN</span><span><span>Giang Nguyễn</span><small>Admin</small></span></div>
        </aside>
        <button class="scrim" type="button" aria-label="Đóng menu" data-scrim></button>
        <div class="main">
            <header class="topbar">
                <button class="btn ghost icon menu-toggle" type="button" aria-label="Mở menu" data-menu>${icon(icons.menu)}</button>
                <button class="search" type="button" data-palette-open>${icon(icons.search)}<span class="search-text">Tìm kiếm</span><span class="kbd">⌘K</span></button>
                ${themeMenuMarkup()}
                ${I18N.switchMarkup()}
            </header>
        </div>`;

    page.replaceWith(...shell.children);
    document.querySelector('.main').append(page);

    const toasts = document.createElement('div');
    toasts.className = 'toasts';
    toasts.setAttribute('aria-live', 'polite');
    body.append(toasts);

    renderPrototypePanel();

    renderPalette();
}

/** Menu chọn giao diện trên topbar: 4 theme, lưu theo người dùng. */
function themeMenuMarkup() {
    const options = themes
        .map(
            (theme) => `<button class="menu-item" type="button" role="menuitemradio" aria-checked="false" data-theme-option="${theme.id}">
                <i class="theme-dot" style="background:${theme.color}"></i>
                <span><span>${theme.label}</span><small>${theme.hint}</small></span>
                ${icon(icons.check)}
            </button>`,
        )
        .join('');

    return `<div class="theme-menu" data-theme-menu>
        <button class="btn secondary theme-trigger" type="button" aria-haspopup="menu" aria-expanded="false" aria-label="Giao diện" data-theme-trigger>
            <i class="theme-dot current"></i><span class="search-text">Giao diện</span>
        </button>
        <div class="menu" role="menu" aria-label="Giao diện" hidden>${options}</div>
    </div>`;
}

function bindThemeMenu() {
    const wrapper = document.querySelector('[data-theme-menu]');
    const trigger = wrapper.querySelector('[data-theme-trigger]');
    const menu = wrapper.querySelector('.menu');
    const setOpen = (open) => {
        menu.hidden = !open;
        trigger.setAttribute('aria-expanded', String(open));

        if (open) {
            menu.querySelector('[aria-checked="true"]')?.focus();
        }
    };

    trigger.addEventListener('click', () => setOpen(menu.hidden));
    menu.querySelectorAll('[data-theme-option]').forEach((button) => {
        button.addEventListener('click', () => {
            setTheme(button.dataset.themeOption);
            setOpen(false);
            trigger.focus();
        });
    });

    document.addEventListener('click', (event) => {
        if (!wrapper.contains(event.target)) {
            setOpen(false);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !menu.hidden) {
            setOpen(false);
            trigger.focus();
        }
    });

    const params = new URLSearchParams(location.search);
    setTheme(root.dataset.theme || 'graphite', params.has('theme') && !params.has('embed'));
}

/** Bảng nổi góc phải (chỉ có trong prototype): chọn trạng thái hiển thị của trang. */
function renderPrototypePanel() {
    const states = body.dataset.states ? JSON.parse(body.dataset.states) : null;

    if (!states) {
        return;
    }

    const panel = document.createElement('details');
    panel.className = 'proto';
    panel.open = false;
    panel.classList.toggle('hidden', new URLSearchParams(location.search).has('embed'));

    panel.innerHTML = `
        <summary>Prototype <span class="faint">${states.label}</span></summary>
        <div class="proto-body">
            <select data-state-select aria-label="${states.label}">${Object.entries(states.options)
                .map(([value, label]) => `<option value="${value}">${label}</option>`)
                .join('')}</select>
        </div>`;
    body.append(panel);

    const select = panel.querySelector('[data-state-select]');
    const scope = document.querySelector(states.target);
    const apply = () => {
        scope.querySelectorAll('[data-view]').forEach((node) => {
            node.classList.toggle('hidden', !node.dataset.view.split(' ').includes(select.value));
        });
    };
    select.value = new URLSearchParams(location.search).get('state') || select.value;
    select.addEventListener('change', apply);
    apply();
}

/* ----------------------------------------------------------------- Toast */

function toast(message, tone = 'success') {
    const node = document.createElement('div');
    node.className = `toast${tone === 'danger' ? ' danger' : ''}`;
    node.innerHTML = `${icon(tone === 'danger' ? icons.alert : icons.check)}<span></span>`;
    node.querySelector('span').textContent = t(message);
    document.querySelector('.toasts').append(node);
    setTimeout(() => node.remove(), 3200);
}

/* ---------------------------------------------------------------- Modal */

let openModalNode = null;
let modalTrigger = null;

function openModal(id, trigger) {
    const modal = document.getElementById(id);

    if (!modal) {
        return;
    }

    openModalNode = modal;
    modalTrigger = trigger;
    modal.classList.add('open');
    body.classList.add('modal-open');
    modal.querySelector('input, select, textarea, button:not([data-close])')?.focus();
}

function closeModal() {
    if (!openModalNode) {
        return;
    }

    openModalNode.classList.remove('open');
    openModalNode.querySelectorAll('[aria-invalid]').forEach((field) => field.removeAttribute('aria-invalid'));
    openModalNode.querySelectorAll('.error').forEach((error) => error.classList.add('hidden'));
    openModalNode = null;
    body.classList.remove('modal-open');
    modalTrigger?.focus();
}

/* ---------------------------------------------------------------- Bind */

function bindInteractions() {
    const sidebar = document.querySelector('[data-sidebar]');
    const scrim = document.querySelector('[data-scrim]');
    const setDrawer = (open) => {
        sidebar.classList.toggle('open', open);
        scrim.classList.toggle('open', open);
        body.classList.toggle('drawer-open', open);
    };

    document.querySelector('[data-menu]').addEventListener('click', () => setDrawer(true));
    scrim.addEventListener('click', () => setDrawer(false));

    document.addEventListener('click', (event) => {
        const soon = event.target.closest('[data-soon]');

        if (soon) {
            toast(`${soon.dataset.soon} thuộc vòng prototype sau.`);
        }

        const action = event.target.closest('[data-toast]');

        if (action) {
            event.preventDefault();
            toast(action.dataset.toast, action.dataset.tone);
        }

        const opener = event.target.closest('[data-modal]');

        if (opener) {
            event.preventDefault();
            openModal(opener.dataset.modal, opener);
        }

        if (event.target.closest('[data-close]') || event.target.classList.contains('modal')) {
            closeModal();
        }

        const row = event.target.closest('tr[data-href]');

        if (row && !event.target.closest('a, button, input, select')) {
            location.href = row.dataset.href;
        }

        const view = event.target.closest('.views button');

        if (view) {
            view.parentElement.querySelectorAll('button').forEach((button) => {
                button.setAttribute('aria-pressed', String(button === view));
            });
        }
    });

    document.querySelectorAll('[data-loading]').forEach((button) => {
        button.addEventListener('click', () => {
            const [message, tone] = button.dataset.loading.split('|');
            button.classList.add('loading');
            setTimeout(() => {
                button.classList.remove('loading');
                toast(message, tone);
            }, 900);
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeModal();
        }
    });
}

function bindTabs() {
    document.querySelectorAll('[data-tabs]').forEach((group) => {
        const tabs = [...group.querySelectorAll('[role="tab"]')];
        const select = (id, updateHash) => {
            tabs.forEach((tab) => tab.setAttribute('aria-selected', String(tab.dataset.tab === id)));
            document.querySelectorAll(`[data-panel="${group.dataset.tabs}"]`).forEach((panel) => {
                panel.hidden = panel.id !== id;
            });

            if (updateHash) {
                history.replaceState(null, '', `#${id}`);
            }
        };

        tabs.forEach((tab) => tab.addEventListener('click', () => select(tab.dataset.tab, true)));

        const fromHash = location.hash.slice(1);
        select(tabs.some((tab) => tab.dataset.tab === fromHash) ? fromHash : tabs[0].dataset.tab, false);
    });
}

/** Form prototype: kiểm tra `required` và `data-pattern`, báo lỗi dưới field. */
function bindForms() {
    document.querySelectorAll('form[data-submit]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            let firstInvalid = null;

            form.querySelectorAll('[data-validate]').forEach((field) => {
                const value = field.value.trim();
                const pattern = field.dataset.pattern ? new RegExp(field.dataset.pattern) : null;
                const invalid = (field.required && value === '') || Boolean(pattern && value !== '' && !pattern.test(value));

                field.setAttribute('aria-invalid', String(invalid));
                form.querySelector(`[data-error="${field.id}"]`)?.classList.toggle('hidden', !invalid);
                firstInvalid ??= invalid ? field : null;
            });

            if (firstInvalid) {
                firstInvalid.focus();

                return;
            }

            if (form.closest('.modal')) {
                closeModal();
                form.reset();
            }

            form.querySelector('[data-saved]')?.replaceChildren(t('Đã lưu lúc vừa xong'));
            toast(form.dataset.submit);
        });
    });
}

/** j / k để đi qua các dòng của bảng chính, Enter để mở. */
function bindTableKeyboard() {
    const table = document.querySelector('[data-keyboard]');

    if (!table) {
        return;
    }

    let index = -1;

    document.addEventListener('keydown', (event) => {
        if (openModalNode || event.target.closest('input, select, textarea')) {
            return;
        }

        const rows = [...table.querySelectorAll('tbody tr')].filter((row) => row.offsetParent);

        if (!rows.length) {
            return;
        }

        if (event.key === 'j' || event.key === 'k') {
            index = event.key === 'j' ? Math.min(index + 1, rows.length - 1) : Math.max(index - 1, 0);
            rows.forEach((row, i) => row.classList.toggle('focused', i === index));
            rows[index].scrollIntoView({ block: 'nearest' });
        }

        if (event.key === 'Enter' && rows[index]) {
            rows[index].querySelector('a')?.click();
        }
    });
}

/* --------------------------------------------------------------- Palette */

function renderPalette() {
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.id = 'palette';
    modal.innerHTML = `
        <div class="palette" role="dialog" aria-modal="true" aria-label="Tìm kiếm">
            <input type="search" placeholder="Tìm service, finding, team…" autocomplete="off" />
            <ul role="listbox"></ul>
        </div>`;
    body.append(modal);

    const input = modal.querySelector('input');
    const list = modal.querySelector('ul');
    let selected = 0;
    let results = [];

    const draw = () => {
        const query = input.value.trim().toLowerCase();
        results = paletteEntries.filter((entry) =>
            `${entry.label} ${entry.hint} ${t(entry.label)} ${t(entry.hint)}`.toLowerCase().includes(query),
        );
        selected = Math.min(selected, Math.max(results.length - 1, 0));
        list.innerHTML = results.length
            ? results
                  .map(
                      (entry, i) =>
                          `<li role="option" data-index="${i}" aria-selected="${i === selected}"><span>${t(entry.label)}</span><span>${t(entry.hint)}</span></li>`,
                  )
                  .join('')
            : `<li><span class="muted">${t('Không có kết quả')}</span></li>`;
    };

    const choose = (entry) => {
        closeModal();

        if (entry.href) {
            location.href = entry.href;
        } else {
            toast(`${entry.label} thuộc màn hình của vòng sau.`);
        }
    };

    const open = (trigger) => {
        input.value = '';
        selected = 0;
        draw();
        openModal('palette', trigger);
        input.focus();
    };

    input.addEventListener('input', () => {
        selected = 0;
        draw();
    });

    input.addEventListener('keydown', (event) => {
        if (['ArrowDown', 'ArrowUp'].includes(event.key) && results.length) {
            event.preventDefault();
            selected = (selected + (event.key === 'ArrowDown' ? 1 : -1) + results.length) % results.length;
            draw();
        }

        if (event.key === 'Enter' && results[selected]) {
            choose(results[selected]);
        }
    });

    list.addEventListener('click', (event) => {
        const item = event.target.closest('[data-index]');

        if (item) {
            choose(results[Number(item.dataset.index)]);
        }
    });

    document.querySelector('[data-palette-open]').addEventListener('click', (event) => open(event.currentTarget));
    document.addEventListener('keydown', (event) => {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            open(document.activeElement);
        }
    });
}

root.dataset.theme ||= new URLSearchParams(location.search).get('theme') || storedTheme() || 'graphite';
renderShell();
bindThemeMenu();
bindInteractions();
bindTabs();
bindForms();
bindTableKeyboard();
I18N.init();
I18N.bindSwitches();
