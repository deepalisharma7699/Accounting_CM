{{--
| The invoice as the customer sees it — M20.
|
| The markup half of `components/invoice-document.js`, and the same extraction
| the bill document is: every value in here is painted by that one file, from the
| one array `InvoiceDocumentService` builds. Two hosts include this partial and
| neither may copy it —
|
|   layouts/app.blade.php   the workshop's print copy, hidden until Print is
|                           pressed and then the only thing on the page.
|   invoices/public         the customer's copy at /i/{token}.
|
| The copy the workshop keeps and the copy the customer opens are the same
| document. That is not a nicety: a difference between them is a dispute, and a
| dispute about an invoice is one nobody can settle from either copy. One
| partial and one renderer is how they are kept identical, structurally, rather
| than by remembering to change both.
|
| ## Everything is a host
|
| Unlike the bill document, almost nothing here is static markup. An invoice's
| shape depends on what is on it: IGST replaces CGST + SGST, the discount column
| exists only if something was discounted, the round-off line only if the
| workshop rounds. Painting the sections wholesale is what keeps those decisions
| in one file instead of scattered through `hidden` toggles.
--}}

<article data-invoice-document class="invoice-sheet" aria-label="Invoice">

    {{-- Who issued it, and what it is. --}}
    <header class="invoice-band" data-invoice-head></header>

    {{-- Who it is for, and where the supply took place. --}}
    <section class="invoice-parties" data-invoice-parties></section>

    {{-- What was sold. Scrolls inside itself on a narrow phone rather than
         making the whole sheet scroll sideways (§7.3). --}}
    <div class="invoice-lines-scroll">
        <table class="invoice-lines">
            <thead data-invoice-columns></thead>
            <tbody data-invoice-rows></tbody>
        </table>
    </div>

    {{-- What it comes to. The notes sit beside the totals rather than under
         them, because on a printed page the right-hand column is where an eye
         goes for a figure and the left is dead space. --}}
    <section class="invoice-close">
        <div class="invoice-close-left" data-invoice-aside></div>
        <dl class="invoice-totals" data-invoice-totals></dl>
    </section>

    {{-- The amount in words, which is the figure a digit cannot be added to. --}}
    <p class="invoice-words" data-invoice-words></p>

    {{-- What has been paid, and what is left. Absent on a credit note, which
         settles nothing on its own. --}}
    <section data-invoice-settlement></section>

    <footer class="invoice-foot" data-invoice-foot></footer>

</article>
