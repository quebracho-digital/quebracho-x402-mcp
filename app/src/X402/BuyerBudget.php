<?php

declare(strict_types=1);

namespace App\X402;

use Quebracho\X402\Budget;
use Quebracho\X402\Networks;

/**
 * Budget::fromUsd, plus one refusal: the public demo key (the web3.js docs
 * key, in app/.env on purpose) must never get a cap on a network that is not
 * a testnet, because anyone can sign with it.
 */
final class BuyerBudget
{
    private const PUBLIC_KEY = '0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318';

    public static function create(array $caps, array $perPayment, \Closure $used, string $key): Budget
    {
        if (0 === strcasecmp($key, self::PUBLIC_KEY)) {
            foreach (array_keys($caps) as $network) {
                if (!preg_match('/testnet|sepolia|fuji/i', Networks::get($network)['label'])) {
                    throw new \LogicException(\sprintf('X402_BUDGETS lists %s, a mainnet, but X402_BUYER_KEY is the public demo key. Set your own key in .env.local.', $network));
                }
            }
        }

        return Budget::fromUsd($caps, $perPayment, $used);
    }
}
