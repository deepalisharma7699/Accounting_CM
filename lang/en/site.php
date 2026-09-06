<?php

/*
|--------------------------------------------------------------------------
| The public site — English
|--------------------------------------------------------------------------
|
| Every word the shopfront says. lang/hi/site.php is the same tree with the
| same keys, so the Hindi page is a translation of one site rather than a
| second site that drifts away from this one. If you add a key here, add it
| there; `SiteTranslationTest` fails the build when the two disagree.
|
| Facts do not live here. The address, the phone number, the opening hours and
| the figures on the trust strip come from config/shop.php, because they are
| the same in both languages and a number that has to be corrected twice is a
| number that will end up wrong in one place.
|
| The `:placeholders` below are filled by App\Support\Site from that config —
| `:town`, `:shop`, `:hp_min`, `:hp_max` and so on.
*/

return [

    /*
    |----------------------------------------------------------------------
    | What a search result and a shared link show
    |----------------------------------------------------------------------
    */
    'meta' => [
        'home_title' => ':shop — Motor rewinding & submersible pump repairs in :town',
        'home_description' => 'Electric motor rewinding, borewell and openwell pump repairs, new motors, bearings and copper winding wire in :town, Haryana. Rewound to the original winding data and load tested before it goes back.',
        'service_title' => ':title — :shop, :town',
    ],

    /*
    |----------------------------------------------------------------------
    | Chrome
    |----------------------------------------------------------------------
    */
    /*
    |----------------------------------------------------------------------
    | Place names, as they are written in this language
    |----------------------------------------------------------------------
    |
    | The town, district and state appear inside sentences — "everything
    | happens here in :town", ":district भर में ब्रेकडाउन कॉल" — and a Latin
    | name dropped into the middle of a Devanagari sentence reads as a page
    | that was translated by machine and not finished.
    |
    | So the spelling is a translation and lives here, while config/shop.php
    | keeps the canonical Latin form. That is not a second copy of a fact: the
    | JSON-LD address, the map link and the page title all still read the
    | config, because a search engine and a courier want "Charkhi Dadri".
    |
    | Blank falls back to the config value — see Site::replacements().
    */
    'places' => [
        'town' => '',
        'district' => '',
        'state' => '',
    ],

    'nav' => [
        'services' => 'What we do',
        'nameplate' => 'Nameplate guide',
        'process' => 'How it works',
        'counter' => 'On the counter',
        'area' => 'Where we come out',
        'faq' => 'Questions',
        'contact' => 'Visit',
        'menu_open' => 'Open menu',
        'menu_close' => 'Close menu',
    ],

    'actions' => [
        'whatsapp' => 'Send the nameplate',
        'whatsapp_long' => 'Send a nameplate photo on WhatsApp',
        'call' => 'Call :phone',
        'call_short' => 'Call',
        'directions' => 'Directions',
        'services' => 'What we take on',
        'read_more' => 'Read more',
        'back_home' => 'Back to the shop',
        'staff_login' => 'Staff login',
        'language' => 'Language',
    ],

    // The message a visitor's WhatsApp opens pre-filled. Kept short: it is
    // edited on a phone, one-handed, usually standing next to the motor.
    'whatsapp_message' => 'Hello :shop — I have a motor to look at. Here is a photo of the nameplate:',

    /*
    |----------------------------------------------------------------------
    | Hero
    |----------------------------------------------------------------------
    */
    'hero' => [
        'eyebrow' => 'Motor rewinding & pump repairs · :town, Haryana',
        'title_lead' => 'Your motor goes back to work',
        'title_accent' => 'with the readings to prove it',
        'body' => 'Single- and three-phase motors and borewell pumps, rewound on our own bench to the turns and gauge on the nameplate — then run on load, with the current on every phase written down and handed over with the motor.',
        'hint' => 'A clear photo of the nameplate is enough for a quote. No need to load anything onto a trolley first.',
        'diagram_title' => 'What comes apart on a rewind',
        'diagram_caption' => 'Every one of these is inspected, and what is worn is replaced rather than reused.',
        // The labels around the cutaway. Keys are parts, not positions: where
        // a label sits is a layout decision and lives in the partial.
        'callouts' => [
            'terminal_box' => 'Terminal box',
            'stator' => 'Stator winding',
            'rotor' => 'Rotor',
            'bearings' => 'Bearings',
            'nameplate' => 'Nameplate',
        ],
    ],

    /*
    | Labels for the figures on the trust strip.
    |
    | The numbers themselves are in config/shop.php, and a figure nobody has
    | confirmed is dropped along with its label — see App\Support\Site::stats().
    | So these may go a long time without being rendered at all, which is the
    | correct behaviour and not a bug to be worked around.
    */
    'stats' => [
        'years' => 'Years on the same road',
        'rewound' => 'Motors rewound',
        'turnaround' => 'Usual turnaround',
        'warranty' => 'Written warranty on a rewind',
    ],

    // The four short promises under the hero. These are commitments about how
    // the work is done, not figures — they stay true whatever config/shop.php
    // has been filled in with.
    'trust' => [
        ['title' => 'Quoted before it is opened', 'body' => 'You get the figure and the reason first. Nothing is stripped on the assumption you will agree to it.'],
        ['title' => 'Wound to the nameplate', 'body' => 'The old winding is counted out before it comes out, so the new one is the maker’s data and not whatever copper was nearest.'],
        ['title' => 'Load tested, and written down', 'body' => 'Run on load with the current recorded on all three phases. A copy of the readings goes home with the motor.'],
        ['title' => 'We come out to you', 'body' => 'Breakdowns on farms, flour mills and workshops across the district, looked at the same day where we can.'],
    ],

    /*
    |----------------------------------------------------------------------
    | The services
    |----------------------------------------------------------------------
    |
    | Keyed by the slugs in config/shop.php. `page` is only read for the three
    | that have a route of their own; the rest is what the card on the home
    | page shows.
    */
    'services' => [
        'heading' => 'What we do',
        'title' => 'Six trades, one bench',
        'intro' => 'All of it happens here in :town. Nothing is sent away to another shop, so nothing comes back a week later than we promised it.',

        'motor-rewinding' => [
            'title' => 'Motor rewinding',
            'tag' => ':hp_min – :hp_max HP · single & three phase',
            'summary' => 'Burnt-out motors stripped, rewound to the turns and gauge the maker specified, varnished and oven-cured. The old winding is counted before it comes out, so nothing is guessed at.',
            'page' => [
                'lead' => 'A rewind is not putting copper back into a stator. It is putting the same copper back — same turns, same gauge, same pitch, same connection — and then proving it with a meter.',
                'sections' => [
                    [
                        'title' => 'What we take on',
                        'body' => 'Single-phase and three-phase induction motors from :hp_min HP up to :hp_max HP: flour mill and chaff cutter motors, monoblock and openwell pump motors, compressor and lathe motors, fan and blower sets, and the three-phase motors on feed plants and cattle-feed mixers. Any make — the job is defined by the winding in front of us, not by the badge on the end shield.',
                    ],
                    [
                        'title' => 'What actually happens on the bench',
                        'body' => 'The winding is megged to earth and checked phase to phase before anything is touched, so the fault is known rather than assumed. Then the old coils are counted out — turns per coil, wire gauge, coil pitch, number of circuits, star or delta — and written on the job card. The stator is stripped, the slots cleaned back to bare lamination and re-lined with fresh insulation paper. New coils are wound to the recorded data, inserted, laced, connected and tested for continuity and balance. It is varnished, oven-cured, fitted with new bearings, and the rotor checked for balance and shaft wear.',
                    ],
                    [
                        'title' => 'What we will tell you not to rewind',
                        'body' => 'Not every burnt motor is worth the copper. If the laminations have burnt and lost their insulation the core will run hot whatever winding goes into it. If the shaft is scored at the bearing seat or the end shield housing has worn oval, the new bearings will not last the season. We say so and price a replacement instead, because a rewind that fails in month four costs you more than the one you did not buy.',
                    ],
                    [
                        'title' => 'How it is priced',
                        'body' => 'On the copper it takes and the labour to put it in — so it follows the HP, the pole count and the wire gauge, not a flat rate per motor. That is why the figure comes after we have opened the terminal box and megged it, and before anything is stripped.',
                    ],
                ],
                'specs_title' => 'What we handle',
                'specs' => [
                    ['label' => 'Output', 'value' => ':hp_min – :hp_max HP'],
                    ['label' => 'Supply', 'value' => 'Single phase 230 V · three phase 415 V'],
                    ['label' => 'Poles', 'value' => '2, 4, 6 and 8 pole (2880 / 1440 / 960 / 720 RPM)'],
                    ['label' => 'Insulation', 'value' => 'Class B, F and H'],
                    ['label' => 'Also rewound', 'value' => 'Openwell, monoblock, jet and self-priming pump motors'],
                ],
            ],
        ],

        'submersible-pumps' => [
            'title' => 'Submersible & openwell pumps',
            'tag' => 'Borewell V3 · V4 · V6 · openwell',
            'summary' => 'Borewell and openwell sets stripped, rewound, resealed and refilled, with the thrust bearing and mechanical seal replaced as a matter of course rather than as an extra.',
            'page' => [
                'lead' => 'A borewell pump fails in a place nobody can see, and it costs more to pull out than to repair. So the whole point is that it goes back down once.',
                'sections' => [
                    [
                        'title' => 'What comes back up the bore',
                        'body' => 'Most sets arrive for one of four reasons: the winding has gone to earth after water got past the cable entry or the seal; the thrust bearing has worn and the impellers are rubbing; the shaft has seized in the bush after running dry; or the impeller stack has worn and the discharge has quietly halved over two seasons while the current stayed normal. The last one is the one people live with for a year without knowing.',
                    ],
                    [
                        'title' => 'What we do with it',
                        'body' => 'The set is stripped to the stator, rotor, shaft, bush bearings, thrust pad and impeller stack. The winding is rewound in resin- or water-filled construction as the maker built it, the cable entry resealed, and the mechanical seal, thrust pad and bush bearings replaced. Impellers and diffusers are gauged for wear and changed where the clearance has gone. It is refilled, sealed, and its insulation checked wet — not dry on a bench, which tells you nothing about a motor that lives in water.',
                    ],
                    [
                        'title' => 'Tested against head, not just switched on',
                        'body' => 'Anything that spins looks fine on the workshop floor. A pump is tested against discharge and head, and the current recorded, so what goes back down the bore is known to lift what it is supposed to lift. If the bore itself has dropped and the set is now under-sized for the water level, that shows up here — and we would rather tell you that than have you blame the repair.',
                    ],
                    [
                        'title' => 'Panels and dry-run protection',
                        'body' => 'A large share of burnt submersibles were killed by their control panel, not by their motor. We check the starter rating against the pump’s full load current, and fit dry-run and single-phase preventers where there are none. It is a small part of the bill and it is what stops you paying for the same rewind next summer.',
                    ],
                ],
                'specs_title' => 'What we handle',
                'specs' => [
                    ['label' => 'Borewell', 'value' => 'V3, V4 and V6 submersible sets'],
                    ['label' => 'Openwell', 'value' => 'Single- and three-phase openwell submersibles'],
                    ['label' => 'Surface', 'value' => 'Monoblock, jet and self-priming pumps'],
                    ['label' => 'Replaced as standard', 'value' => 'Mechanical seal, thrust pad, bush bearings, cable gland'],
                    ['label' => 'Tested for', 'value' => 'Insulation, discharge, head and running current'],
                ],
            ],
        ],

        'winding-wire' => [
            'title' => 'Copper winding wire',
            'tag' => 'Enamelled copper · sold by weight',
            'summary' => 'Super-enamelled copper by SWG, plus insulation paper, sleeving, tape and varnish — the same stock we wind with ourselves, weighed out for winders across the district.',
            'page' => [
                'lead' => 'Winding wire is bought on two things: the gauge is what the label says, and the weight is what the scale says. Everything else is a discount you pay for later.',
                'sections' => [
                    [
                        'title' => 'What we sell',
                        'body' => 'Super-enamelled copper winding wire in the gauges a rewinding shop actually uses, wound on spools and cut to weight. Alongside it: slot insulation paper, fibreglass and cotton sleeving, polyester and cotton tape, lacing cord, terminal leads, and air-drying and baking varnish. If you are winding, it is on the counter.',
                    ],
                    [
                        'title' => 'Copper, not copper-clad',
                        'body' => 'We stock enamelled copper. Copper-clad aluminium is cheaper by weight and it is not the same wire: it carries less current for the same section, so a winding that used it runs hotter for the same load and reaches the insulation’s temperature limit sooner. It has its uses, and a motor that will be reloaded to its nameplate is not one of them. We will tell you which one you are buying.',
                    ],
                    [
                        'title' => 'Bought by the kilo, checked on the scale',
                        'body' => 'Wire is sold by weight and the price moves with the copper market, so we quote on the day rather than off a printed list. The scale is on the counter and you are welcome to watch it. Gauge is stated in SWG, which is what the trade here works in — the conversion to millimetres is on the reference table below if you need it for a datasheet.',
                    ],
                ],
                'specs_title' => 'On the shelf',
                'specs' => [
                    ['label' => 'Wire', 'value' => 'Super-enamelled copper, Class F and H'],
                    ['label' => 'Gauges', 'value' => 'The working range, 18 to 40 SWG'],
                    ['label' => 'Insulation', 'value' => 'Slot paper, fibreglass and cotton sleeving, polyester tape'],
                    ['label' => 'Finishing', 'value' => 'Air-drying and baking varnish, lacing cord, terminal leads'],
                    ['label' => 'Sold', 'value' => 'By weight, priced on the day'],
                ],
            ],
        ],

        'new-motors' => [
            'title' => 'New motors & pumps',
            'tag' => 'Sized against your load',
            'summary' => 'Induction motors, monoblocks and submersibles chosen against the head, the discharge and the supply you actually have — not against whatever is nearest on the shelf.',
        ],

        'spares' => [
            'title' => 'Spares & bearings',
            'tag' => 'Over the counter',
            'summary' => 'Bearings, capacitors, cooling fans, terminal blocks, starters, shaft sleeves, submersible cable and glands — the parts that decide whether a rewind lasts four years or four months.',
        ],

        'site-visits' => [
            'title' => 'Site visits & breakdown calls',
            'tag' => 'Across the district',
            'summary' => 'Farms, flour mills, feed plants and workshops. We come out, find whether it is the motor, the starter or the supply, and take away only what has to come away.',
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | The nameplate guide
    |----------------------------------------------------------------------
    |
    | The most useful block on the site: it is what turns a phone call that
    | goes nowhere into a photo we can quote from. The rows are the fields on
    | a real Indian motor plate, in the order they are usually printed.
    */
    'nameplate' => [
        'heading' => 'Before you call',
        'title' => 'Read the nameplate, and we can quote without you moving the motor',
        'intro' => 'Every induction motor carries a plate on the body, usually beside the terminal box. It has everything we need to price a rewind or match a replacement. Wipe the grease off it, take one square-on photo in good light, and send it on WhatsApp.',
        'plate_title' => 'What is on the plate',
        'plate_caption' => 'A typical three-phase plate. Yours will use some of these words and not others — send the photo and we will read it.',
        'rows' => [
            'output' => ['field' => 'kW / HP', 'means' => 'What the motor delivers at the shaft — not what it draws from the supply. 1 HP is 0.746 kW.', 'why' => 'Sets the copper and the price'],
            'volts' => ['field' => 'Volts', 'means' => '415 V across three phases, or 230 V single phase. Some plates show both a star and a delta voltage.', 'why' => 'Decides the connection'],
            'amps' => ['field' => 'Amps', 'means' => 'Full load current — what it should draw when it is working properly at rated load.', 'why' => 'What your starter must be set to'],
            'rpm' => ['field' => 'RPM', 'means' => 'Speed on load. 2880 is 2-pole, 1440 is 4-pole, 960 is 6-pole, 720 is 8-pole.', 'why' => 'Sets the pole count and the winding'],
            'phase' => ['field' => 'Phase / Hz', 'means' => '1 ph or 3 ph, and 50 Hz on any Indian supply.', 'why' => 'Decides the whole winding layout'],
            'frame' => ['field' => 'Frame', 'means' => 'The mounting dimensions — 80, 90L, 100L, 112M, 132S and so on. It is a size, not a model.', 'why' => 'How a replacement is matched'],
            'insulation' => ['field' => 'Ins. class', 'means' => 'B, F or H — the temperature the winding insulation is built to survive.', 'why' => 'Sets the wire and varnish we use'],
            'connection' => ['field' => 'Conn.', 'means' => 'Star or delta, and sometimes both with a changeover. Your starter has to agree with it.', 'why' => 'Wrong one burns the winding'],
            'ip' => ['field' => 'IP / encl.', 'means' => 'How well it is sealed against dust and water. IP55 on most farm and mill duty.', 'why' => 'Says where it can be mounted'],
            'serial' => ['field' => 'Serial / date', 'means' => 'The maker’s own reference and, on most plates, the year it was built.', 'why' => 'Tells us its age and history'],
        ],
        'unreadable_title' => 'Plate burnt off or missing?',
        'unreadable_body' => 'It happens on older motors, and it is not a problem. Bring it in: the winding data can be counted out of the stator as the old coils come off — turns, gauge, pitch and connection — and that is what we would have wound to anyway. Send a photo of the whole motor and the terminal box instead, and tell us what it was driving.',
    ],

    /*
    |----------------------------------------------------------------------
    | How it works
    |----------------------------------------------------------------------
    */
    'process' => [
        'heading' => 'How it works',
        'title' => 'From the trolley to the load test',
        'intro' => 'Five steps, and you are told where it is at the end of each one.',
        'steps' => [
            [
                'title' => 'Booked in',
                'body' => 'Nameplate, winding data and the fault as you describe it, written down against your name and number before a single bolt comes off.',
            ],
            [
                'title' => 'Tested',
                'body' => 'Insulation resistance to earth, continuity and balance across the phases, bearing play and shaft condition. That is what separates a rewind from a bearing job from a starter fault.',
            ],
            [
                'title' => 'Quoted',
                'body' => 'You get the figure, and what it is for, before anything is stripped. If we think it is not worth rewinding, that is the conversation we have here rather than after.',
            ],
            [
                'title' => 'Rewound',
                'body' => 'Old winding counted out and removed, slots cleaned and re-lined, new copper to the recorded turns and gauge, laced, connected, varnished and oven-cured. Bearings replaced, rotor and shaft checked.',
            ],
            [
                'title' => 'Load tested and handed back',
                'body' => 'Run on load. Current on all three phases, insulation resistance and running temperature recorded, and a copy of the readings goes home with the motor.',
            ],
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | The test report
    |----------------------------------------------------------------------
    |
    | A worked example, and labelled as one. The figures are what a healthy
    | 7.5 HP set actually reads, so it teaches a customer what to look for on
    | their own sheet — but it is not a claim about any particular job.
    */
    'report' => [
        'heading' => 'What you get back',
        'title' => 'A rewind is only as good as the test behind it',
        'body' => 'Any shop can put copper into a stator, and it will run when you switch it on. The difference shows up in month four. So every motor leaving here is run on load and measured, and the sheet is handed over with it — which is also what stops a warranty claim turning into an argument about what was measured.',
        'example_label' => 'Example report',
        'example_note' => 'A worked example of the sheet, not a specific job. Your motor’s figures will be its own.',
        'subject' => '7.5 HP · 3 phase · 4 pole · 1440 RPM',
        'passed' => 'Passed',
        'rows' => [
            ['label' => 'Insulation resistance to earth', 'value' => 'Above 200 MΩ'],
            ['label' => 'Winding resistance R / Y / B', 'value' => 'Balanced within 2%'],
            ['label' => 'Running current R / Y / B', 'value' => '10.4 / 10.6 / 10.5 A'],
            ['label' => 'No-load run', 'value' => '30 min, no temperature rise'],
            ['label' => 'Bearings', 'value' => 'Both replaced · 6205 ZZ'],
            ['label' => 'Rotor', 'value' => 'Balanced, shaft true'],
        ],
        'footnote' => 'Balanced current across the three phases is the figure to look at. If one phase reads high, the winding or the supply is not right — whatever the motor sounds like.',
    ],

    /*
    |----------------------------------------------------------------------
    | On the counter
    |----------------------------------------------------------------------
    */
    'counter' => [
        'heading' => 'On the counter',
        'title' => 'What you can walk in and buy',
        'intro' => 'Stock a working shop needs the same day, not on a three-day order.',
        'groups' => [
            'wire' => [
                'title' => 'Winding materials',
                'items' => ['Super-enamelled copper wire, 18–40 SWG', 'Slot insulation paper', 'Fibreglass and cotton sleeving', 'Polyester and cotton tape', 'Lacing cord and terminal leads', 'Air-drying and baking varnish'],
            ],
            'bearings' => [
                'title' => 'Bearings & mechanical',
                'items' => ['Ball bearings, 6200 and 6300 series, ZZ and 2RS', 'Mechanical seals and thrust pads', 'Shaft sleeves and circlips', 'Cooling fans and fan covers', 'Grease and bearing lock'],
            ],
            'electrical' => [
                'title' => 'Electrical',
                'items' => ['Run and start capacitors', 'DOL and star-delta starters', 'Single-phase preventers and dry-run relays', 'Terminal blocks and terminal boxes', 'Submersible flat cable and glands'],
            ],
            'machines' => [
                'title' => 'Motors & pumps',
                'items' => ['Single- and three-phase induction motors', 'Monoblock and self-priming pumps', 'Openwell submersibles', 'Borewell submersible sets, V3 / V4 / V6', 'Control panels'],
            ],
        ],
        'brands_title' => 'Makes we deal in',
        'brands_pending' => 'We stock and repair the makes commonly used across the district. Ask at the counter or on WhatsApp for what is in today — stock on a given make moves week to week and we would rather tell you than have you drive over.',
    ],

    /*
    |----------------------------------------------------------------------
    | Reference tables
    |----------------------------------------------------------------------
    |
    | Not marketing. These are the three conversions the trade looks up most,
    | and putting them on the site is the cheapest useful thing this page does.
    */
    'reference' => [
        'heading' => 'Reference',
        'title' => 'The three tables everyone looks up',
        'intro' => 'Kept here because a winder halfway through a job should not have to hunt for them. Figures are standard values — always work to the plate on the motor in front of you.',
        'rating' => [
            'title' => 'HP, kW and typical full load current',
            'note' => 'Current is indicative for a three-phase 415 V motor at about 0.85 power factor. The nameplate is what your starter is set from — not this table.',
            'columns' => ['HP', 'kW', 'Approx. FLC at 415 V, 3 ph'],
        ],
        'swg' => [
            'title' => 'SWG to millimetres',
            'note' => 'Standard Wire Gauge, bare copper diameter. Enamel adds roughly 0.02–0.05 mm depending on the grade.',
            'columns' => ['SWG', 'mm'],
        ],
        'bearings' => [
            'title' => 'Common motor bearings',
            'note' => 'ZZ is metal shielded, 2RS is rubber sealed. Bore is what you measure on the shaft.',
            'columns' => ['Bearing', 'Bore', 'Outer', 'Width'],
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Where we come out
    |----------------------------------------------------------------------
    */
    'area' => [
        'heading' => 'Where we come out',
        'title' => 'Breakdown calls across :district',
        'body' => 'If it is bolted to a mill, a feed plant or a borewell head, it is usually easier for us to come to it. A call-out means finding whether the fault is the motor, the starter or the supply — and taking away only what has to come away. Most of the district is inside an hour.',
        'towns_title' => 'Villages and towns we regularly get to',
        'towns_note' => 'Not on the list? Call anyway — it is a short district and this is not a boundary.',
    ],

    /*
    |----------------------------------------------------------------------
    | Questions
    |----------------------------------------------------------------------
    |
    | Also the source of the FAQPage JSON-LD, so the answers are written to
    | stand on their own out of context — a search result may show one without
    | the page around it.
    */
    'faq' => [
        'heading' => 'Questions',
        'title' => 'What people ask at the counter',
        'items' => [
            [
                'q' => 'Is it worth rewinding, or should I just buy a new motor?',
                'a' => 'Below about 3 HP a new motor is often close enough to the cost of a proper rewind that replacing it is the better buy, and we will say so. Above that, a rewind to the original data costs a fraction of a replacement and the frame, shaft and end shields are usually still sound. What settles it is the core: if the laminations have burnt, or the shaft is scored at the bearing seat, no winding will fix that — and we would rather tell you before we take your money than after.',
            ],
            [
                'q' => 'What does a rewind cost?',
                'a' => 'It is priced on the copper it takes and the labour to put it in, so it follows the HP, the pole count and the wire gauge rather than a flat rate per motor. Copper is also a traded metal, so the figure moves with the market. You get the price after we have opened the terminal box and tested the winding, and always before anything is stripped.',
            ],
            [
                'q' => 'Do you handle makes other than the ones you sell?',
                'a' => 'Yes. A rewind is done to the winding data in the motor in front of us, so the badge on the end shield does not change the job. We rewind whatever is brought in, including motors bought elsewhere and motors somebody else has already had a go at.',
            ],
            [
                'q' => 'My motor trips the starter the moment it runs. What is that?',
                'a' => 'Usually one of three things: the winding has gone to earth, the supply is single-phasing so the motor is trying to run on two phases, or the starter’s overload is set below what the motor actually draws. Testing the insulation to earth takes about ten minutes and tells you which one you have — bring the motor and the starter together if you can, because the fault is often in the one people leave at home.',
            ],
            [
                'q' => 'My submersible still runs but the water has dropped off. Is the motor going?',
                'a' => 'Often not. When the discharge falls but the current stays normal, the usual cause is worn impellers and diffusers rather than a failing winding — or the water level in the bore itself has dropped. Both are worth checking before anyone talks about a rewind, because pulling a set out is the expensive part of the job.',
            ],
            [
                'q' => 'What should I bring?',
                'a' => 'The motor, and the starter or control panel if you have one — a surprising share of burnt windings were killed by their panel, and testing them together saves you coming back. Send a photo of the nameplate on WhatsApp first and we can usually tell you the likely cost and time before you load anything.',
            ],
            [
                'q' => 'Can you come out to the site?',
                'a' => 'Yes — farms, flour mills, feed plants and workshops across :district. We come out, find whether the fault is the motor, the starter or the supply, and only bring back what has to come back to the bench.',
            ],
            [
                'q' => 'Do you give a proper bill?',
                'a' => 'Yes. A printed invoice with the parts, the labour and the tax shown separately, so it is something you can put in your books or claim against. Ask for it at the counter when you collect.',
            ],
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Visit
    |----------------------------------------------------------------------
    */
    'contact' => [
        'heading' => 'Visit the shop',
        'title' => 'Bring it in, or we will come to it',
        'body' => 'Walk in with the motor, send a photo of the nameplate on WhatsApp, or call and we will come out. Breakdowns are looked at the same day where we can.',
        'labels' => [
            'address' => 'Address',
            'phone' => 'Phone',
            'email' => 'Email',
            'hours' => 'Open',
            'gstin' => 'GSTIN',
        ],
        'cta_title' => 'Send us the nameplate',
        'cta_body' => 'A clear photo of the plate — HP, phase and RPM — is enough for us to quote a rewind or price a replacement before you load anything onto a trolley.',
        'cta_note' => 'Quotes are free, and nothing is opened up until you have agreed the figure.',
        'hours_note' => 'Open seven days. Ring ahead on a festival day.',
    ],

    'footer' => [
        'tagline' => 'Motor rewinding, submersible pump repairs and winding materials in :town, Haryana.',
        'rights' => '© :year :shop. All rights reserved.',
        'services_title' => 'What we do',
        'shop_title' => 'The shop',
        'staff_note' => 'Staff sign in to the workshop’s own books.',
    ],

    /*
    |----------------------------------------------------------------------
    | The way in
    |----------------------------------------------------------------------
    |
    | The sign-in modal. The ids it carries are bound by initLogin() in
    | resources/js/app.js — the wording is translated, the plumbing is not.
    */
    'login' => [
        'title' => 'Welcome back',
        'subtitle' => 'Sign in to your AI Accounting Back Office',
        'email' => 'Email address',
        'password' => 'Password',
        'submit' => 'Sign in',
        'close' => 'Close',
        'show_password' => 'Show password',
        'signup_prompt' => 'New here?',
        'signup_link' => 'Create your workshop',
        'staff_only' => 'For shop staff. Customers do not need an account.',

        /*
        | The passkey half.
        |
        | The button is only ever painted where the browser can actually
        | perform the ceremony, so this copy never appears as a promise the
        | device cannot keep. It names what the person will be asked for
        | rather than the technology: nobody at a counter knows what a passkey
        | is, and everybody knows what their fingerprint is.
        */
        'passkey' => 'Sign in with fingerprint or face',
        'passkey_busy' => 'Waiting for your device…',
        'passkey_hint' => 'Faster, and nothing to remember. Set it up from your account once you are in.',
        'or' => 'or use your password',
    ],
];
