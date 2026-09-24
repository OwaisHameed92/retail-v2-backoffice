import {
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
} from '@/components/ui/dropdown-menu';
import { useAppearance, type Appearance } from '@/hooks/use-appearance';
import { Monitor, Moon, Sun, SunMoon } from 'lucide-react';

const options: { value: Appearance; label: string; icon: typeof Sun }[] = [
    { value: 'light', label: 'Light', icon: Sun },
    { value: 'dark', label: 'Dark', icon: Moon },
    { value: 'system', label: 'System', icon: Monitor },
];

/** "Theme" submenu for account menus: light, dark or follow the system. */
export function ThemeSubmenu() {
    const { appearance, updateAppearance } = useAppearance();

    return (
        <DropdownMenuSub>
            <DropdownMenuSubTrigger>
                <SunMoon className="text-muted-foreground size-4" aria-hidden />
                Theme
            </DropdownMenuSubTrigger>
            <DropdownMenuSubContent className="min-w-36">
                <DropdownMenuRadioGroup value={appearance} onValueChange={(value) => updateAppearance(value as Appearance)}>
                    {options.map((option) => (
                        <DropdownMenuRadioItem key={option.value} value={option.value}>
                            <option.icon className="text-muted-foreground mr-2 size-4" aria-hidden />
                            {option.label}
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuSubContent>
        </DropdownMenuSub>
    );
}

export default ThemeSubmenu;
