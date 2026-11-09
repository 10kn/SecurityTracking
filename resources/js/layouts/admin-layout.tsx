import { Link, router, usePage } from '@inertiajs/react';
import { CpuIcon, FolderIcon, LayoutDashboardIcon, LogOutIcon, MenuIcon, ServerIcon, ShieldAlertIcon, ShieldIcon, SirenIcon, SlidersHorizontalIcon, UsersIcon, type LucideIcon } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { toast } from 'sonner';

import { index as findingsIndex } from '@/actions/App/Http/Controllers/Admin/FindingController';
import { index as incidentsIndex } from '@/actions/App/Http/Controllers/Admin/IncidentController';
import OverviewController from '@/actions/App/Http/Controllers/Admin/OverviewController';
import { index as projectsIndex } from '@/actions/App/Http/Controllers/Admin/ProjectController';
import { index as servicesIndex } from '@/actions/App/Http/Controllers/Admin/ServiceController';
import { destroy as logout } from '@/actions/App/Http/Controllers/Admin/SessionController';
import { edit as settingsEdit } from '@/actions/App/Http/Controllers/Admin/SystemSettingController';
import { index as teamsIndex } from '@/actions/App/Http/Controllers/Admin/TeamController';
import { index as technologiesIndex } from '@/actions/App/Http/Controllers/Admin/TechnologyController';
import { AppearanceMenu, LanguageSwitch } from '@/components/admin/appearance-menu';
import { CommandPalette } from '@/components/admin/command-palette';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Toast } from '@/types';

interface NavigationItem {
    label: string;
    href: string;
    icon: LucideIcon;
    /** Active on its own page only, not on the pages below it (the Overview is /admin). */
    isExact?: boolean;
}

function showToast(flash: { toast?: Toast } | undefined) {
    if (flash?.toast) {
        toast[flash.toast.type](flash.toast.message, { description: flash.toast.description });
    }
}

/**
 * The shell of every signed-in page: the sidebar (same color as the page, it recedes behind the content), the topbar with
 * search, the appearance menu and the language switch, and toasts from flash data.
 */
export default function AdminLayout({ children }: { children: ReactNode }) {
    const t = useTranslation();
    const page = usePage();
    const [isSidebarOpen, setIsSidebarOpen] = useState(false);

    const groups: { label?: string; items: NavigationItem[] }[] = [
        {
            items: [{ label: t('Tổng quan'), href: OverviewController.url(), icon: LayoutDashboardIcon, isExact: true }],
        },
        {
            label: t('Giám sát'),
            items: [
                { label: t('Findings'), href: findingsIndex.url(), icon: ShieldAlertIcon },
                { label: t('Sự kiện bảo mật'), href: incidentsIndex.url(), icon: SirenIcon },
            ],
        },
        {
            label: t('Danh mục'),
            items: [
                { label: t('Projects'), href: projectsIndex.url(), icon: FolderIcon },
                { label: t('Services'), href: servicesIndex.url(), icon: ServerIcon },
            ],
        },
        {
            label: t('Cài đặt'),
            items: [
                { label: t('Teams'), href: teamsIndex.url(), icon: UsersIcon },
                { label: t('Công nghệ'), href: technologiesIndex.url(), icon: CpuIcon },
                { label: t('Hệ thống'), href: settingsEdit.url(), icon: SlidersHorizontalIcon },
            ],
        },
    ];

    useEffect(() => {
        showToast(page.flash);

        // The flash of the first page; later ones arrive through the event.
        return router.on('flash', (event) => showToast(event.detail.flash));
    }, []);

    useEffect(() => router.on('navigate', () => setIsSidebarOpen(false)), []);

    const path = page.url.split('?')[0];
    const user = page.props.auth.user;

    return (
        <TooltipProvider>
            <aside
                className={cn(
                    'fixed inset-y-0 left-0 z-50 flex w-sidebar flex-col border-r border-border bg-background transition-transform duration-150 max-[860px]:-translate-x-full',
                    isSidebarOpen && 'max-[860px]:translate-x-0',
                )}
            >
                <Link href={OverviewController.url()} className="flex h-topbar items-center gap-2.5 px-5 text-base font-semibold tracking-[-0.01em]">
                    <span className="grid size-6 place-items-center rounded-md bg-primary text-primary-foreground">
                        <ShieldIcon className="size-3.5" strokeWidth={1.8} />
                    </span>
                    Security Manager
                </Link>
                <nav className="flex-1 overflow-y-auto px-3 pt-2 pb-4" aria-label={t('Điều hướng')}>
                    {groups.map((group) => (
                        <div key={group.label ?? ''} className="mt-[18px] first:mt-1">
                            {group.label && <p className="mx-2 mb-1.5 text-xs font-medium text-faint">{group.label}</p>}
                            {group.items.map((item) => {
                                const isActive = path === item.href || (!item.isExact && path.startsWith(`${item.href}/`));

                                return (
                                    <Link
                                        key={item.href}
                                        href={item.href}
                                        aria-current={isActive ? 'page' : undefined}
                                        className={cn(
                                            'flex h-9 items-center gap-2.5 rounded-md border border-transparent px-2 text-nav font-medium text-muted-foreground hover:bg-foreground/5 hover:text-foreground',
                                            isActive && 'border-nav-active-border bg-nav-active text-nav-active-text hover:bg-nav-active',
                                        )}
                                    >
                                        <item.icon className={cn('size-4 shrink-0', isActive ? 'text-current' : 'text-faint')} strokeWidth={1.8} />
                                        <span>{item.label}</span>
                                    </Link>
                                );
                            })}
                        </div>
                    ))}
                </nav>
                {user && (
                    <DropdownMenu>
                        <DropdownMenuTrigger className="flex items-center gap-2.5 border-t border-border px-5 py-3 text-left text-sm hover:bg-foreground/5">
                            <span className="grid size-7 shrink-0 place-items-center rounded-full bg-surface-2 text-xs font-semibold text-muted-foreground">{user.initials}</span>
                            <span className="grid min-w-0">
                                <span className="truncate">{user.name}</span>
                                <small className="text-xs text-faint">{t('Admin')}</small>
                            </span>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent side="top" align="start" className="w-56">
                            <DropdownMenuLabel className="truncate">{user.email}</DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem onSelect={() => router.post(logout.url())}>
                                <LogOutIcon />
                                {t('Đăng xuất')}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </aside>
            {isSidebarOpen && (
                <button type="button" className="fixed inset-0 z-45 bg-overlay min-[861px]:hidden" aria-label={t('Đóng menu')} onClick={() => setIsSidebarOpen(false)} />
            )}
            <div className="min-h-screen min-[861px]:ml-sidebar">
                <header className="sticky top-0 z-30 flex h-topbar items-center gap-3 border-b border-border bg-background/92 px-8 backdrop-blur-sm max-[860px]:px-4">
                    <Button variant="ghost" size="icon" className="min-[861px]:hidden" aria-label={t('Mở menu')} onClick={() => setIsSidebarOpen(true)}>
                        <MenuIcon />
                    </Button>
                    <CommandPalette />
                    <AppearanceMenu />
                    <LanguageSwitch />
                </header>
                <main className="mx-auto max-w-[1200px] px-8 pt-7 pb-24 max-[860px]:px-4 max-[860px]:pt-5 max-[860px]:pb-30">{children}</main>
            </div>
            <Toaster />
        </TooltipProvider>
    );
}
