import { Link, usePage } from '@inertiajs/react';
import { ChevronDown, Languages, Search } from 'lucide-react';
import { useEffect } from 'react';
import AppLogo from '@/components/app-logo';
import { Kbd } from '@/components/kbd';
import { BranchSwitcher } from '@/components/shell/branch-switcher';
import { LiveClock } from '@/components/shell/live-clock';
import { NotificationsBell } from '@/components/shell/notifications-bell';
import { SyncPill } from '@/components/shell/sync-pill';
import { ThemePopover } from '@/components/shell/theme-popover';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { UserMenuContent } from '@/components/user-menu-content';
import { useUiStore } from '@/command/ui-store';
import { useCan } from '@/hooks/use-can';
import { useDigits } from '@/hooks/use-digits';
import { useInitials } from '@/hooks/use-initials';
import { useT } from '@/i18n';
import { usePreferences } from '@/lib/preferences';
import { setBranchPaper } from '@/lib/sales';
import { dashboard } from '@/routes';

/** 52 px (44 compact). On phones it shrinks to logo · sync · bell · avatar (02_AppFlow §1). */
export function TopBar() {
    const { t, locale } = useT();
    const prefs = usePreferences();
    const { auth } = usePage().props;
    const user = auth?.user;
    const openOverlay = useUiStore((s) => s.open);
    const can = useCan();
    const home = can('platform.admin') ? '/platform' : dashboard();

    // Keep the window-level branch paper in step with the server (branch switch
    // changes it without a full remount of the persistent shell).
    const branchPaper = auth?.branch_paper;
    useEffect(() => {
        setBranchPaper(branchPaper ?? null);
    }, [branchPaper]);

    return (
        <header
            className="sticky top-0 z-40 flex shrink-0 items-center gap-2 border-b bg-card px-2 md:px-4"
            style={{ height: 'var(--topbar-h)' }}
        >
            <SidebarTrigger
                className="-ml-1 max-md:hidden"
                aria-label={t('nav.toggle_sidebar')}
            />
            <Link
                href={home}
                prefetch
                className="touch-target flex min-w-0 items-center justify-center gap-1 rounded-md"
                aria-label={t('nav.home')}
            >
                <AppLogo />
            </Link>
            <div className="max-md:hidden">
                <BranchSwitcher />
            </div>

            <button
                type="button"
                onClick={() => openOverlay('palette')}
                className="mx-auto hidden min-h-9 w-full max-w-md items-center gap-2 rounded-md border border-input bg-muted px-3 text-sm text-muted-foreground hover:bg-accent md:flex"
                aria-label={t('palette.open')}
            >
                <Search className="size-4" aria-hidden="true" />
                <span className="flex-1 text-left">
                    {t('palette.placeholder')}
                </span>
                <Kbd keys="mod+k" />
            </button>

            <div className="ml-auto flex items-center gap-1 md:ml-0">
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-11 md:hidden"
                    aria-label={t('palette.open')}
                    onClick={() => openOverlay('palette')}
                >
                    <Search aria-hidden="true" />
                </Button>
                <TodayTicker />
                <SyncPill />
                <NotificationsBell />
                <LiveClock />
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="min-h-9 gap-1 max-md:hidden"
                    aria-label={t('lang.switch')}
                    title={`${t('lang.switch')} (Alt+L)`}
                    onClick={() =>
                        prefs.set({ locale: locale === 'bn' ? 'en' : 'bn' })
                    }
                >
                    <Languages aria-hidden="true" />
                    {locale === 'bn' ? 'EN' : 'বাং'}
                </Button>
                <div className="max-md:hidden">
                    <ThemePopover />
                </div>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            className="h-11 gap-1.5 rounded-lg p-1 md:h-9"
                            aria-label={t('user.menu')}
                            data-test="sidebar-menu-button"
                        >
                            <UserChip />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="end"
                        className="min-w-56 rounded-lg"
                    >
                        {user ? <UserMenuContent user={user} /> : null}
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </header>
    );
}

/** Mockup user chip: initials avatar, then name over role (name and role hidden on phones). */
function UserChip() {
    const { auth } = usePage().props;
    const getInitials = useInitials();
    const user = auth.user;

    if (!user) {
        return null;
    }
    const role = auth.roles?.[0] ?? null;

    return (
        <>
            <span className="flex size-7 shrink-0 items-center justify-center rounded-full border bg-surface-sunken text-xs font-bold text-foreground">
                {getInitials(user.name)}
            </span>
            <span className="hidden max-w-32 text-left text-xs leading-tight sm:block">
                <span className="block truncate font-semibold">
                    {user.name}
                </span>
                {role ? (
                    <span className="block truncate text-[10px] font-normal text-muted-foreground">
                        {role}
                    </span>
                ) : null}
            </span>
            <ChevronDown
                className="size-3 text-muted-foreground max-sm:hidden"
                aria-hidden="true"
            />
        </>
    );
}

/** Mockup "Today ৳… · N sales" pill for the current branch (shared `todayTicker`, dashboard.view only). */
function TodayTicker() {
    const { t } = useT();
    const digits = useDigits();
    const today = usePage().props.todayTicker;

    if (!today) {
        return null;
    }

    return (
        <Link
            href={dashboard()}
            prefetch
            title={t('shell.today_title')}
            data-test="today-ticker"
            className="hidden h-7 items-center gap-1.5 rounded-full border bg-surface-sunken px-2.5 text-[11px] text-foreground hover:border-primary lg:flex"
        >
            <span
                className="size-2 rounded-full bg-success"
                aria-hidden="true"
            />
            <span className="font-bold whitespace-nowrap">
                {t('shell.today', { amount: digits.money(today.total_paisa) })}
            </span>
            <span className="font-medium whitespace-nowrap text-muted-foreground">
                {t(
                    today.sales === 1
                        ? 'shell.today_one_sale'
                        : 'shell.today_sales',
                    { n: digits.n(today.sales) },
                )}
            </span>
        </Link>
    );
}
