import { AppBranchSwitcher } from '@/components/app-branch-switcher';
import { AccountTrigger, HelpMenu, NotificationsMenu, SearchTrigger, Topbar, TopbarBrand } from '@/components/shell/topbar';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { UserMenuContent } from '@/components/user-menu-content';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { CalendarDays, Sparkles } from 'lucide-react';

function DateRange() {
    // Not wired up yet: report pages will read the range once they exist.
    return (
        <Select defaultValue="today">
            <SelectTrigger className="h-9 w-[132px]" aria-label="Date range">
                <CalendarDays className="text-muted-foreground size-4" aria-hidden />
                <SelectValue />
            </SelectTrigger>
            <SelectContent align="end">
                <SelectItem value="today">Today</SelectItem>
                <SelectItem value="week">This week</SelectItem>
                <SelectItem value="month">This month</SelectItem>
            </SelectContent>
        </Select>
    );
}

/** Business portal top bar (dark chrome): logo, "Ask anything" (soon), help, notifications, account menu. */
export function AppSidebarHeader() {
    const { auth, companyRole } = usePage<SharedData>().props;

    return (
        <Topbar
            brand={<TopbarBrand href="/app" />}
            search={<SearchTrigger placeholder="Ask anything, e.g. top sellers in Leeds" icon={Sparkles} disabled />}
            actions={
                <>
                    <NotificationsMenu />
                    <HelpMenu />
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <AccountTrigger
                                name={auth.user.name}
                                subtitle={companyRole ? companyRole.charAt(0).toUpperCase() + companyRole.slice(1) : undefined}
                            />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-64" sideOffset={8}>
                            <UserMenuContent user={auth.user} />
                        </DropdownMenuContent>
                    </DropdownMenu>
                </>
            }
        />
    );
}

/** Branch and date filters at the top of the page, right-aligned (kept off the dark top bar). */
export function AppFilterBar() {
    return (
        <div className="flex flex-wrap items-center justify-end gap-2">
            <AppBranchSwitcher />
            <DateRange />
        </div>
    );
}
