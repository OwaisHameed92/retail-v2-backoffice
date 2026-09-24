import { ThemeSubmenu } from '@/components/shell/theme-menu';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { type SharedData, type User } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { LogOut, UserRound } from 'lucide-react';

interface UserMenuContentProps {
    user: User;
}

/** Tenant account menu: who you are, switch business, your settings, theme, log out. */
export function UserMenuContent({ user }: UserMenuContentProps) {
    const cleanup = useMobileNavigation();
    const { company, companies } = usePage<SharedData>().props;

    const switchCompany = (companyId: string) => {
        if (companyId !== company?.id) {
            router.post(route('app.company.switch'), { company_id: companyId });
        }
    };

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2.5 px-2 py-2 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            {companies && companies.length > 1 && (
                <>
                    <DropdownMenuSeparator />
                    <DropdownMenuLabel>Switch business</DropdownMenuLabel>
                    <DropdownMenuRadioGroup value={company?.id} onValueChange={switchCompany}>
                        {companies.map((option) => (
                            <DropdownMenuRadioItem key={option.id} value={option.id}>
                                <span className="truncate">{option.name}</span>
                            </DropdownMenuRadioItem>
                        ))}
                    </DropdownMenuRadioGroup>
                </>
            )}
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    <Link className="w-full" href={route('profile.edit')} prefetch onClick={cleanup}>
                        <UserRound className="text-muted-foreground size-4" />
                        Your profile
                    </Link>
                </DropdownMenuItem>
                <ThemeSubmenu />
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link className="w-full" method="post" href={route('logout')} as="button" onClick={cleanup}>
                    <LogOut className="text-muted-foreground size-4" />
                    Log out
                </Link>
            </DropdownMenuItem>
        </>
    );
}
