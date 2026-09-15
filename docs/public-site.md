# The public site

Everything a visitor sees before signing in: the home page, three service
pages, and both of those again in Hindi. It is the shop's own site — what it
does, how the work is done, what is on the counter and how to reach it — with
the staff sign-in as a modal on it rather than a screen of its own.

It is **not** the application, and it deliberately does not look like it. The
application is light and blue because the Figma build is; the site is copper on
ink, because the shop rewinds motors with copper and sells it by the kilo. The
change in tone is what tells somebody which of the two they are looking at.

---

## What is where

| Concern | File |
|---|---|
| Facts — address, phone, hours, figures, service list | [config/shop.php](../config/shop.php) |
| Words, English | [lang/en/site.php](../lang/en/site.php) |
| Words, Hindi | [lang/hi/site.php](../lang/hi/site.php) |
| Assembly, URLs, structured data | [app/Support/Site.php](../app/Support/Site.php) |
| The three reference tables | [app/Support/MotorReference.php](../app/Support/MotorReference.php) |
| Routing and the two locales | [routes/web.php](../routes/web.php), [SetSiteLocale](../app/Http/Middleware/SetSiteLocale.php) |
| Pages | [SiteController](../app/Http/Controllers/SiteController.php), `resources/views/site/` |
| Styling | [resources/css/site.css](../resources/css/site.css) |
| Behaviour | [resources/js/pages/site.js](../resources/js/pages/site.js) |
| Tests | [tests/Feature/Site/PublicSiteTest.php](../tests/Feature/Site/PublicSiteTest.php) |

**A fact that is the same in both languages belongs in the config; a sentence
belongs in a language file.** That is the whole filing rule. A number that has
to be corrected in two places is a number that will end up wrong in one of
them.

Templates read `App\Support\Site` and never `config()` or `__()` directly.

---

## The rule that matters most

**A figure nobody has confirmed is not printed.**

`config/shop.php` carries a `verified` flag beside each of the four figures the
trust strip can show — years in business, motors rewound, turnaround, warranty.
While a flag is `false`, `Site::stats()` drops that figure *and its label*. No
tile, no dash, no "coming soon": a tile reading "—" tells a visitor there is a
number there and that the shop would not say it, which is worse than the tile
not existing.

The page this replaced carried "32+ years on the same road" and "14k+ motors
rewound" as decoration, along with three invented customer testimonials. Nobody
had checked any of it. A workshop caught inventing its own numbers has nothing
left to be believed about, and the entire argument this site makes — *we
measure things and we write the readings down* — dies with it.

The same rule runs through three other places:

- **`email_verified`.** The address on file is a personal inbox, so the e-mail
  row is absent from the contact card and the footer. An address published on a
  public page cannot be withdrawn once it has been scraped.
- **`counter.verified`.** Makes are not named until somebody confirms them: a
  brand on a shopfront reads as a dealership, which is a different claim and a
  legal one. Until then the section says stock moves week to week and to ask —
  which is also more useful than a stale logo wall.
- **`reviews_url`.** There is no reviews section, because there are no real
  reviews. When there is a Google listing, that is where it points.

`aggregateRating` and `priceRange` are deliberately absent from the
LocalBusiness JSON-LD for the same reason. A rating with no reviews behind it is
a fabricated review with a schema wrapper on it, and it is the specific thing a
search engine penalises a local business for.

**To publish a figure: set its value and flip its flag. There is nothing else to
change.**

---

## Two languages, one site

English is at `/`, Hindi at `/hi`. The language is **in the path**, never in a
cookie or an `Accept-Language` header.

That is not a style preference. A cookie-driven switch gives the two languages
one address between them, so only one of them can be linked to, shared on
WhatsApp or indexed — and for a shop whose customers search in Devanagari, the
invisible half would be the one that mattered. It also means the page a visitor
shares is the page their cousin opens, rather than whichever language that phone
last chose.

Each page carries a canonical of its own and an `hreflang` pointing at its
translation, including on the service pages: the switch on `/hi/services/
submersible-pumps` goes to the English *pump* page, not to the English home
page.

`lang/hi/site.php` is `lang/en/site.php` key for key.
`test_every_key_in_one_language_exists_in_the_other` fails the build when they
diverge — without it, a key added to English and forgotten in Hindi does not
error, it renders the literal string `site.faq.title` in the middle of a
heading.

**On the wording.** The trade here speaks Hindi with the technical vocabulary in
English — मोटर, वाइंडिंग, बेयरिंग, स्टार्टर, सबमर्सिबल. That is what is
written. Translating "winding" to कुंडलन would be correct Hindi and wrong for the
man standing at the counter, who has never called it that in his life.

Two typographic consequences, both in `site.css` and both scoped to
`:lang(hi)`:

- **Line height.** The display headings are set at 1.06–1.1, which is right for
  Latin capitals and wrong for a script that hangs matras above the line and
  conjuncts below it. At that leading the Hindi hero renders as two overlapping
  rows of ink. The override is **outside `@layer base`** on purpose: the leading
  comes from a Tailwind utility, utilities are a later layer, and a layered rule
  loses to a later layer whatever its specificity.
- **No uppercasing.** Devanagari has no case, so `text-transform: uppercase` on
  an eyebrow does nothing to the letters and only opens the tracking into gaps.

Inter carries no Devanagari at all. `Noto Sans Devanagari` is second in the font
stack and self-hosted alongside it; both are emitted with per-face
`unicode-range`, so a page with no Devanagari on it never fetches those files.

---

## Fonts, and a bug this fixed

`@vite` does **not** emit the font faces. The families downloaded at build time
by the `bunny()` plugin land in a second manifest (`public/build/fonts-manifest.json`),
and `{{ Vite::fonts([...]) }}` is what turns that into preload links and the
`@font-face` block.

That call was missing, so the site — and the application, which still does not
have it — rendered in whatever the device happened to have rather than in Inter.
It is one line in `resources/views/site/layout.blade.php`. **The application's
`layouts/app.blade.php` still lacks it**; adding it there would change the look
of every screen behind the sign-in, so it was left for a separate decision.

---

## The shape of the site

### The home page

One long page in bands that alternate ink and paper: the shop **speaks** on the
dark ones and **explains** on the light ones, so somebody scrolling fast can tell
which is which without reading a word.

Section order is the order the questions arrive in — what is it → what do you do
→ how do I tell you what I have → what will you do to it → what do I get back →
what can I buy → the numbers I came to look up → do you come to me → the things I
was going to ask → where are you.

There is no "about us", because nobody has ever searched for one.

### Service pages

Three of the six services have a page: rewinding, pump repairs and winding wire.
Those are the three somebody types into a search box by name, and a card cannot
answer "what actually happens to my motor" in forty words.

The other three do not, and **should not be given one to make the set tidy**. A
page that exists only to repeat its own card is a page a visitor clicks once and
never trusts again.

`config/shop.php` decides which, and the route's whitelist is built from the same
list — so a page cannot exist without the copy to fill it, and copy cannot be
written for a page nobody can reach. `/services/spares` is a 404 at the router.

### The nameplate guide

The most useful block on the site, and the one it is built around. A caller who
cannot say what their motor is cannot be quoted, and the whole conversation
becomes "bring it in" — which for somebody with a 20 HP mill motor on a trolley
is the entire problem. Get them to photograph the plate instead and the shop can
answer before anyone loads anything.

The plate is **drawn in HTML**, not photographed and not an SVG, because its
fields have to be individually highlightable: pointing at a row in the list
lights up the field on the plate, and that is what teaches somebody where to
look. A photograph could not do that, would be a picture of somebody else's
motor, and would not survive a phone screen.

Its labels stay in English in both languages. That is not an untranslated
string — Indian motor nameplates are printed in English, and a guide to reading
one has to show the words that are actually stamped on it.

The pairing is bound on `pointerenter` rather than `mouseenter`, and on `focus`
as well, so it works from a keyboard and on a touch screen. Without that it
would be inert on the device most of these visitors are holding.

### The reference tables

HP/kW against typical full-load current, SWG to millimetres, and the common
motor bearings. Standard engineering values, not claims about this shop, which is
why they are code rather than config — nobody edits them, and they are the same
in Charkhi Dadri as anywhere else.

They are on the site because a winder halfway through a job should not have to
hunt for them, and because a page that is useful to the trade gets read by the
trade. All three panels ship **rendered**; the script hides two of them on boot
to make them tabs. Doing it the other way round would leave a failed script
showing sixty rows of stacked table — and would hide the rows from a crawler.

---

## Nothing on this site is load-bearing JavaScript

The page is complete, readable and navigable with `site.js` never loading:

- every section is visible (a `<noscript>` block in the layout sees to it);
- the questions are real `<details>` that open on their own — which they must be,
  because those answers are also the FAQ structured data;
- all three reference tables are in the markup;
- every phone number and WhatsApp link is a plain `<a href>`.

That is the right shape for a page whose visitor is in a shed with one bar of
signal.

No third-party script runs on this site at all. The map is a search **link**, not
an embedded iframe, and the fonts are self-hosted — so no request leaves this
origin when the page loads.

---

## The sign-in modal

The ids and `data-` hooks in `site/partials/login-modal.blade.php` are an
**interface with `initLogin()` in `resources/js/app.js`**, not styling. Only the
wording is translated. Renaming one of them to match the site's `s-` prefix
would leave a dialog that opens, accepts a password and does nothing with it —
which is why `test_the_sign_in_dialog_keeps_the_hooks_app_js_binds_to` asserts
all fourteen of them.

`/login` remains a redirect to `/?login=1`, because that is where the whole
application sends somebody whose session has ended.

---

## Still to be filled in

Everything below is marked `TODO(shop)` in `config/shop.php` and is either
absent from the page or stated conditionally until somebody confirms it:

- the year the shop opened, the real warranty period, the honest turnaround, and
  the lifetime rewind count, if that is a number anybody knows;
- a real shop mailbox rather than a personal address;
- confirmation of the street address;
- the GSTIN, if invoices carry one;
- the makes actually stocked;
- a Google Maps listing URL, so reviews can be linked rather than invented.
