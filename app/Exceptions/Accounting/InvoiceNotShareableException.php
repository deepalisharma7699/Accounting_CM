<?php

namespace App\Exceptions\Accounting;

use App\Exceptions\ApiException;

/**
 * A document that must not be published as a public link.
 *
 * Refused rather than published-with-a-warning, because the act cannot be
 * partly done: once a URL exists it can be forwarded, and "we only meant to
 * show them the draft" is not something a link can be told afterwards.
 */
class InvoiceNotShareableException extends ApiException
{
    /**
     * Not a document the workshop issues to a customer.
     *
     * A purchase bill is the vendor's document under the vendor's numbering, and
     * a journal is not a document at all. See
     * {@see \App\Enums\TransactionType::isCustomerDocument()}.
     */
    public static function wrongType(int $transactionId, string $typeLabel): self
    {
        return new self(
            message: sprintf(
                'Only an invoice or a credit note can be shared with a customer, and #%d is a %s.',
                $transactionId,
                strtolower($typeLabel),
            ),
            status: 422,
            errorCode: 'INVOICE_NOT_SHAREABLE',
            details: ['transaction_id' => $transactionId, 'type' => $typeLabel],
        );
    }

    /**
     * A draft, or something already reversed.
     *
     * A draft has no document number, no priced lines and no tax — it has not
     * been through the posting engine, so there is literally no invoice to show.
     * A reversed one is worse than absent: it is a real document that the books
     * say did not happen, and a customer holding a link to it would go on
     * believing it stands.
     */
    public static function notPosted(int $transactionId, string $statusLabel): self
    {
        return new self(
            message: sprintf(
                'Transaction #%d is %s, so there is no invoice to share yet.',
                $transactionId,
                strtolower($statusLabel),
            ),
            status: 422,
            errorCode: 'INVOICE_NOT_POSTED',
            details: ['transaction_id' => $transactionId, 'status' => $statusLabel],
        );
    }
}
