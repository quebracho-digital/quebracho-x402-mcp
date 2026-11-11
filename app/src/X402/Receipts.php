<?php

declare(strict_types=1);

namespace App\X402;

/**
 * The x402 receipts the buyer got during this run, and the offers it
 * refused, for app:ask to show.
 *
 * PayingMcpClient's two callbacks (services.yaml): $onPaid is this object's __invoke, called with the tool,
 * the offer that was signed and the seller's receipt; $onRefused is refused(), called with an offer the
 * client did not pay and the reason (over budget, payTo not allowed, no scheme for that network...).
 */
final class Receipts
{
    /** @var list<array{tool: string, req: array, receipt: array}> */
    public array $all = [];

    /** @var list<array{req: array, reason: string}> offers not paid, and why */
    public array $refused = [];

    public function __invoke(string $tool, array $req, array $receipt): void
    {
        $this->all[] = ['tool' => $tool, 'req' => $req, 'receipt' => $receipt];
    }

    public function refused(array $req, string $reason): void
    {
        $this->refused[] = ['req' => $req, 'reason' => $reason];
    }
}
