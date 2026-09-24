import { AppBranchSwitcher } from '@/components/app-branch-switcher';
import { InitialsAvatar } from '@/components/shared/entity-cell';
import { HelpMenu, NotificationsMenu, SearchTrigger, Topbar, TopbarBreadcrumbs } from '@/components/shell/topbar';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { UserMenuContent } from '@/components/user-menu-content';
import { type BreadcrumbItem as BreadcrumbItemType, type SharedData } from '@/types';
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

/**
 * Tenant portal top bar: breadcrumbs, "Ask anything" (soon), branch and date filters, help, notifications,
 * account menu. Below xl the branch and date filters move to AppFilterBar.
 */
export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    const { auth } = usePage<SharedData>().props;

    return (
        <Topbar
            breadcrumbs={<TopbarBreadcrumbs items={breadcrumbs} />}
            search={<SearchTrigger placeholder="Ask anything, e.g. top sellers in Leeds" icon={Sparkles} disabled />}
            actions={
                <>
                    <div className="mr-2 hidden items-center gap-2 xl:flex">
                        <AppBranchSwitcher />
                        <DateRange />
                    </div>
                    <HelpMenu />
                    <NotificationsMenu />
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" className="ml-1 size-9 rounded-full" aria-label="Account menu">
                                <InitialsAvatar name={auth.user.name} />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-64">
                            <UserMenuContent user={auth.user} />
                        </DropdownMenuContent>
                    </DropdownMenu>
                </>
            }
        />
    );
}

/** Branch and date filters under the top bar on screens narrower than xl (the top bar has no room). */
export function AppFilterBar() {
    return (
        <div className="bg-background flex items-center gap-2 border-b px-3 py-2 sm:px-4 lg:px-6 xl:hidden">
            <AppBranchSwitcher />
            <DateRange />
        </div>
    );
}
