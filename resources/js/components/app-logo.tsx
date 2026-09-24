import AppLogoIcon from './app-logo-icon';

/** Sidebar/header brand: the "S" mark plus product name and an optional subtitle (company name, "Admin"). */
export default function AppLogo({ subtitle }: { subtitle?: string | null }) {
    return (
        <>
            <AppLogoIcon className="size-8 shrink-0" />
            <div className="ml-1 grid flex-1 text-left text-sm leading-tight">
                <span className="truncate font-semibold tracking-tight">
                    Switch <span className="text-brand-green">&amp;</span> Save
                </span>
                {subtitle && <span className="text-muted-foreground truncate text-xs">{subtitle}</span>}
            </div>
        </>
    );
}
