import { CUSTOMER, initCounterpartyPage } from './counterparty';

/**
 * Customers — the counterparty screen, told to lead with the receivable.
 *
 * Everything is in `counterparty.js`, which the Vendors screen shares. The two
 * lists are views of one `parties` table filtered on role membership, so a
 * counterparty who is both appears on both — see the notes there.
 */
export default initCounterpartyPage(CUSTOMER);
