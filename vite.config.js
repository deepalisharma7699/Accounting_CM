import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            /*
            | Two stylesheets, and deliberately two.
            |
            | `app.css` is everything behind the sign-in; `welcome.css` is the
            | shopfront. They share only the tokens and the buttons in
            | `shared.css`, so a change to one surface cannot show up unannounced
            | on the other — and neither page downloads the other's CSS.
            */
            input: [
                'resources/css/app.css',
                'resources/css/welcome.css',
                'resources/js/app.js',
                /*
                | The customer's copy of an invoice — /i/{token}.
                |
                | A fourth entry for the same reason there is a second
                | stylesheet: `app.js` carries the auth client, the permission
                | gating and the module shell, and its first act on a page it
                | does not recognise is to redirect to /login. None of that
                | belongs on a page opened by somebody who has no account.
                */
                'resources/js/invoice.js',
            ],
            refresh: true,
            // The design uses Inter. Served from Bunny rather than the
            // design's Google Fonts import so no request leaves for a
            // third-party CDN on page load.
            fonts: [
                bunny('Inter', {
                    weights: [300, 400, 500, 600, 700],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
