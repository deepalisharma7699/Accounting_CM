{{--
| Printing on this device — the markup half of components/device-printing.js.
|
| Which paper each document goes on, and whether a thermal printer is driven
| directly through the print agent. Per device and per browser; see
| resources/js/print-settings.js for why it is never a workshop setting.
--}}
<section class="surface form-card mt-4" data-device-printing>
    <h3 class="text-sm font-bold text-foreground">Printing on this device</h3>
    <p class="mt-1 text-[0.8125rem] text-muted-foreground">
        Saved in this browser only, so the counter and the office can each print on their own printer.
        A sale is handed over as an estimate; the GST invoice is printed when somebody asks for one.
    </p>

    <div class="mt-4 form-grid">
        <div>
            <label for="dp-estimate" class="field-label">Estimate paper</label>
            <select id="dp-estimate" class="field-input" data-dp-paper="estimate"></select>
        </div>

        <div>
            <label for="dp-invoice" class="field-label">GST invoice paper</label>
            <select id="dp-invoice" class="field-input" data-dp-paper="invoice"></select>
        </div>

        <div>
            <label for="dp-method" class="field-label">Thermal printing</label>
            <select id="dp-method" class="field-input" data-dp-method>
                <option value="dialog">Through the print dialog</option>
                <option value="agent">Direct, through the print agent</option>
            </select>
        </div>

        <div data-dp-agent-field>
            <label for="dp-agent" class="field-label">Print agent address</label>
            <input id="dp-agent" type="text" class="field-input font-mono" autocomplete="off" spellcheck="false"
                   data-dp-agent>
        </div>
    </div>

    <p class="mt-3 text-xs text-muted-foreground" data-dp-hint></p>

    <div class="mt-4 flex flex-wrap items-center justify-end gap-3">
        <button type="button" class="btn btn-secondary" data-dp-test>Test the printer</button>
        <button type="button" class="btn btn-primary" data-dp-save>Save for this device</button>
    </div>
</section>
