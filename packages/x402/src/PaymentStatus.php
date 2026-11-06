<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * A facilitator that can say what became of a settle by its paymentId (Circle's
 * GET /status). Apart from Facilitator on purpose: most facilitators have no such route.
 */
interface PaymentStatus
{
    /**
     * 'completed' with a transaction, 'failed' with a reason, or still pending.
     *
     * @throws FacilitatorException when the facilitator cannot be asked
     */
    public function status(string $paymentId, string $network): ?array;
}
