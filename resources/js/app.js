import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { i18nPlugin, setCurrentLocale } from './lib/i18n';

const appName = import.meta.env.VITE_APP_NAME || '34Pazar';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),

    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });

        return pages[`./Pages/${name}.vue`];
    },

    setup({ el, App, props, plugin }) {
        // Bileşen dışındaki biçimleyiciler (para, tarih) dili buradan okur.
        setCurrentLocale(props.initialPage.props.locale);
        router.on('navigate', (event) => setCurrentLocale(event.detail.page.props.locale));

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(i18nPlugin)
            .mount(el);
    },

    progress: {
        color: '#CE310D',
    },
});
