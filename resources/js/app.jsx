import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { route as ziggyRoute } from 'ziggy-js';
import { Ziggy as generatedZiggy } from './ziggy';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';
const pages = {
    ...import.meta.glob('./Pages/**/*.jsx'),
    ...import.meta.glob('../../app/Modules/**/Resources/Pages/**/*.jsx'),
};

globalThis.Ziggy = {
    ...generatedZiggy,
    url: window.location.origin,
    location: window.location,
};

globalThis.route = (name, params, absolute, config = globalThis.Ziggy) =>
    ziggyRoute(name, params, absolute, config);

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => {
        const page = pages[`./Pages/${name}.jsx`]
            ? `./Pages/${name}.jsx`
            : Object.keys(pages).find((path) =>
                  path.endsWith(`/Resources/Pages/${name}.jsx`),
              );

        return resolvePageComponent(page, pages);
    },
    setup({ el, App, props }) {
        applyBranding(props.initialPage.props.branding);

        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        color: '#4B5563',
    },
});

function applyBranding(branding) {
    if (!branding) {
        return;
    }

    document.documentElement.style.setProperty('--color-primary', branding.primaryRgb);
    document.documentElement.style.setProperty('--color-secondary', branding.secondaryRgb);

    const savedMode = localStorage.getItem('appearance-mode');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const useDark = savedMode === 'dark' || (!savedMode && (branding.themeMode === 'dark' || (branding.themeMode === 'system' && prefersDark)));

    document.documentElement.classList.toggle('dark', useDark);
    document.documentElement.classList.remove('liquid-glass');
    document.documentElement.style.colorScheme = useDark ? 'dark' : 'light';
    localStorage.removeItem('appearance-glass');
}
