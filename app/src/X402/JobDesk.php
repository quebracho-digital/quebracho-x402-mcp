<?php

declare(strict_types=1);

namespace App\X402;

use Quebracho\X402\Settlement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Where job mode meets the payment: the hooks X402McpMiddleware calls (config/services.yaml), routed by the
 * tool's #[PaidJob] role. A job is bought in two payments, and each has its own class:
 *
 *   `_start`   the deposit, settled BEFORE the tool runs: JobDeposit
 *   `_result`  the rest, settled AFTER the tool answered: JobDelivery (also `_status`, which is free)
 *
 * The order the middleware calls the hooks in, for one tools/call:
 *
 *   validate()   before any payment is read: the tool's schema (Pricing), then a `_start`'s work check
 *   payer()      once the signature proved who pays, before the claim: the role's own refusal, or NULL
 *   sensitive()  whether the ledger may keep the answer
 *   settle()     around the facilitator's settle: the role's beforeSettle(), the facilitator, its afterSettle()
 *
 * then, after the response went out, afterResponse() (App\EventListener\JobTerminate, kernel.terminate).
 * A tool without a #[PaidJob] role goes straight through: its schema, the rules, the facilitator.
 *
 * One instance per request, like its two parts: the role is this request's call, set by payer().
 */
final class JobDesk implements ResetInterface
{
    /** This request's role ('start' or 'result'), from payer(); NULL for a tool that is no job. */
    private ?string $role = null;

    /**
     * @param \Closure                                                                                            $settle the facilitator's settle (App\X402\Facilitator)
     * @param array<string, array{job: ?array{role: string, pairedWith: string, price: ?string}, schema: string}> $tools
     */
    public function __construct(
        public readonly JobDeposit $deposit,
        public readonly JobDelivery $delivery,
        private readonly Pricing $pricing,
        private readonly SensitiveRules $sensitiveRules,
        private readonly \Closure $settle,
        #[Autowire(param: 'app.mcp_tools')] private readonly array $tools,
    ) {
    }

    /**
     * X402McpMiddleware's $validate: the schema (Pricing), then for a `_start` its work's own check.
     */
    public function validate(array $params): ?string
    {
        $invalid = $this->pricing->validate($params);

        return null === $invalid && 'start' === $this->role($params['name']) ? $this->deposit->check($params) : $invalid;
    }

    /**
     * X402McpMiddleware's $payer: before the claim, why $from cannot buy this call, or NULL. Never throws.
     */
    public function payer(array $params, string $from): ?string
    {
        $this->role = $this->role($params['name']);

        return match ($this->role) {
            'start' => $this->deposit->payer($params, $from),
            'result' => $this->delivery->payer($params, $from),
            default => null,
        };
    }

    /**
     * X402McpMiddleware's $settle, around the facilitator's. Never throws after the facilitator answered:
     * Settlement::run would turn the throw into a failed receipt, and withhold an answer that was paid for.
     */
    public function settle(array $payment, array $req, ?\Closure $reference = null): array
    {
        $instead = match ($this->role) {
            'start' => $this->deposit->beforeSettle($payment, $req),
            'result' => $this->delivery->beforeSettle($req),
            default => null,
        };
        if (null !== $instead) {
            return $instead;
        }
        $receipt = null === $reference ? ($this->settle)($payment, $req) : ($this->settle)($payment, $req, $reference);
        $moved = true === ($receipt['success'] ?? null);
        // A clear refusal: no transaction, and a reason that says no money can have moved.
        $nothing = !$moved && '' === ($receipt['transaction'] ?? '') && !\in_array($receipt['errorReason'] ?? null, Settlement::MAY_HAVE_MOVED, true);
        match ($this->role) {
            'start' => $this->deposit->afterSettle($nothing),
            'result' => $this->delivery->afterSettle($moved, $nothing),
            default => null,
        };

        return $receipt;
    }

    /**
     * X402McpMiddleware's $sensitive: whether the ledger must not keep this call's answer.
     */
    public function sensitive(array $params): bool
    {
        return match ($this->role($params['name'])) {
            // A job id, no buyer text: always kept, so a retry of the same deposit gets the same job (7c)
            // instead of already_settled. A sensitive deposit marks its job instead (see the result).
            'start' => false,
            'result' => $this->delivery->sensitive($params),
            default => $this->sensitiveRules->marks($params['name'], $params['arguments'] ?? []),
        };
    }

    /**
     * kernel.terminate, after the response went out (Response::send calls fastcgi_finish_request): a
     * `_result` never settled is ready again, a queued `_start` runs.
     */
    public function afterResponse(): void
    {
        try {
            $this->delivery->afterResponse();
            $this->deposit->afterResponse();
        } finally {
            $this->reset();
        }
    }

    public function reset(): void
    {
        $this->role = null;
        $this->deposit->reset();
        $this->delivery->reset();
    }

    private function role(string $name): ?string
    {
        return $this->tools[$name]['job']['role'] ?? null;
    }
}
