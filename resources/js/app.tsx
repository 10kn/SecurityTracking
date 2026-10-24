import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';

import AdminLayout from '@/layouts/admin-layout';

createInertiaApp({
    title: (title) => (title ? `${title} — Security Manager` : 'Security Manager'),
    layout: (name) => (name.startsWith('auth/') ? null : AdminLayout),
    progress: {
        color: 'var(--primary)',
        delay: 150,
    },
});
