import { AppBranchSwitcher } from '@/components/app-branch-switcher';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';
import { Sparkles } from 'lucide-react';

export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    return (
        <header className="border-sidebar-border/50 flex min-h-16 shrink-0 flex-wrap items-center gap-2 border-b px-4 py-3 md:flex-nowrap md:py-0">
            <div className="flex items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <div className="hidden xl:block">
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>
            </div>

            <TooltipProvider delayDuration={0}>
                <Tooltip>
                    <TooltipTrigger asChild>
                        <div className="relative order-last w-full md:order-none md:ml-4 md:w-auto md:max-w-md md:flex-1">
                            <Sparkles className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                            <Input
                                disabled
                                aria-label="Ask anything"
                                placeholder="Ask anything, e.g. top sellers in Leeds"
                                className="h-9 pl-9 disabled:cursor-default"
                            />
                        </div>
                    </TooltipTrigger>
                    <TooltipContent>Coming soon</TooltipContent>
                </Tooltip>
            </TooltipProvider>

            <div className="ml-auto flex items-center gap-2">
                <AppBranchSwitcher />

                {/* Not wired up yet: pages will read the range once reports exist. */}
                <Select defaultValue="today">
                    <SelectTrigger className="h-9 w-[124px]" aria-label="Date range">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="today">Today</SelectItem>
                        <SelectItem value="week">This week</SelectItem>
                        <SelectItem value="month">This month</SelectItem>
                    </SelectContent>
                </Select>
            </div>
        </header>
    );
}
