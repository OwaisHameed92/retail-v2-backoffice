import { useEffect, useState } from 'react';

export type Appearance = 'light' | 'dark' | 'system';

/**
 * Light is the default for everyone (design system v2, pass 2). Only a theme the user picked is saved, under this
 * key; the old `appearance` key is ignored because earlier builds wrote "system" to it on every page load, which
 * is not a choice the user made. Keep in sync with the inline script in resources/views/app.blade.php.
 */
export const APPEARANCE_KEY = 'theme';
const DEFAULT_APPEARANCE: Appearance = 'light';

const prefersDark = () => window.matchMedia('(prefers-color-scheme: dark)').matches;

const readSaved = (): Appearance => {
    try {
        const saved = window.localStorage.getItem(APPEARANCE_KEY);

        return saved === 'light' || saved === 'dark' || saved === 'system' ? saved : DEFAULT_APPEARANCE;
    } catch {
        return DEFAULT_APPEARANCE;
    }
};

const applyTheme = (appearance: Appearance) => {
    const isDark = appearance === 'dark' || (appearance === 'system' && prefersDark());

    document.documentElement.classList.toggle('dark', isDark);
    document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
};

const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');

const handleSystemThemeChange = () => applyTheme(readSaved());

export function initializeTheme() {
    applyTheme(readSaved());

    // Only matters when the user chose "System".
    mediaQuery.addEventListener('change', handleSystemThemeChange);
}

export function useAppearance() {
    const [appearance, setAppearance] = useState<Appearance>(DEFAULT_APPEARANCE);

    const updateAppearance = (mode: Appearance) => {
        setAppearance(mode);
        try {
            window.localStorage.setItem(APPEARANCE_KEY, mode);
        } catch {
            // Blocked storage: the theme still applies for this page.
        }
        applyTheme(mode);
    };

    useEffect(() => {
        // Read only; never save a theme the user did not pick.
        setAppearance(readSaved());
    }, []);

    return { appearance, updateAppearance };
}
