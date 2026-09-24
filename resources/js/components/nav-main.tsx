import { Badge } from '@/components/ui/badge';
import { SidebarGroup, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';

export function NavMain({ items = [] }: { items: NavItem[] }) {
    const page = usePage();
    const currentPath = page.url.split('?')[0];

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarMenu>
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        {item.soon ? (
                            <SidebarMenuButton
                                aria-disabled="true"
                                tooltip={`${item.title} (coming soon)`}
                                className="text-muted-foreground cursor-default"
                            >
                                {item.icon && <item.icon />}
                                <span>{item.title}</span>
                                <Badge
                                    variant="secondary"
                                    className="ml-auto px-1.5 py-0 text-[10px] font-medium group-data-[collapsible=icon]:hidden"
                                >
                                    Soon
                                </Badge>
                            </SidebarMenuButton>
                        ) : (
                            <SidebarMenuButton asChild isActive={item.isActive ?? item.url === currentPath} tooltip={item.title}>
                                <Link href={item.url} prefetch>
                                    {item.icon && <item.icon />}
                                    <span>{item.title}</span>
                                </Link>
                            </SidebarMenuButton>
                        )}
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
