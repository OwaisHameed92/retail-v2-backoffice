import AppLogo from '@/components/app-logo';
import { adminNavItems } from '@/components/admin/admin-nav';
import { type AdminSharedData } from '@/components/admin/types';
import {
    Sidebar,
    SidebarContent,
    SidebarGroup,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarRail,
} from '@/components/ui/sidebar';
import { Link, usePage } from '@inertiajs/react';

export function AdminSidebar() {
    const { admin } = usePage<AdminSharedData>().props;
    const abilities = admin?.abilities ?? [];
    const items = adminNavItems.filter((item) => !item.ability || abilities.includes(item.ability));

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={route('admin.dashboard')} prefetch>
                                <AppLogo subtitle="Admin" />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <SidebarGroup className="px-2 py-0">
                    <SidebarMenu>
                        {items.map((item) =>
                            item.route ? (
                                <SidebarMenuItem key={item.title}>
                                    <SidebarMenuButton asChild tooltip={item.title} isActive={route().current(item.activePattern ?? item.route)}>
                                        <Link href={route(item.route)} prefetch>
                                            <item.icon />
                                            <span>{item.title}</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            ) : (
                                <SidebarMenuItem key={item.title}>
                                    <SidebarMenuButton
                                        type="button"
                                        aria-disabled="true"
                                        tooltip={`${item.title} (soon)`}
                                        className="text-sidebar-foreground/50 hover:text-sidebar-foreground/50 active:text-sidebar-foreground/50 cursor-default hover:bg-transparent active:bg-transparent"
                                    >
                                        <item.icon />
                                        <span>{item.title}</span>
                                    </SidebarMenuButton>
                                    <SidebarMenuBadge className="border-sidebar-border text-muted-foreground rounded-full border px-1.5 text-[10px] font-normal">
                                        Soon
                                    </SidebarMenuBadge>
                                </SidebarMenuItem>
                            ),
                        )}
                    </SidebarMenu>
                </SidebarGroup>
            </SidebarContent>
            <SidebarRail />
        </Sidebar>
    );
}
