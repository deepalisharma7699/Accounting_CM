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
            | `app.css` is everything behind the sign-in; `site.css` is the
            | public site. They share only the tokens and the buttons in
            | `shared.css`, so a change to one surface cannot show up unannounced
            | on the other — and neither page downloads the other's CSS.
            */
            input: [
                'resources/css/app.css',
                'resources/css/site.css',
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
            /*
            | The design uses Inter. Served from Bunny rather than the design's
            | Google Fonts import so no request leaves for a third-party CDN on
            | page load — the files are downloaded at build time and served from
            | this origin.
            |
            | Noto Sans Devanagari is for the Hindi half of the public site.
            | Inter carries no Devanagari, so without it /hi falls back to
            | whatever the device happens to have — which on Windows is Nirmala
            | UI, on Android is something else again, and on a machine with
            | neither is tofu.
            |
            | It costs nothing on the pages that do not use it: every face is
            | emitted with a `unicode-range`, so a browser only fetches the
            | Devanagari files when it actually has Devanagari to draw. The
            | English page and the whole application never touch them.
            */
            fonts: [
                bunny('Inter', {
                    weights: [300, 400, 500, 600, 700],
                }),
                bunny('Noto Sans Devanagari', {
                    weights: [400, 500, 600, 700],
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
