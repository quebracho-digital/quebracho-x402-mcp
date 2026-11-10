<?php

declare(strict_types=1);

namespace App\X402;

use Quebracho\X402\Job\JobDesk as LibJobDesk;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Adapter: wraps the library's JobDesk and adapts Symfony-specific Pricing/SensitiveRules
 * to the library's closure-based constructor.
 */
final class JobDesk implements ResetInterface
{
    /** This request's role ('start' or 'result'), from payer(); NULL for a tool that is no job. */
    private ?string $role = null;

    public function __construct(
        public readonly JobDeposit $deposit,
        public readonly JobDelivery $delivery,
        private readonly Pricing $pricing,
        private readonly SensitiveRules $sensitiveRules,
        private readonly \Closure $settle,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(param: 'app.mcp_tools')] private readonly array $tools,
    ) {
        $jobs = self::extractJobs($tools);
        $validate = fn (array $params) => $pricing->validate($params);
        $sensitive = fn (array $params) => $sensitiveRules->marks($params['name'], $params['arguments'] ?? []);

        // Wrap the Facilitator to accept the optional $reference parameter the library passes.
        // The app's Facilitator only accepts 2 args, so we ignore $reference.
        $settler = fn (array $payment, array $req, ?\Closure $reference = null) => ($this->settle)($payment, $req);

        $this->desk = new LibJobDesk($this->deposit->lib, $this->delivery->lib, $settler, $jobs, $validate, $sensitive);
    }

    private LibJobDesk $desk;

    /** Delegate: library already calls pricing->validate then deposit->check internally. */
    public function validate(array $params): ?string
    {
        return $this->desk->validate($params);
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
        $receipt = $this->desk->settle($payment, $req, $reference);
        $moved = true === ($receipt['success'] ?? null);
        // A clear refusal: no transaction, and a reason that says no money can have moved.
        $nothing = !$moved && '' === ($receipt['transaction'] ?? '') && !\in_array($receipt['errorReason'] ?? null, \Quebracho\X402\Settlement::MAY_HAVE_MOVED, true);
        match ($this->role) {
            'start' => $this->deposit->afterSettle($nothing),
            'result' => $this->delivery->afterSettle($moved, $nothing),
            default => null,
        };

        return $receipt;
    }

    /** Delegate: library already handles sensitive rules internally. */
    public function sensitive(array $params): bool
    {
        return $this->desk->sensitive($params);
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

    private static function extractJobs(array $tools): array
    {
        $jobs = [];
        foreach ($tools as $name => $t) {
            $j = $t['job'] ?? null;
            if (null !== $j) {
                $jobs[$name] = ['role' => $j['role'], 'pairedWith' => $j['pairedWith'], 'price' => $j['price'] ?? null];
            }
        }

        return $jobs;
    }
}
