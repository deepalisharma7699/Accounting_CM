import { VENDOR, initCounterpartyPage } from './counterparty';

/**
 * Vendors — the counterparty screen, told to lead with the payable.
 *
 * Everything is in `counterparty.js`, which the Customers screen shares. The two
 * lists are views of one `parties` table filtered on role membership, so a
 * counterparty who is both appears on both — see the notes there.
 */
export default initCounterpartyPage(VENDOR);
