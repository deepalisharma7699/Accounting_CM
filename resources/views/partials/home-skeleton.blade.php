{{--
    Home, while the session is still being fetched.

    ## The problem it fixes

    Every card on this page starts `hidden` and is revealed only once /auth/me
    confirms the grant behind it — which is right, and is what stops a clerk
    seeing a module flash past that they may not open. But it leaves the band
    headings on their own for the length of that round trip, so home opens as
    five labels over nothing at all. A heading with no cards under it does not
    read as "still loading"; it reads as "this workshop has no modules".

    ## Why the headings go too

    A heading is a claim about what is beneath it, and while the session is
    unknown none of them can be made: not which bands this reader gets, not
    whether there is a favourites row above them, not whether the grid comes out
    empty altogether (a platform admin's does). So `#view-home[data-home-loading]`
    hides everything but this, and this is one anonymous grid.

    ## Eight, and it is a shape rather than a count

    Nobody knows the real number until the grants arrive, so this does not
    pretend to: eight fills roughly the first screen at any width the grid lays
    out, and the cards that replace it are the same size, so the page settles
    instead of jumping. `aria-hidden` because there is nothing here to read —
    `#view-home` carries `aria-busy` while this is up, which is the part a
    screen reader needs.
--}}
<div data-home-skeleton aria-hidden="true">
    <span class="skel mb-2 h-3.5 w-40"></span>

    <div class="card-grid">
        @for ($i = 0; $i < 8; $i++)
            {{-- The card's own shape: a band carrying a chip and a name, the
                 line beneath it, and the corner the arrow sits in. --}}
            <div class="skel-card">
                <span class="skel-band">
                    <span class="skel h-8 w-8 rounded-lg"></span>
                    <span class="skel h-3.5 w-1/2"></span>
                </span>

                <span class="skel-body">
                    <span class="skel w-full"></span>
                    <span class="skel mt-1.5 w-4/5"></span>
                    <span class="skel mt-auto w-10"></span>
                </span>
            </div>
        @endfor
    </div>
</div>
