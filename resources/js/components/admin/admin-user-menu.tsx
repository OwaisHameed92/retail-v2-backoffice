import { type AdminSession } from '@/components/admin/types';
import { InitialsAvatar } from '@/components/shared/entity-cell';
import { ThemeSubmenu } from '@/components/shell/theme-menu';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem, useSidebar } from '@/components/ui/sidebar';
import { useIsMobile } from '@/hooks/use-mobile';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { Link } from '@inertiajs/react';
import { ChevronsUpDown, LogOut } from 'lucide-react';

/** Menu items shared by the top-bar avatar and the sidebar user card. */
function AdminMenuItems({ admin }: { admin: AdminSession }) {
    const cleanup = useMobileNavigation();

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2.5 px-2 py-2">
                    <InitialsAvatar name={admin.name} />
                    <div className="grid min-w-0 text-sm leading-tight">
                        <span className="text-foreground truncate font-medium">{admin.name}</span>
                        <span className="text-muted-foreground truncate text-xs">{admin.email}</span>
                    </div>
                    <span className="bg-primary-soft text-accent-foreground ml-auto shrink-0 rounded px-1.5 py-0.5 text-[11px] font-medium">
                        {admin.roleLabel}
                    </span>
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <ThemeSubmenu />
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link className="w-full" method="post" href={route('admin.logout')} as="button" onClick={cleanup}>
                    <LogOut className="text-muted-foreground size-4" />
                    Log out
                </Link>
            </DropdownMenuItem>
        </>
    );
}

/** Top-bar avatar button with the admin's account menu. */
export function AdminUserMenu({ admin }: { admin: AdminSession }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="ml-1 size-9 rounded-full" aria-label="Account menu">
                    <InitialsAvatar name={admin.name} />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent className="w-72" align="end">
                <AdminMenuItems admin={admin} />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** Sidebar footer card: avatar, name and role; opens the same account menu. */
export function AdminSidebarUser({ admin }: { admin: AdminSession }) {
    const { state } = useSidebar();
    const isMobile = useIsMobile();

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton size="lg" className="data-[state=open]:bg-sidebar-accent h-12 gap-2.5 px-2">
                            <InitialsAvatar name={admin.name} />
                            <span className="grid min-w-0 flex-1 text-left text-sm leading-tight">
                                <span className="text-sidebar-accent-foreground truncate font-medium">{admin.name}</span>
                                <span className="text-sidebar-muted truncate text-xs font-normal">{admin.roleLabel}</span>
                            </span>
                            <ChevronsUpDown className="ml-auto size-4" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-64"
                        align="end"
                        side={isMobile ? 'bottom' : state === 'collapsed' ? 'right' : 'top'}
                        sideOffset={6}
                    >
                        <AdminMenuItems admin={admin} />
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
