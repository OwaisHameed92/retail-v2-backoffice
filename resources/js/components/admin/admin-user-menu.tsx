import { type AdminSession } from '@/components/admin/types';
import { InitialsAvatar } from '@/components/shared/entity-cell';
import { ThemeSubmenu } from '@/components/shell/theme-menu';
import { AccountTrigger } from '@/components/shell/topbar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { Link, usePage } from '@inertiajs/react';
import { LogOut, ShieldCheck } from 'lucide-react';

/** The admin account menu: who is signed in, sign-in security, theme, log out. */
function AdminMenuItems({ admin }: { admin: AdminSession }) {
    const cleanup = useMobileNavigation();
    const twoFactorEnabled = usePage<{ twoFactorEnabled?: boolean }>().props.twoFactorEnabled === true;

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2.5 px-2 py-2">
                    <InitialsAvatar name={admin.name} />
                    <div className="grid min-w-0 text-sm leading-tight">
                        <span className="text-foreground truncate font-medium">{admin.name}</span>
                        <span className="text-muted-foreground truncate text-xs">{admin.email}</span>
                    </div>
                    <span className="bg-primary-soft text-primary ml-auto shrink-0 rounded px-1.5 py-0.5 text-[11px] font-medium">
                        {admin.roleLabel}
                    </span>
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            {twoFactorEnabled && (
                <DropdownMenuItem asChild>
                    <Link className="w-full" href={route('admin.security')} onClick={cleanup}>
                        <ShieldCheck className="text-muted-foreground size-4" />
                        Sign-in security
                    </Link>
                </DropdownMenuItem>
            )}
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

/** Top-bar account button (avatar, name, role) with the admin's account menu. */
export function AdminUserMenu({ admin }: { admin: AdminSession }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <AccountTrigger name={admin.name} subtitle={admin.roleLabel} />
            </DropdownMenuTrigger>
            <DropdownMenuContent className="w-72" align="end" sideOffset={8}>
                <AdminMenuItems admin={admin} />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
