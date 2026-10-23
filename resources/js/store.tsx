import '../css/store.css';

import { createInertiaApp } from '@inertiajs/react';
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
