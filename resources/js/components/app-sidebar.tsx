import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Banknote, BarChart3, BookOpen, Boxes, LayoutGrid, Package, Receipt, Settings, Tag, Truck, UserCog, Users } from 'lucide-react';
import AppLogo from './app-logo';

// Only Dashboard exists so far; the rest are placeholders for later modules.
const mainNavItems: NavItem[] = [
    { title: 'Dashboard', url: '/app', icon: LayoutGrid },
    { title: 'Sales', url: '#', icon: Receipt, soon: true },
    { title: 'Products', url: '#', icon: Package, soon: true },
    { title: 'Promotions', url: '#', icon: Tag, soon: true },
    { title: 'Stock', url: '#', icon: Boxes, soon: true },
    { title: 'Purchasing', url: '#', icon: Truck, soon: true },
    { title: 'Customers', url: '#', icon: Users, soon: true },
    { title: 'Cash and Z', url: '#', icon: Banknote, soon: true },
    { title: 'Accounts', url: '#', icon: BookOpen, soon: true },
    { title: 'Staff', url: '#', icon: UserCog, soon: true },
    { title: 'Reports', url: '#', icon: BarChart3, soon: true },
    { title: 'Settings', url: '#', icon: Settings, soon: true },
];

export function AppSidebar() {
    const { company } = usePage<SharedData>().props;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/app" prefetch>
                                <AppLogo subtitle={company?.name} />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
