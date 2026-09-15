<?php

/*
|--------------------------------------------------------------------------
| The shop
|--------------------------------------------------------------------------
|
| Every fact about the business that the public site states — the address on
| the contact card, the number behind every tel: and wa.me link, the figures in
| the trust strip, the structure of the services menu — is read from here and
| from nowhere else. A detail that is wrong is one edit in this file, not a
| search through eight Blade templates.
|
| What is NOT here is the wording. Copy lives in lang/en/site.php and
| lang/hi/site.php so the Hindi page is a translation of the same structure
| rather than a second site that drifts. The rule is: a fact that is the same
| in both languages belongs here; a sentence belongs there.
|
| `verified => false` on anything below means it has NOT been confirmed with
| the shop. Nothing marked false may be stated as a claim on the page — see
| App\Support\Site::stats(), which drops an unverified figure rather than
| printing it. That is deliberate: an invented "14,000 motors rewound" is the
| one thing a workshop's own site cannot afford to be caught at.
*/

return [

    /*
    |----------------------------------------------------------------------
    | Identity and how to reach it
    |----------------------------------------------------------------------
    |
    | `phone_dial` is what tel: and https://wa.me/ consume and must stay
    | digits-only with the country code and no plus. `phone` is only ever what
    | a visitor reads.
    */
    'name' => 'Choudhary Motors',
    'town' => 'Charkhi Dadri',
    'district' => 'Charkhi Dadri',
    'state' => 'Haryana',

    'phone' => '+91 98137 07087',
    'phone_dial' => '919813707087',
    'whatsapp_dial' => '919813707087',

    // TODO(shop): confirm — this is the address the previous page carried.
    'street' => 'Near Indian Oil Petrol Pump, Rohtak Road Bypass, Rawaldhi',
    'city' => 'Charkhi Dadri',
    'postcode' => '127306',

    /*
    | TODO(shop): a real shop mailbox. The current value is a personal address,
    | which is why `email_verified` is false — the contact card drops the whole
    | row rather than publishing it.
    */
    'email' => 'sunil.achievers@gmail.com',
    'email_verified' => false,

    /*
    | A link to the shop's own Google listing rather than an embedded map. No
    | third-party script runs on this site — which is also why the fonts are
    | self-hosted — and an iframe from Google would be one, on every page load,
    | for every visitor.
    |
    | The `cid` form is used deliberately: it is the listing's permanent id, so
    | it survives a rename or a move, where the maps.app.goo.gl short link the
    | share sheet produces is a redirect that Google can retire. Source link:
    | https://maps.app.goo.gl/JfB9HB641ru7mwhn8
    |
    | `reviews_url` is where "read the reviews" points. Until it is a real
    | listing the reviews section renders nothing at all rather than inventing
    | three customers, which is what the page it replaced did.
    */
    'map_url' => 'https://maps.google.com/?cid=507299632346997598',
    'reviews_url' => null,

    /*
    | The listing's own coordinates, read off the same place. They are stated in
    | the JSON-LD as `geo`, which is what lets a phone offer "Directions" from a
    | search result without opening the site, and they are the one part of an
    | address that cannot be mistyped into somewhere else in the district.
    |
    | Google files the shop as "Choudhary Motors / Rattan Foji / Sunil Kaale",
    | category: electric motor store. Only the first of those names is published.
    */
    'geo' => ['latitude' => 28.6146772, 'longitude' => 76.2819704],

    // TODO(shop): the GSTIN that appears on the shop's own invoices, or null.
    'gstin' => null,

    /*
    |----------------------------------------------------------------------
    | Opening hours
    |----------------------------------------------------------------------
    |
    | Structured rather than a sentence, because the same rows are read twice:
    | once for the contact card and once for `openingHoursSpecification` in the
    | LocalBusiness JSON-LD, which is what puts "Open now" under the shop's name
    | in a search result. Days use the schema.org spelling for that reason.
    */
    'hours' => [
        'days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
        'opens' => '05:30',
        'closes' => '20:00',
    ],

    /*
    |----------------------------------------------------------------------
    | The figures on the trust strip
    |----------------------------------------------------------------------
    |
    | Each is dropped from the page entirely while `verified` is false. Set the
    | true value and flip the flag; there is nothing else to change.
    */
    'facts' => [
        // Year the shop opened. The page prints the years since, not the year.
        'established' => ['value' => null, 'verified' => false],
        // Written warranty on a rewind, in months.
        'warranty_months' => ['value' => null, 'verified' => false],
        // The usual turnaround in hours — not the best case.
        'turnaround_hours' => ['value' => null, 'verified' => false],
        // Lifetime motors rewound, if it is a number anybody actually knows.
        'motors_rewound' => ['value' => null, 'verified' => false],
    ],

    /*
    |----------------------------------------------------------------------
    | What the shop takes on
    |----------------------------------------------------------------------
    |
    | The ceiling is stated because it is the first thing a caller with a 60 HP
    | mill motor asks, and the range is what decides whether they drive over.
    */
    'capability' => [
        'hp_min' => '0.5',
        'hp_max' => '100',
    ],

    /*
    |----------------------------------------------------------------------
    | The services menu
    |----------------------------------------------------------------------
    |
    | Structure only — the icon, the accent, and whether the service has a page
    | of its own. Titles and prose are keyed by these same slugs in the language
    | files, so adding a service is a row here plus a block in each lang file
    | and never a change to a template.
    |
    | `page => true` gives the service a route at /services/{slug}. Three of the
    | six have one: those are the searches people actually type. The other three
    | are answered fully by their card and would be three thin pages.
    */
    'services' => [
        'motor-rewinding' => ['icon' => 'wrench', 'tone' => 'copper', 'page' => true],
        'submersible-pumps' => ['icon' => 'droplet', 'tone' => 'blue', 'page' => true],
        'winding-wire' => ['icon' => 'layers', 'tone' => 'amber', 'page' => true],
        'new-motors' => ['icon' => 'zap', 'tone' => 'sky', 'page' => false],
        'spares' => ['icon' => 'package', 'tone' => 'slate', 'page' => false],
        'site-visits' => ['icon' => 'truck', 'tone' => 'emerald', 'page' => false],
    ],

    /*
    |----------------------------------------------------------------------
    | The service area
    |----------------------------------------------------------------------
    |
    | Not facts of the business but of its geography: read by `areaServed` in
    | the JSON-LD and printed on the coverage section. Kept here so the two can
    | never disagree.
    */
    'area' => [
        'Charkhi Dadri', 'Badhra', 'Baund Kalan', 'Jhojhu Kalan', 'Kalali',
        'Loharu', 'Bhiwani', 'Mandhana', 'Sanjarwas', 'Atela Kalan',
        'Imlota', 'Pilod', 'Kadma', 'Ranila',
    ],

    /*
    |----------------------------------------------------------------------
    | What is on the counter
    |----------------------------------------------------------------------
    |
    | Deliberately a list of what the shop deals in rather than a wall of brand
    | logos: a logo implies an authorised dealership, which is a different claim
    | and a legal one. While `verified` is false the makes are not named on the
    | page at all — the section describes the categories instead.
    */
    'counter' => [
        'verified' => false,
        'motors' => ['Crompton', 'Kirloskar', 'Havells', 'Bharat Bijlee'],
        'pumps' => ['CRI', 'Texmo', 'V-Guard', 'Kirloskar'],
        'bearings' => ['SKF', 'FAG', 'NBC', 'ZKL'],
    ],

    /*
    |----------------------------------------------------------------------
    | The languages the site is published in
    |----------------------------------------------------------------------
    |
    | `''` is the prefix of the default locale, so English stays at `/` and
    | Hindi is at `/hi`. Both are rendered server-side and each carries an
    | hreflang pointing at the other: a language switch that only swapped text
    | in the browser would leave one of the two invisible to search, which for
    | a shop whose customers type Devanagari is the half that matters.
    */
    'locales' => [
        'en' => ['prefix' => '', 'label' => 'English', 'short' => 'EN', 'html' => 'en-IN'],
        'hi' => ['prefix' => 'hi', 'label' => 'हिन्दी', 'short' => 'हिं', 'html' => 'hi-IN'],
    ],
];
