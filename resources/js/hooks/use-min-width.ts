import { useEffect, useState } from 'react';

/** True while the viewport is at least `px` wide (Tailwind breakpoints: sm 640, md 768, lg 1024, xl 1280). */
export function useMinWidth(px: number): boolean {
    const query = `(min-width: ${px}px)`;
    const [matches, setMatches] = useState(() => (typeof window === 'undefined' ? true : window.matchMedia(query).matches));

    useEffect(() => {
        const mql = window.matchMedia(query);
        const onChange = () => setMatches(mql.matches);
        onChange();
        mql.addEventListener('change', onChange);

        return () => mql.removeEventListener('change', onChange);
    }, [query]);

    return matches;
}

/** Which Tailwind breakpoint the viewport is at: 0 = phone, 1 = sm, 2 = md, 3 = lg, 4 = xl. */
export function useBreakpoint(): 0 | 1 | 2 | 3 | 4 {
    const sm = useMinWidth(640);
    const md = useMinWidth(768);
    const lg = useMinWidth(1024);
    const xl = useMinWidth(1280);

    return xl ? 4 : lg ? 3 : md ? 2 : sm ? 1 : 0;
}
