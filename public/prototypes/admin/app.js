/*
 * Project Security Manager — Admin prototype.
 * Dựng sidebar/topbar dùng chung và các tương tác nhỏ (tabs, dialog, toast,
 * command palette, chuyển trạng thái hiển thị). Không gọi backend.
 */

const icon = (path, strokeWidth = 1.8) =>
    `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="${strokeWidth}" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${path}</svg>`;

const icons = {
    shield: '<path d="M12 3 4.5 6v5.5c0 4.6 3.2 8.4 7.5 9.5 4.3-1.1 7.5-4.9 7.5-9.5V6L12 3Z"/><path d="m9 12 2 2 4-4"/>',
    home: '<path d="M4 10.5 12 4l8 6.5V20H4v-9.5Z"/><path d="M9.5 20v-5.5h5V20"/>',
    inbox: '<path d="M4 13.5 6.5 5h11l2.5 8.5V19H4v-5.5Z"/><path d="M4 13.5h4.5l1.5 2h4l1.5-2H20"/>',
    runtime: '<rect x="3.5" y="4" width="17" height="12" rx="2"/><path d="m7.5 8.5 2.5 2-2.5 2M12 12.5h4M8 20h8"/>',
    news: '<path d="M5 4h11v16H6.5A1.5 1.5 0 0 1 5 18.5V4Z"/><path d="M16 8h3v10.5a1.5 1.5 0 0 1-3 0M8 8h5M8 12h5M8 16h3"/>',
    project: '<path d="M3.5 7.5A2.5 2.5 0 0 1 6 5h4l2 2.5h6A2.5 2.5 0 0 1 20.5 10v7.5A2.5 2.5 0 0 1 18 20H6a2.5 2.5 0 0 1-2.5-2.5v-10Z"/>',
    service: '<rect x="4" y="4" width="16" height="6" rx="1.5"/><rect x="4" y="14" width="16" height="6" rx="1.5"/><path d="M8 7h.01M8 17h.01"/>',
    asset: '<rect x="5" y="3.5" width="14" height="17" rx="2"/><path d="M9 8h6M9 12h6M12 16.5h.01"/>',
    team: '<circle cx="9" cy="8.5" r="3"/><path d="M3.5 19c.6-3 2.8-4.5 5.5-4.5s4.9 1.5 5.5 4.5"/><circle cx="17" cy="9.5" r="2.3"/><path d="M16 14.6c2.2.1 3.9 1.4 4.5 3.9"/>',
    health: '<path d="M3 12h4l2.5-6 4 12 2.5-6h5"/>',
    menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
    close: '<path d="M18 6 6 18M6 6l12 12"/>',
    search: '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
    bell: '<path d="M6 16V11a6 6 0 1 1 12 0v5l1.5 2h-15L6 16Z"/><path d="M10 20.5a2.2 2.2 0 0 0 4 0"/>',
    check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
    alert: '<path d="M10.3 4.2 2.6 18a2 2 0 0 0 1.7 3h15.4a2 2 0 0 0 1.7-3L13.7 4.2a2 2 0 0 0-3.4 0Z"/><path d="M12 9.5v4M12 17h.01"/>',
};

const navigation = [
    {
        label: 'Giám sát',
        items: [
            { id: 'dashboard', label: 'Tổng quan', href: 'dashboard.html', icon: 'home' },
            { id: 'findings', label: 'Findings', icon: 'inbox', meta: '14', alert: true },
            { id: 'runtimes', label: 'Runtimes', icon: 'runtime' },
            { id: 'news', label: 'Tin bảo mật', icon: 'news', meta: '2' },
        ],
    },
    {
        label: 'Danh mục',
        items: [
            { id: 'project', label: 'Projects', href: 'project.html', icon: 'project', meta: '5' },
            { id: 'services', label: 'Services', icon: 'service', meta: '8' },
            { id: 'assets', label: 'Assets', icon: 'asset', meta: '1', alert: true },
        ],
    },
    {
        label: 'Cài đặt',
        items: [
            { id: 'teams', label: 'Teams', href: 'teams.html', icon: 'team', meta: '3' },
            { id: 'health', label: 'Sức khỏe hệ thống', icon: 'health', meta: '1', alert: true },
        ],
    },
];

/** Các mục tìm kiếm trong command palette. */
const paletteEntries = [
    { group: 'Trang', label: 'Tổng quan', hint: 'Hôm nay cần làm gì', href: 'dashboard.html' },
    { group: 'Trang', label: 'Teams', hint: 'Thành viên, webhook', href: 'teams.html' },
    { group: 'Project', label: 'Shop Platform', hint: 'Commerce · 3 service', href: 'project.html' },
    { group: 'Project', label: 'Booking Core', hint: 'Booking · 3 service' },
    { group: 'Service', label: 'shop-api', hint: 'PHP 8.3 · ECS · Public', href: 'project.html#services' },
    { group: 'Service', label: 'legacy-cms', hint: 'PHP 8.1 · EOL', href: 'project.html#services' },
    { group: 'Service', label: 'realtime-gateway', hint: 'Node.js 20 · EOL' },
    { group: 'Finding', label: 'DEMO-GHSA-0002 guzzlehttp/guzzle', hint: 'Critical · shop-api' },
    { group: 'Finding', label: 'PHP 8.3.12 thiếu bản vá bảo mật', hint: 'Critical · 3 service' },
    { group: 'Team', label: 'Commerce', hint: '6 thành viên', href: 'teams.html' },
    { group: 'Team', label: 'Booking', hint: '4 thành viên', href: 'teams.html' },
];

const body = document.body;

function renderShell() {
    const current = body.dataset.page;
    const crumbs = JSON.parse(body.dataset.crumbs || '[]');
    const main = document.querySelector('main.page');

    const navHtml = navigation
        .map(
            (group) => `
            <p class="nav-label">${group.label}</p>
            <ul class="nav-list">
                ${group.items
                    .map((item) => {
                        const meta = item.meta
                            ? `<span class="nav-meta${item.alert ? ' alert' : ''}">${item.meta}</span>`
                            : '';
                        const inner = `${icon(icons[item.icon])}<span>${item.label}</span>${meta}`;

                        if (!item.href) {
                            return `<li><span class="nav-item disabled" aria-disabled="true" title="Chưa có trong prototype vòng này" data-disabled-nav>${inner}</span></li>`;
                        }

                        const active = item.id === current ? ' active" aria-current="page' : '';

                        return `<li><a class="nav-item${active}" href="${item.href}">${inner}</a></li>`;
                    })
                    .join('')}
            </ul>`,
        )
        .join('');

    const crumbHtml = crumbs
        .map((crumb, index) =>
            index === crumbs.length - 1
                ? `<span class="current">${crumb.label}</span>`
                : `<a href="${crumb.href || '#'}">${crumb.label}</a><span class="crumb-separator">/</span>`,
        )
        .join('');

    const shell = document.createElement('div');
    shell.className = 'app-shell';
    shell.innerHTML = `
        <aside class="sidebar" data-sidebar aria-label="Điều hướng">
            <header class="sidebar-header">
                <a class="brand" href="dashboard.html">
                    <span class="brand-mark">${icon(icons.shield, 2)}</span>
                    <span class="brand-copy"><strong>Security Manager</strong><span>Admin workspace</span></span>
                </a>
                <button class="sidebar-close" type="button" data-sidebar-close aria-label="Đóng menu">${icon(icons.close, 2)}</button>
            </header>
            <div class="sidebar-scroll">${navHtml}</div>
            <footer class="sidebar-footer">
                <div class="admin-profile">
                    <span class="avatar">GN</span>
                    <span class="admin-profile-copy"><strong>Giang Nguyễn</strong><span>Security owner · Admin</span></span>
                </div>
            </footer>
        </aside>
        <button class="sidebar-overlay" type="button" data-sidebar-overlay aria-label="Đóng menu"></button>
        <div class="app-main">
            <header class="topbar">
                <div class="topbar-start">
                    <button class="menu-button" type="button" data-sidebar-open aria-label="Mở menu">${icon(icons.menu, 2)}</button>
                    <nav class="breadcrumbs" aria-label="Breadcrumb">${crumbHtml}</nav>
                </div>
                <div class="topbar-actions">
                    <button class="search-trigger" type="button" data-palette-open aria-label="Tìm kiếm">
                        ${icon(icons.search, 2)}<span>Tìm service, finding, CVE…</span><span class="kbd">⌘K</span>
                    </button>
                    <button class="icon-button" type="button" aria-label="Thông báo" data-prototype-action="Mở danh sách thông báo — chưa có trong vòng này">${icon(icons.bell)}</button>
                </div>
            </header>
        </div>`;

    main.replaceWith(shell);
    shell.querySelector('.app-main').append(main);

    const toastRegion = document.createElement('div');
    toastRegion.className = 'toast-region';
    toastRegion.setAttribute('aria-live', 'polite');
    toastRegion.dataset.toastRegion = '';
    body.append(toastRegion);

    renderPalette();
}

/* ---------------------------------------------------------------- Toast */

function showToast(message, tone = 'success') {
    const region = document.querySelector('[data-toast-region]');
    const toast = document.createElement('div');
    toast.className = `toast${tone === 'danger' ? ' danger' : ''}`;
    toast.innerHTML = `${icon(tone === 'danger' ? icons.alert : icons.check, 2)}<span></span>`;
    toast.querySelector('span').textContent = message;
    region.append(toast);
    setTimeout(() => toast.remove(), 3600);
}

/* -------------------------------------------------------------- Sidebar */

function bindSidebar() {
    const sidebar = document.querySelector('[data-sidebar]');
    const overlay = document.querySelector('[data-sidebar-overlay]');
    const setOpen = (open) => {
        sidebar.classList.toggle('open', open);
        overlay.classList.toggle('open', open);
        body.classList.toggle('drawer-open', open);
    };

    document.querySelector('[data-sidebar-open]').addEventListener('click', () => setOpen(true));
    document.querySelector('[data-sidebar-close]').addEventListener('click', () => setOpen(false));
    overlay.addEventListener('click', () => setOpen(false));

    document.querySelectorAll('[data-disabled-nav]').forEach((item) => {
        item.addEventListener('click', () =>
            showToast(`“${item.textContent.trim().replace(/\d+$/, '')}” thuộc vòng prototype sau.`),
        );
    });
}

/* ----------------------------------------------------------------- Tabs */

function activateTab(group, targetId, updateHash = true) {
    group.querySelectorAll('[role="tab"]').forEach((tab) => {
        const selected = tab.dataset.tab === targetId;
        tab.setAttribute('aria-selected', String(selected));
        tab.tabIndex = selected ? 0 : -1;
    });

    document.querySelectorAll(`[data-tab-panel-group="${group.dataset.tabs}"]`).forEach((panel) => {
        panel.hidden = panel.id !== targetId;
    });

    if (updateHash && group.dataset.tabsHash !== undefined) {
        history.replaceState(null, '', `#${targetId}`);
    }
}

function bindTabs() {
    document.querySelectorAll('[data-tabs]').forEach((group) => {
        group.querySelectorAll('[role="tab"]').forEach((tab) => {
            tab.addEventListener('click', () => activateTab(group, tab.dataset.tab));
        });

        const fromHash = location.hash.slice(1);
        const initial =
            group.dataset.tabsHash !== undefined && group.querySelector(`[data-tab="${fromHash}"]`)
                ? fromHash
                : group.querySelector('[aria-selected="true"]')?.dataset.tab;

        if (initial) {
            activateTab(group, initial, false);
        }
    });

    document.querySelectorAll('[data-goto-tab]').forEach((link) => {
        link.addEventListener('click', (event) => {
            const [groupName, tabId] = link.dataset.gotoTab.split(':');
            const group = document.querySelector(`[data-tabs="${groupName}"]`);

            if (group) {
                event.preventDefault();
                activateTab(group, tabId);
                group.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });
}

/* --------------------------------------------------------------- Dialog */

let activeDialog = null;
let dialogTrigger = null;

function openDialog(id, trigger) {
    const backdrop = document.getElementById(id);

    if (!backdrop) {
        return;
    }

    activeDialog = backdrop;
    dialogTrigger = trigger;
    backdrop.classList.add('open');
    body.classList.add('dialog-open');
    backdrop.querySelector('input:not([type=hidden]), select, textarea, button')?.focus();
}

function closeDialog() {
    if (!activeDialog) {
        return;
    }

    activeDialog.classList.remove('open');
    body.classList.remove('dialog-open');
    activeDialog.querySelectorAll('[aria-invalid]').forEach((field) => field.removeAttribute('aria-invalid'));
    activeDialog.querySelectorAll('.field-error').forEach((error) => error.classList.add('hidden'));
    activeDialog = null;
    dialogTrigger?.focus();
}

function bindDialogs() {
    document.querySelectorAll('[data-open-dialog]').forEach((button) => {
        button.addEventListener('click', () => openDialog(button.dataset.openDialog, button));
    });

    document.querySelectorAll('[data-close-dialog]').forEach((button) => {
        button.addEventListener('click', closeDialog);
    });

    document.querySelectorAll('.dialog-backdrop').forEach((backdrop) => {
        backdrop.addEventListener('click', (event) => {
            if (event.target === backdrop) {
                closeDialog();
            }
        });
    });
}

/**
 * Form prototype: kiểm tra các field `required` và `data-pattern`,
 * hiện lỗi ngay dưới field, rồi đóng dialog + toast nếu hợp lệ.
 */
function bindStaticForms() {
    document.querySelectorAll('[data-static-submit]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            let firstInvalid = null;

            form.querySelectorAll('[data-validate]').forEach((field) => {
                const value = field.value.trim();
                const pattern = field.dataset.pattern ? new RegExp(field.dataset.pattern) : null;
                const invalid = (field.required && value === '') || (pattern && value !== '' && !pattern.test(value));
                const error = form.querySelector(`[data-error-for="${field.id}"]`);

                field.setAttribute('aria-invalid', String(invalid));
                error?.classList.toggle('hidden', !invalid);
                firstInvalid ??= invalid ? field : null;
            });

            if (firstInvalid) {
                firstInvalid.focus();

                return;
            }

            if (form.closest('.dialog-backdrop')) {
                closeDialog();
            }

            form.querySelectorAll('[data-reset-on-submit]').forEach((field) => (field.value = ''));
            showToast(form.dataset.staticSubmit);
            form.dispatchEvent(new CustomEvent('prototype:saved'));
        });
    });
}

/* ------------------------------------------------------- Dirty tracking */

function bindDirtyForms() {
    document.querySelectorAll('[data-dirty-form]').forEach((form) => {
        const state = document.querySelector(`[data-save-state="${form.id}"]`);
        const saveButton = document.querySelector(`[data-save-button="${form.id}"]`);
        const setDirty = (dirty) => {
            state?.classList.toggle('dirty', dirty);

            if (state) {
                state.textContent = dirty ? 'Có thay đổi chưa lưu' : 'Đã lưu';
            }

            saveButton?.toggleAttribute('disabled', !dirty);
        };

        setDirty(false);
        form.addEventListener('input', () => setDirty(true));
        form.addEventListener('change', () => setDirty(true));
        form.addEventListener('prototype:saved', () => setDirty(false));
        form.addEventListener('reset', () => setTimeout(() => setDirty(false)));
    });
}

/* ------------------------------------------------------- View switcher */

/** Chuyển giữa các trạng thái: có dữ liệu / rỗng / đang tải / lỗi / dữ liệu cũ. */
function bindViewSwitchers() {
    document.querySelectorAll('[data-view-switcher]').forEach((switcher) => {
        const scope = document.querySelector(switcher.dataset.viewSwitcher);
        const apply = (state) => {
            scope.dataset.viewState = state;
            scope.querySelectorAll('[data-view]').forEach((node) => {
                node.classList.toggle('hidden', !node.dataset.view.split(' ').includes(state));
            });
            switcher.querySelectorAll('button').forEach((button) => {
                button.setAttribute('aria-pressed', String(button.dataset.state === state));
            });
        };

        switcher.querySelectorAll('button').forEach((button) => {
            button.addEventListener('click', () => apply(button.dataset.state));
        });

        apply(scope.dataset.viewState || 'ready');
    });
}

/* ------------------------------------------------------ Misc actions */

function bindPrototypeActions() {
    document.addEventListener('click', (event) => {
        const action = event.target.closest('[data-prototype-action]');

        if (action) {
            event.preventDefault();
            action.closest('.segmented[role="group"]')?.querySelectorAll('button').forEach((button) => {
                button.setAttribute('aria-pressed', String(button === action));
            });
            showToast(action.dataset.prototypeAction, action.dataset.tone);
        }
    });

    document.querySelectorAll('[data-loading-action]').forEach((button) => {
        button.addEventListener('click', () => {
            const [message, tone] = button.dataset.loadingAction.split('|');
            button.classList.add('is-loading');
            setTimeout(() => {
                button.classList.remove('is-loading');
                showToast(message, tone);
            }, 1100);
        });
    });

    document.querySelectorAll('[data-copy]').forEach((button) => {
        button.addEventListener('click', () => {
            navigator.clipboard?.writeText(button.dataset.copy).catch(() => {});
            showToast('Đã sao chép vào clipboard.');
        });
    });
}

/** j/k di chuyển trong danh sách việc cần làm, Enter để mở. */
function bindListKeyboard() {
    const list = document.querySelector('[data-keyboard-list]');

    if (!list) {
        return;
    }

    let index = -1;
    const rows = () => [...list.querySelectorAll('[data-keyboard-row]')].filter((row) => row.offsetParent);

    document.addEventListener('keydown', (event) => {
        if (activeDialog || event.target.closest('input, textarea, select, [contenteditable]')) {
            return;
        }

        const items = rows();

        if (!items.length || !['j', 'k', 'Enter'].includes(event.key)) {
            return;
        }

        if (event.key === 'Enter' && index >= 0) {
            items[index].querySelector('.task-title')?.click();

            return;
        }

        if (event.key === 'Enter') {
            return;
        }

        index = event.key === 'j' ? Math.min(index + 1, items.length - 1) : Math.max(index - 1, 0);
        items.forEach((row, i) => row.classList.toggle('is-focused', i === index));
        items[index].scrollIntoView({ block: 'nearest' });
    });
}

/* -------------------------------------------------------------- Palette */

function renderPalette() {
    const backdrop = document.createElement('div');
    backdrop.className = 'dialog-backdrop';
    backdrop.id = 'command-palette';
    backdrop.innerHTML = `
        <div class="palette" role="dialog" aria-modal="true" aria-label="Tìm kiếm nhanh">
            <label class="palette-input">${icon(icons.search, 2)}<span class="sr-only">Tìm kiếm</span>
                <input type="search" placeholder="Tìm service, finding, CVE, team…" data-palette-input autocomplete="off" />
                <span class="kbd">Esc</span>
            </label>
            <ul class="palette-results" role="listbox" data-palette-results></ul>
        </div>`;
    body.append(backdrop);

    const input = backdrop.querySelector('[data-palette-input]');
    const results = backdrop.querySelector('[data-palette-results]');
    let selected = 0;
    let visible = [];

    const draw = () => {
        const query = input.value.trim().toLowerCase();
        visible = paletteEntries.filter((entry) =>
            `${entry.label} ${entry.hint} ${entry.group}`.toLowerCase().includes(query),
        );
        selected = Math.min(selected, Math.max(visible.length - 1, 0));

        if (!visible.length) {
            results.innerHTML = '<li class="empty-state"><strong>Không tìm thấy kết quả</strong>Thử tên service, mã DEMO-CVE hoặc tên team.</li>';

            return;
        }

        let lastGroup = '';
        results.innerHTML = visible
            .map((entry, i) => {
                const heading = entry.group !== lastGroup ? `<li class="palette-group">${entry.group}</li>` : '';
                lastGroup = entry.group;

                return `${heading}<li class="palette-item" role="option" data-index="${i}" aria-selected="${i === selected}"><span>${entry.label}</span><span>${entry.hint}</span></li>`;
            })
            .join('');
    };

    const choose = (entry) => {
        closeDialog();

        if (entry.href) {
            location.href = entry.href;
        } else {
            showToast(`“${entry.label}” thuộc màn hình của vòng prototype sau.`);
        }
    };

    input.addEventListener('input', () => {
        selected = 0;
        draw();
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            selected = (selected + (event.key === 'ArrowDown' ? 1 : -1) + visible.length) % visible.length;
            draw();
        }

        if (event.key === 'Enter' && visible[selected]) {
            choose(visible[selected]);
        }
    });

    results.addEventListener('click', (event) => {
        const item = event.target.closest('.palette-item');

        if (item) {
            choose(visible[Number(item.dataset.index)]);
        }
    });

    backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) {
            closeDialog();
        }
    });

    const open = (trigger) => {
        input.value = '';
        selected = 0;
        draw();
        openDialog('command-palette', trigger);
        input.focus();
    };

    document.querySelector('[data-palette-open]').addEventListener('click', (event) => open(event.currentTarget));
    document.addEventListener('keydown', (event) => {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            open(document.activeElement);
        }
    });
}

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        closeDialog();
    }
});

renderShell();
bindSidebar();
bindTabs();
bindDialogs();
bindStaticForms();
bindDirtyForms();
bindViewSwitchers();
bindPrototypeActions();
bindListKeyboard();
