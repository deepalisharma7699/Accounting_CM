import { $, esc, showActionError, toast } from '../ui';
import { agentPrint, agentStatus } from './print-agent';
import { testReceiptBytes } from './receipt-escpos';
import {
    DEFAULT_AGENT_URL, PAPERS, isThermal, printSettings, savePrintSettings,
} from '../print-settings';

/**
 * "Printing on this device" — the Settings card's section for which paper each
 * document goes on, and whether the thermal printer is driven directly.
 *
 * The markup is `partials/device-printing.blade.php`. Nothing here reaches the
 * server: the choices are this browser's (see print-settings.js), so there is no
 * request to wait on and no grant to check.
 *
 * Everything is scoped to the section node, which the shell may hold detached.
 */
export function mountDevicePrinting(section) {
    if (!section) return;

    const papers = PAPERS.map((paper) => `<option value="${esc(paper.value)}">${esc(paper.label)}</option>`).join('');
    const field = (hook) => $(hook, section);

    field('[data-dp-paper="estimate"]').innerHTML = papers;
    field('[data-dp-paper="invoice"]').innerHTML = papers;

    const read = () => ({
        estimate: field('[data-dp-paper="estimate"]').value,
        invoice: field('[data-dp-paper="invoice"]').value,
        method: field('[data-dp-method]').value,
        agentUrl: field('[data-dp-agent]').value.trim().replace(/\/+$/, '') || DEFAULT_AGENT_URL,
    });

    /**
     * Only what applies to the choices on screen: the agent's address and its
     * test are about direct printing, and the hint is about whichever way the
     * thermal roll is being driven.
     */
    const repaint = () => {
        const choices = read();
        const thermal = isThermal(choices.estimate) || isThermal(choices.invoice);
        const direct = choices.method === 'agent';

        field('[data-dp-agent-field]').classList.toggle('hidden', !direct);
        field('[data-dp-test]').classList.toggle('hidden', !direct);

        field('[data-dp-hint]').textContent = !thermal
            ? 'Both documents print on A4 through the print dialog.'
            : direct
                ? 'Thermal paper is sent straight to the printer through the print agent, and cut. If the agent '
                    + 'does not answer, the print dialog opens instead. A4 always uses the dialog.'
                : 'Thermal paper goes through the print dialog. In the dialog choose the receipt printer, set '
                    + 'Margins to None, and set the paper size to the roll in the printer’s own settings.';
    };

    const settings = printSettings();

    field('[data-dp-paper="estimate"]').value = settings.estimate;
    field('[data-dp-paper="invoice"]').value = settings.invoice;
    field('[data-dp-method]').value = settings.method;
    field('[data-dp-agent]').value = settings.agentUrl;
    field('[data-dp-agent]').placeholder = DEFAULT_AGENT_URL;

    repaint();

    section.addEventListener('change', repaint);

    field('[data-dp-save]').addEventListener('click', (event) => {
        if (savePrintSettings(read())) {
            toast('Saved for this device.');
        } else {
            showActionError(event.currentTarget,
                'This browser is not keeping settings — a private window, or site data is blocked. '
                + 'Printing stays on A4 through the dialog.');
        }
    });

    /*
    | Proves the whole path with the settings on screen, saved or not: the agent
    | is running, it is pointed at the right printer, the roll is the width
    | chosen and the cutter works. Busy while it goes (§3.4) — the agent has no
    | place in the global activity bar, which is raised by the application's own
    | requests.
    */
    field('[data-dp-test]').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const choices = read();
        const roll = isThermal(choices.estimate) ? choices.estimate : (isThermal(choices.invoice) ? choices.invoice : '80');
        const idle = button.textContent;

        button.disabled = true;
        button.textContent = 'Testing…';

        try {
            const status = await agentStatus(choices.agentUrl);

            await agentPrint(choices.agentUrl, testReceiptBytes(roll));

            toast(`Test slip sent to ${status.printer ?? 'the printer'}.`);
        } catch (error) {
            showActionError(button, error.message);
        } finally {
            button.disabled = false;
            button.textContent = idle;
        }
    });
}
