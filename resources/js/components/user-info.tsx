import { InitialsAvatar } from '@/components/shared/entity-cell';
import { Avatar, AvatarImage } from '@/components/ui/avatar';
import { type User } from '@/types';

/** Avatar + name (+ email or a subtitle such as the role). Used in the sidebar user card and account menus. */
export function UserInfo({ user, showEmail = false, subtitle }: { user: User; showEmail?: boolean; subtitle?: string }) {
    const second = showEmail ? user.email : subtitle;

    return (
        <>
            {user.avatar ? (
                <Avatar className="size-8 overflow-hidden rounded-full">
                    <AvatarImage src={user.avatar} alt={user.name} />
                </Avatar>
            ) : (
                <InitialsAvatar name={user.name} />
            )}
            <div className="grid min-w-0 flex-1 text-left text-sm leading-tight">
                <span className="text-foreground truncate font-medium">{user.name}</span>
                {second && <span className="text-muted-foreground truncate text-xs font-normal">{second}</span>}
            </div>
        </>
    );
}
