import '../css/store.css';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { setCountry } from './lib/country';
import { type CountryProfile } from './types';

/*
 * The public online store's entry (OS-2, resources/views/store.blade.php): only the store's pages, its own
 * stylesheet, light only, and page titles as each page sets them ("Basket · Kirkgate Convenience").
 */
createInertiaApp({
    title: (title) => title,
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/store/**/*.tsx')),
    setup({ el, App, props }) {
        setCountry(props.initialPage.props.country as CountryProfile | undefined);

        createRoot(el).render(<App {...props} />);
    },
    progress: {
        color: '#CFF27A',
        delay: 150,
    },
});

/*
 * Audit OSA-20: after a visit to another page (not a filter or basket change on the same page), focus moves to the
 * page's main area and its title is announced, as a full page load would. A page that has already put focus inside
 * the main area itself (e.g. an order confirmation heading) keeps it.
 */
let lastPath = window.location.pathname;
const announcer = document.createElement('div');
announcer.setAttribute('aria-live', 'polite');
announcer.setAttribute('aria-atomic', 'true');
announcer.className = 'sr-only';
document.body.appendChild(announcer);

router.on('navigate', () => {
    const path = window.location.pathname;

    if (path === lastPath) {
        return;
    }

    lastPath = path;
    window.setTimeout(() => {
        const main = document.getElementById('main');

        if (main && !main.contains(document.activeElement)) {
            main.focus({ preventScroll: true });
        }

        announcer.textContent = '';
        window.setTimeout(() => (announcer.textContent = document.title), 50);
    }, 60);
});
