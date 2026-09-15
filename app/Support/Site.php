<?php

namespace App\Support;

use Illuminate\Support\Arr;

/**
 * The public site's content, assembled.
 *
 * Two sources feed the shopfront and this is where they meet: config/shop.php
 * holds the facts, lang/{locale}/site.php holds the words. Templates ask this
 * class and never either of those directly, which is what keeps the Hindi page
 * a translation of the same site rather than a second site.
 *
 * The one rule worth stating out loud is in stats(): a figure whose `verified`
 * flag is false is **dropped**, not printed. The page this replaced carried
 * "32+ years" and "14k+ motors rewound" as decoration, and a workshop caught
 * inventing its own numbers has nothing left to be believed about. An empty
 * trust strip is a much smaller problem, and it is one somebody fixes by
 * filling in config/shop.php rather than by arguing.
 */
final class Site
{
    /** The locale served when no prefix is present. */
    public const DEFAULT_LOCALE = 'en';

    /* ---------------------------------------------------------------------
     | The shop
     | ------------------------------------------------------------------ */

    /** One fact from config/shop.php. */
    public static function shop(?string $key = null, mixed $default = null): mixed
    {
        return $key === null
            ? config('shop')
            : config('shop.'.$key, $default);
    }

    /** The address as one line, for the contact card and the JSON-LD. */
    public static function addressLine(): string
    {
        return implode(', ', array_filter([
            self::shop('street'),
            self::shop('city'),
            self::shop('state').' '.self::shop('postcode'),
        ]));
    }

    /**
     * The e-mail, or null while it is an unverified personal address.
     *
     * Publishing somebody's personal inbox as a shop contact is worse than
     * publishing no address at all — it cannot be withdrawn once it is
     * scraped, and the WhatsApp number is the channel this shop's customers
     * actually use anyway.
     */
    public static function email(): ?string
    {
        return self::shop('email_verified') ? self::shop('email') : null;
    }

    /**
     * A wa.me link with the first message already typed.
     *
     * The visitor is standing next to a motor holding a phone in one hand, so
     * the fewer words they have to compose the more likely the photo arrives.
     */
    public static function whatsappUrl(): string
    {
        return 'https://wa.me/'.self::shop('whatsapp_dial')
            .'?text='.rawurlencode(self::text('whatsapp_message'));
    }

    public static function telUrl(): string
    {
        return 'tel:+'.self::shop('phone_dial');
    }

    /**
     * The opening hours as a customer reads them.
     *
     * config/shop.php stores them as "05:30" and "20:00" because that is what
     * `openingHoursSpecification` in the JSON-LD requires, and that record is
     * what puts "Open now" under the shop's name in a search result. Nobody
     * standing at a counter reads 24-hour time, though, so the page gets
     * "5:30 am – 8:00 pm" formatted from the same two values — one source, two
     * readers (§4.4).
     */
    public static function hoursLabel(): string
    {
        $format = static fn (string $time): string => strtolower(
            date('g:i a', strtotime($time)),
        );

        return $format(self::shop('hours.opens')).' – '.$format(self::shop('hours.closes'));
    }

    /* ---------------------------------------------------------------------
     | Words
     | ------------------------------------------------------------------ */

    /**
     * The values behind every `:placeholder` in the language files.
     *
     * Every fact that appears mid-sentence goes through here, so a sentence in
     * either language can never carry a stale copy of the town's name or the
     * HP ceiling.
     *
     * @return array<string, string>
     */
    public static function replacements(): array
    {
        /*
        | A place name inside a sentence is spelt in the sentence's own script:
        | "Charkhi Dadri भर में" reads as a page that was half-translated. So
        | `site.places` may override the config's spelling, and falls back to it
        | when a language has nothing of its own to say.
        |
        | Only the prose is affected. The JSON-LD address, the map link and the
        | page title still read config/shop.php directly, because a search
        | engine and a courier both want the Latin form.
        */
        $places = __('site.places');
        $places = is_array($places) ? $places : [];

        $place = static fn (string $key, string $fallback): string => trim(
            (string) ($places[$key] ?? '')
        ) ?: $fallback;

        return [
            'shop' => (string) self::shop('name'),
            'town' => $place('town', (string) self::shop('town')),
            'district' => $place('district', (string) self::shop('district')),
            'state' => $place('state', (string) self::shop('state')),
            'phone' => (string) self::shop('phone'),
            'hp_min' => (string) self::shop('capability.hp_min'),
            'hp_max' => (string) self::shop('capability.hp_max'),
            'year' => date('Y'),
        ];
    }

    /** One translated string, with the shop's facts substituted in. */
    public static function text(string $key, array $extra = []): string
    {
        $value = __('site.'.$key, $extra + self::replacements());

        // __() hands back the key itself when nothing is defined for it, and
        // an array when the key names a subtree. Neither is a string a
        // template should be printing.
        return is_string($value) ? $value : '';
    }

    /**
     * A translated subtree, with the replacements applied all the way down.
     *
     * Laravel substitutes `:placeholders` only in the string it returns, so an
     * array of rows comes back with `:town` still in it. Everything on this
     * site that repeats — the trust strip, the process steps, the FAQ — is an
     * array of rows, so the walk is not an optimisation.
     */
    public static function items(string $key): array
    {
        $value = __('site.'.$key);

        return is_array($value) ? self::substitute($value) : [];
    }

    private static function substitute(array $rows): array
    {
        $replacements = self::replacements();

        array_walk_recursive($rows, function (&$value) use ($replacements) {
            if (! is_string($value)) {
                return;
            }

            foreach ($replacements as $search => $replace) {
                $value = str_replace(':'.$search, $replace, $value);
            }
        });

        return $rows;
    }

    /* ---------------------------------------------------------------------
     | The figures, and the ones that are missing
     | ------------------------------------------------------------------ */

    /**
     * The trust strip's numbers — only the ones somebody has confirmed.
     *
     * Returns rows of `[value, label]` ready to print. An unconfirmed fact
     * contributes nothing: no row, no dash, no "coming soon". A tile reading
     * "—" tells a visitor there is a number there and that the shop would not
     * say it, which is worse than the tile not existing.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function stats(): array
    {
        $facts = self::shop('facts', []);
        $out = [];

        $established = self::verified($facts, 'established');
        if ($established !== null && $established <= (int) date('Y')) {
            $out[] = [
                'value' => ((int) date('Y') - (int) $established).'+',
                'label' => self::text('stats.years'),
            ];
        }

        $rewound = self::verified($facts, 'motors_rewound');
        if ($rewound !== null) {
            $out[] = [
                'value' => number_format((int) $rewound).'+',
                'label' => self::text('stats.rewound'),
            ];
        }

        $turnaround = self::verified($facts, 'turnaround_hours');
        if ($turnaround !== null) {
            $out[] = [
                'value' => $turnaround.' hr',
                'label' => self::text('stats.turnaround'),
            ];
        }

        $warranty = self::verified($facts, 'warranty_months');
        if ($warranty !== null) {
            $out[] = [
                'value' => $warranty.' mo',
                'label' => self::text('stats.warranty'),
            ];
        }

        return $out;
    }

    /** The value of a fact, or null when nobody has confirmed it. */
    private static function verified(array $facts, string $key): int|string|null
    {
        $fact = Arr::get($facts, $key, []);

        return ($fact['verified'] ?? false) === true ? ($fact['value'] ?? null) : null;
    }

    /* ---------------------------------------------------------------------
     | Services
     | ------------------------------------------------------------------ */

    /**
     * Every service, config structure merged with its translated copy.
     *
     * @return list<array{slug: string, icon: string, tone: string, has_page: bool, title: string, tag: string, summary: string, url: ?string}>
     */
    public static function services(?string $locale = null): array
    {
        $services = [];

        foreach (self::shop('services', []) as $slug => $meta) {
            $copy = self::items('services.'.$slug);

            $services[] = [
                'slug' => $slug,
                'icon' => $meta['icon'],
                'tone' => $meta['tone'],
                'has_page' => (bool) $meta['page'],
                'title' => $copy['title'] ?? $slug,
                'tag' => $copy['tag'] ?? '',
                'summary' => $copy['summary'] ?? '',
                'url' => $meta['page'] ? self::url('services.show', ['service' => $slug], $locale) : null,
            ];
        }

        return $services;
    }

    /** One service with its long-form page copy, or null if it has no page. */
    public static function service(string $slug, ?string $locale = null): ?array
    {
        $meta = self::shop('services.'.$slug);

        if ($meta === null || ! $meta['page']) {
            return null;
        }

        $copy = self::items('services.'.$slug);

        return [
            'slug' => $slug,
            'icon' => $meta['icon'],
            'tone' => $meta['tone'],
            'title' => $copy['title'] ?? $slug,
            'tag' => $copy['tag'] ?? '',
            'summary' => $copy['summary'] ?? '',
            'lead' => Arr::get($copy, 'page.lead', ''),
            'sections' => Arr::get($copy, 'page.sections', []),
            'specs_title' => Arr::get($copy, 'page.specs_title', ''),
            'specs' => Arr::get($copy, 'page.specs', []),
            'url' => self::url('services.show', ['service' => $slug], $locale),
        ];
    }

    /** The slugs that have a page of their own — the route's whitelist. */
    public static function pagedServiceSlugs(): array
    {
        return array_keys(array_filter(
            self::shop('services', []),
            static fn (array $meta): bool => (bool) $meta['page'],
        ));
    }

    /* ---------------------------------------------------------------------
     | Locale and URLs
     | ------------------------------------------------------------------ */

    /** The locale this request is being served in. */
    public static function locale(): string
    {
        $locale = app()->getLocale();

        return isset(self::shop('locales')[$locale]) ? $locale : self::DEFAULT_LOCALE;
    }

    /**
     * A URL for one of the site's routes, in a given locale.
     *
     * Hindi lives under `/hi` and its routes carry an `hi.` name prefix, so
     * choosing a language is choosing a route name. Doing it this way rather
     * than with a `?lang=` parameter is what gives each language a real URL to
     * be linked to, shared on WhatsApp and indexed under.
     */
    public static function url(string $name, array $params = [], ?string $locale = null): string
    {
        $locale ??= self::locale();
        $prefix = $locale === self::DEFAULT_LOCALE ? '' : $locale.'.';

        return route($prefix.$name, $params);
    }

    /**
     * The same page in every language, for the language switch and hreflang.
     *
     * `current` marks the one being served so the switch can show it as
     * chosen; both are emitted as `<link rel="alternate" hreflang>` so a
     * search engine is told the pages are translations rather than duplicates.
     *
     * @return list<array{locale: string, label: string, short: string, html: string, url: string, current: bool}>
     */
    public static function alternates(string $name, array $params = []): array
    {
        $current = self::locale();

        $out = [];

        foreach (self::shop('locales', []) as $locale => $meta) {
            $out[] = [
                'locale' => $locale,
                'label' => $meta['label'],
                'short' => $meta['short'],
                'html' => $meta['html'],
                'url' => self::url($name, $params, $locale),
                'current' => $locale === $current,
            ];
        }

        return $out;
    }

    /* ---------------------------------------------------------------------
     | Structured data
     | ------------------------------------------------------------------ */

    /**
     * The LocalBusiness record behind the shop's search listing.
     *
     * This is what puts the address, the hours and "Open now" under the shop's
     * name in a result, and for a business whose customers search on a phone
     * from a field it is worth more than any section on the page.
     *
     * Nothing unverified goes in. `priceRange` and `aggregateRating` are
     * deliberately absent: a rating nobody left is a fabricated review with a
     * schema wrapper around it, and search engines penalise exactly that.
     */
    public static function localBusinessSchema(): array
    {
        $hours = self::shop('hours');

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => self::shop('name'),
            'description' => self::text('meta.home_description'),
            'url' => self::url('home', [], self::DEFAULT_LOCALE),
            'telephone' => '+'.self::shop('phone_dial'),
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => self::shop('street'),
                'addressLocality' => self::shop('city'),
                'addressRegion' => self::shop('state'),
                'postalCode' => self::shop('postcode'),
                'addressCountry' => 'IN',
            ],
            'hasMap' => self::shop('map_url'),
            'openingHoursSpecification' => [[
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => $hours['days'],
                'opens' => $hours['opens'],
                'closes' => $hours['closes'],
            ]],
            'areaServed' => array_map(
                static fn (string $place): array => ['@type' => 'Place', 'name' => $place],
                self::shop('area', []),
            ),
            'makesOffer' => array_map(
                static fn (array $service): array => [
                    '@type' => 'Offer',
                    'itemOffered' => ['@type' => 'Service', 'name' => $service['title']],
                ],
                self::services(),
            ),
        ];

        /*
         * Coordinates outrank the written address here. A street line in a
         * district where half the landmarks are "near the petrol pump" is not
         * something a mapping service can resolve; a latitude is.
         */
        if ($geo = self::shop('geo')) {
            $schema['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude' => $geo['latitude'],
                'longitude' => $geo['longitude'],
            ];
        }

        if ($email = self::email()) {
            $schema['email'] = $email;
        }

        if ($gstin = self::shop('gstin')) {
            $schema['taxID'] = $gstin;
        }

        if ($reviews = self::shop('reviews_url')) {
            $schema['sameAs'] = [$reviews];
        }

        return $schema;
    }

    /**
     * The questions, as a FAQPage.
     *
     * Written so each answer stands on its own: a search result may show one
     * without the page around it, and an answer that starts "as mentioned
     * above" is no answer at all in that context.
     */
    public static function faqSchema(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(
                static fn (array $item): array => [
                    '@type' => 'Question',
                    'name' => $item['q'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
                ],
                self::items('faq.items'),
            ),
        ];
    }

    /** A service page's own record, plus the trail back to the shop. */
    public static function serviceSchema(array $service): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Service',
            'name' => $service['title'],
            'description' => $service['summary'],
            'url' => $service['url'],
            'areaServed' => array_map(
                static fn (string $place): array => ['@type' => 'Place', 'name' => $place],
                self::shop('area', []),
            ),
            'provider' => [
                '@type' => 'LocalBusiness',
                'name' => self::shop('name'),
                'telephone' => '+'.self::shop('phone_dial'),
                'address' => [
                    '@type' => 'PostalAddress',
                    'streetAddress' => self::shop('street'),
                    'addressLocality' => self::shop('city'),
                    'addressRegion' => self::shop('state'),
                    'postalCode' => self::shop('postcode'),
                    'addressCountry' => 'IN',
                ],
            ],
        ];
    }
}
