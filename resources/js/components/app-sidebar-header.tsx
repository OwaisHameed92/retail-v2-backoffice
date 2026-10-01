import { AppNotificationsMenu } from '@/components/app/notifications/app-notifications-menu';
import { AccountTrigger, HelpMenu, SearchTrigger, Topbar, TopbarDivider } from '@/components/shell/topbar';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { UserMenuContent } from '@/components/user-menu-content';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';

/** Business portal top bar (light, mint wash): menu button, "Ask anything" (the portal assistant, module 6.2: "Soon"), help, notifications, account menu. */
export function AppSidebarHeader() {
    const { auth, companyRole } = usePage<SharedData>().props;

    return (
        <Topbar
            home="/app"
            search={<SearchTrigger placeholder="Ask anything, e.g. top sellers in Leeds" shortPlaceholder="Ask anything…" icon={Sparkles} disabled />}
            actions={
                <>
                    <AppNotificationsMenu />
                    <HelpMenu />
                    <TopbarDivider />
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
