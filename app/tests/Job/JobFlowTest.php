<?php

declare(strict_types=1);

namespace App\Tests\Job;

use App\Job\FileJobStore;
use App\Job\Job;
use App\Job\JobFailed;
use App\Job\JobRunner;
use App\Job\JobWork;
use App\Mcp\AnalyzeTermsJobTool;
use App\Mcp\WisdomTool;
use App\X402\JobDesk;
use App\X402\JobSlots;
use App\X402\PaidJob;
use App\X402\Pricing;
use App\X402\SensitiveRules;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quebracho\X402\EvmExact;
use Quebracho\X402\FileLedger;
use Quebracho\X402\Settlement;
use Quebracho\X402\Signer;
use Quebracho\X402\X402McpMiddleware;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Job mode end to end, through the real X402McpMiddleware (EIP-3009 signatures checked for real, a file
 * ledger) and the real job tools, with JobDesk's hooks wired as in config/services.yaml. Only the
 * facilitator and the work are fakes: nothing leaves the host. Each request() is one HTTP request,
 * kernel.terminate included.
 */
final class JobFlowTest extends TestCase
{
    private const PAY_TO = '0x7e510E6B4c3E2121CB64CD0686a4f241Df326510';
    private const BUYER = '0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318';
    private const BUYER_ADDRESS = '0x2c7536E3605D9C16a7a3D7b1898e529396a65c23';
    private const OTHER = '0x59c6995e998f97a5a0044966f0945389dc9e86dae88c7a8412f4603b6b78690d';
    private const TEXT = 'We may suspend your account at any time.';

    private string $dir;
    private FileJobStore $jobs;
    private FileLedger $ledger;
    private Pricing $pricing;
    private JobSlots $slots;
    private JobDesk $desk;
    private \Closure $facilitator;
    /** @var list<array{payment: array, req: array}> what the facilitator was asked to settle */
    private array $settled = [];
    /** @var list<array> the receipts the facilitator answers with, in turn; success when it runs out */
    private array $receipts = [];
    /** @var ?\Closure(array): void sees each payment as the facilitator receives it */
    private ?\Closure $onSettle = null;
    /** @var ?\Closure(): array the work; default: one category found */
    private ?\Closure $work = null;
    private ?string $refuse = null;
    private bool $crashTheTool = false;
    private int $workRuns = 0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/job-flow-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        $this->jobs = new FileJobStore($this->dir.'/jobs');
        $this->ledger = new FileLedger($this->dir.'/ledger');
        $tools = PaidJob::catalog([[AnalyzeTermsJobTool::class, 'start'], [AnalyzeTermsJobTool::class, 'result'], [AnalyzeTermsJobTool::class, 'status'], [WisdomTool::class, '__invoke']]);
        $this->pricing = new Pricing(['wisdom'], 'eip155:84532', '0.01', self::PAY_TO, tools: $tools);
        $this->slots = new JobSlots(new LockFactory(new FlockStore($this->dir)), 1);
        $this->facilitator = function (array $payment, array $req): array {
            $this->onSettle && ($this->onSettle)($payment);
            $this->settled[] = ['payment' => $payment, 'req' => $req];

            return array_shift($this->receipts) ?? Settlement::mock()($payment, $req);
        };
        $this->desk = $this->desk($tools);
    }

    protected function tearDown(): void
    {
        unset($this->desk);
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function desk(?array $tools = null): JobDesk
    {
        $flow = $this;
        $work = new class($flow) implements JobWork {
            public function __construct(private readonly JobFlowTest $flow)
            {
            }

            public static function start(): string
            {
                return AnalyzeTermsJobTool::START;
            }

            public function check(array $args): ?string
            {
                return '' === trim((string) ($args['text'] ?? '')) ? 'Send some text.' : null;
            }

            public function refuse(): ?string
            {
                return $this->flow->refusal();
            }

            public function run(array $args): array
            {
                return $this->flow->runWork($args);
            }
        };
        $tools ??= PaidJob::catalog([[AnalyzeTermsJobTool::class, 'start'], [AnalyzeTermsJobTool::class, 'result'], [AnalyzeTermsJobTool::class, 'status'], [WisdomTool::class, '__invoke']]);

        return new JobDesk($this->jobs, new JobRunner($this->jobs, [$work]), $this->slots, $this->pricing, new SensitiveRules([], [['kind' => 'argument', 'name' => 'sensitive', 'value' => true]]), $this->facilitator, $tools);
    }

    /** @internal for the fake work */
    public function refusal(): ?string
    {
        return $this->refuse;
    }

    /** @internal for the fake work */
    public function runWork(array $args): array
    {
        ++$this->workRuns;
        if (null !== $this->work) {
            return ($this->work)($args);
        }

        return ['model' => 'fake', 'categories' => ['unilateral_termination' => [self::TEXT]], 'quotes_dropped' => 0];
    }

    private function middleware(): X402McpMiddleware
    {
        $f = new Psr17Factory();

        return new X402McpMiddleware([new EvmExact()], $this->pricing->__invoke(...), static fn () => true, $f, $f, $this->desk->settle(...),
            validate: $this->desk->validate(...), payer: $this->desk->payer(...), ledger: $this->ledger, sensitive: $this->desk->sensitive(...));
    }

    /** The MCP server: the job tools, called as the SDK calls them (a RequestContext with the call's _meta). */
    private function server(): RequestHandlerInterface
    {
        return new class(new AnalyzeTermsJobTool($this->desk, $this->jobs), $this->crashTheTool) implements RequestHandlerInterface {
            public function __construct(private readonly AnalyzeTermsJobTool $tool, private readonly bool $crash)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $message = json_decode((string) $request->getBody(), true);
                $args = $message['params']['arguments'];
                if ($this->crash) {
                    // The PHP process dies here: after the deposit settled, before the tool wrote anything.
                    throw new \Error('worker killed');
                }
                try {
                    $text = match ($message['params']['name']) {
                        AnalyzeTermsJobTool::START => $this->tool->start($args['text'], new RequestContext(new Session(new InMemorySessionStore()), CallToolRequest::fromArray($message)), $args['sensitive'] ?? false),
                        AnalyzeTermsJobTool::RESULT => $this->tool->result($args['jobId']),
                        AnalyzeTermsJobTool::STATUS => $this->tool->status($args['jobId'], $args['payer']),
                    };
                    $result = ['content' => [['type' => 'text', 'text' => $text]]];
                } catch (ToolCallException $e) {
                    $result = ['isError' => true, 'content' => [['type' => 'text', 'text' => $e->getMessage()]]];
                }

                return new Response(200, ['Content-Type' => 'application/json'], json_encode(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result], \JSON_THROW_ON_ERROR));
            }
        };
    }

    /** One HTTP request to /mcp, then kernel.terminate (the runner): the tool's JSON-RPC result. */
    private function request(string $tool, array $args, ?array $payment = null): array
    {
        $params = ['name' => $tool, 'arguments' => $args];
        if (null !== $payment) {
            $params['_meta'][X402McpMiddleware::PAYMENT] = $payment;
        }
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => $params], \JSON_THROW_ON_ERROR);
        try {
            $out = json_decode((string) $this->middleware()->process(new ServerRequest('POST', 'https://example.test/mcp', ['Content-Type' => 'application/json'], $body), $this->server())->getBody(), true);
        } finally {
            $this->desk->afterResponse();
        }

        return $out['result'] ?? ['error' => $out['error']];
    }

    /** The buyer signs the offer the unpaid call returns. */
    private function sign(string $tool, array $args, string $key = self::BUYER): array
    {
        $required = $this->request($tool, $args)['structuredContent'];

        return (new EvmExact(new Signer($key)))->pay($required, $required['accepts'][0]);
    }

    private static function answer(array $result): array
    {
        return json_decode($result['content'][0]['text'], true);
    }

    /** @return array{0: array, 1: array} the `_start` answer and the deposit */
    private function start(array $args = ['text' => self::TEXT]): array
    {
        $deposit = $this->sign(AnalyzeTermsJobTool::START, $args);

        return [$this->request(AnalyzeTermsJobTool::START, $args, $deposit), $deposit];
    }

    private function jobStatus(string $jobId, string $payer = self::BUYER_ADDRESS): array
    {
        return self::answer($this->request(AnalyzeTermsJobTool::STATUS, ['jobId' => $jobId, 'payer' => $payer]));
    }

    public function testAJobPaidInTwoParts(): void
    {
        [$started, $deposit] = $this->start();
        self::assertTrue($started['_meta'][X402McpMiddleware::PAYMENT_RESPONSE]['success'], 'the deposit settled before the answer');
        $jobId = self::answer($started)['jobId'];
        self::assertSame('queued', self::answer($started)['state']);
        self::assertSame(1, $this->workRuns, 'kernel.terminate ran it');

        self::assertSame(['jobId' => $jobId, 'state' => 'ready', 'reason' => 'result_ready', 'retryable' => false, 'retryAfter' => null], $this->jobStatus($jobId));
        $job = $this->jobs->get($jobId);
        self::assertNull($job?->params, 'the buyer text left the disk once the work ran');
        self::assertSame('upfront', $this->settled[0]['req']['extra']['paymentFlow']);

        $result = $this->request(AnalyzeTermsJobTool::RESULT, ['jobId' => $jobId], $this->sign(AnalyzeTermsJobTool::RESULT, ['jobId' => $jobId]));
        self::assertTrue($result['_meta'][X402McpMiddleware::PAYMENT_RESPONSE]['success']);
        self::assertSame([self::TEXT], self::answer($result)['categories']['unilateral_termination']);
        self::assertCount(2, $this->settled, 'a deposit and the rest');
        self::assertSame('delivered', $this->jobStatus($jobId)['state']);
        self::assertNull($this->jobs->get($jobId)?->result, 'delivered: the result left the job too');

        // The same deposit again: the same job, never a second one nor a duplicate_settlement (7c).
        self::assertSame($jobId, self::answer($this->request(AnalyzeTermsJobTool::START, ['text' => self::TEXT], $deposit))['jobId']);
        self::assertCount(1, iterator_to_array($this->jobs->all(), false));
        self::assertCount(2, $this->settled);
    }

    public function testTheJobRowIsWrittenBeforeTheDepositSettles(): void
    {
        $seen = null;
        $this->onSettle = function (array $payment) use (&$seen): void {
            $seen = $this->jobs->bySettlement(Settlement::key($payment));
        };
        $this->start();

        self::assertSame(Job::SETTLING, $seen?->state, 'the facilitator was asked only once the row existed');
        self::assertSame(['text' => self::TEXT], $seen->params, 'with what the job needs to resume');
        self::assertTrue($seen->paidBy(self::BUYER_ADDRESS));
    }

    public function testACrashBetweenTheSettleAndTheQueuedRowLeavesTheRowAndTheRetryGetsThatJob(): void
    {
        $this->crashTheTool = true;
        $deposit = $this->sign(AnalyzeTermsJobTool::START, ['text' => self::TEXT]);
        try {
            $this->request(AnalyzeTermsJobTool::START, ['text' => self::TEXT], $deposit);
            self::fail('the worker was meant to die');
        } catch (\Error) {
        }

        // What the reconciler finds: the deposit's row, still settling, by its key; and nothing ran.
        $row = $this->jobs->bySettlement(Settlement::key($deposit));
        self::assertSame(Job::SETTLING, $row?->state);
        self::assertSame(0, $this->workRuns, 'no job runs until its settle is confirmed');
        self::assertCount(1, $this->settled);

        // A new process; the buyer retries with the same payment: no second settle, the same job, now queued and run.
        $this->crashTheTool = false;
        $this->desk = $this->desk();
        $retry = self::answer($this->request(AnalyzeTermsJobTool::START, ['text' => self::TEXT], $deposit));
        self::assertSame($row->id, $retry['jobId']);
        self::assertCount(1, $this->settled, 'the deposit settled once');
        self::assertSame(1, $this->workRuns);
        self::assertSame(Job::READY, $this->jobs->get($row->id)?->state);
    }

    public function testAClearlyRefusedDepositLeavesNoJobAndTheSamePaymentMayBeRetried(): void
    {
        $this->receipts = [['success' => false, 'errorReason' => 'insufficient_funds', 'transaction' => '']];
        $deposit = $this->sign(AnalyzeTermsJobTool::START, ['text' => self::TEXT]);
        $refused = $this->request(AnalyzeTermsJobTool::START, ['text' => self::TEXT], $deposit);

        self::assertSame('insufficient_funds', $refused['structuredContent']['error']);
        self::assertSame([], iterator_to_array($this->jobs->all(), false), 'no row, and no buyer text on disk');

        $retry = $this->request(AnalyzeTermsJobTool::START, ['text' => self::TEXT], $deposit);
        self::assertSame('queued', self::answer($retry)['state']);
    }

    public function testAPendingDepositKeepsItsSettlingRowAndRunsNothing(): void
    {
        $this->receipts = [['success' => false, 'errorReason' => 'settlement_pending', 'transaction' => '0x'.str_repeat('ab', 32), 'network' => 'eip155:84532']];
        [$started, $deposit] = $this->start();

        self::assertSame('settlement_pending', $started['structuredContent']['error']);
        self::assertSame(Job::SETTLING, $this->jobs->bySettlement(Settlement::key($deposit))?->state, 'the money may move: the reconciler decides');
        self::assertSame(0, $this->workRuns);
    }

    public function testAJobRowThatCannotBeWrittenSendsNothing(): void
    {
        // The job directory is a file: no row can be written, so no settle may be sent.
        touch($this->dir.'/jobs');
        [$refused] = $this->start();

        self::assertSame('jobs_unavailable', $refused['structuredContent']['error']);
        self::assertSame([], $this->settled);
    }

    public function testABusyServerRefusesTheDepositBeforeItSettles(): void
    {
        $held = $this->slots->take();
        self::assertNotNull($held, 'JOB_SLOTS=1, taken by another job');
        [$refused] = $this->start();

        self::assertSame('job_slots_busy', $refused['structuredContent']['error']);
        self::assertSame([], $this->settled);
        self::assertSame([], iterator_to_array($this->jobs->all(), false));

        $held->release();
        [$started] = $this->start();
        self::assertSame('queued', self::answer($started)['state'], 'the slot is free again');
        self::assertNotNull($this->slots->take(), 'and freed once the job ran');
    }

    public function testOurKnownDailyCapRefusesTheDepositBeforeItSettles(): void
    {
        $this->refuse = 'our_daily_cap';
        [$refused] = $this->start();

        self::assertSame('our_daily_cap', $refused['structuredContent']['error']);
        self::assertSame([], $this->settled);
    }

    public function testATextTheWorkWouldRefuseIsNeverPaidFor(): void
    {
        $refused = $this->request(AnalyzeTermsJobTool::START, ['text' => '  ']);

        self::assertSame([-32602, 'invalid_params'], [$refused['error']['code'], $refused['error']['data']['reason']]);
        self::assertSame('Send some text.', $refused['error']['message']);
    }

    public function testOnlyTheDepositsPayerCanBuyTheResultAndOthersLearnNothing(): void
    {
        $jobId = self::answer($this->start()[0])['jobId'];
        $other = $this->request(AnalyzeTermsJobTool::RESULT, ['jobId' => $jobId], $this->sign(AnalyzeTermsJobTool::RESULT, ['jobId' => $jobId], self::OTHER));
        $none = $this->request(AnalyzeTermsJobTool::RESULT, ['jobId' => str_repeat('0', 32)], $this->sign(AnalyzeTermsJobTool::RESULT, ['jobId' => str_repeat('0', 32)]));

        self::assertSame('unknown_job', $other['structuredContent']['error']);
        self::assertSame('unknown_job', $none['structuredContent']['error']);
        self::assertCount(1, $this->settled, 'neither was charged');
        self::assertSame(Job::READY, $this->jobs->get($jobId)?->state);

        // The free status: a wrong payer gets exactly what an unknown id gets.
        $wrong = $this->jobStatus($jobId, '0x0000000000000000000000000000000000000001');
        $unknown = $this->jobStatus(str_repeat('0', 32));
        self::assertSame(['state' => 'unknown', 'reason' => 'unknown_job'], ['state' => $wrong['state'], 'reason' => $wrong['reason']]);
        self::assertSame(array_diff_key($unknown, ['jobId' => 1]), array_diff_key($wrong, ['jobId' => 1]));
        self::assertSame('ready', $this->jobStatus($jobId, strtolower(self::BUYER_ADDRESS))['state'], 'the payer, whatever the case of its address');
    }

    public function testASecondResultPaymentIsRefusedWhileOneIsPending(): void
    {
        $jobId = self::answer($this->start()[0])['jobId'];
        $this->receipts = [['success' => false, 'errorReason' => 'settlement_pending', 'transaction' => '0x'.str_repeat('cd', 32), 'network' => 'eip155:84532']];
        $first = $this->request(AnalyzeTermsJobTool::RESULT, ['jobId' => $jobId], $this->sign(AnalyzeTermsJobTool::RESULT, ['jobId' => $jobId]));
        self::assertSame('settlement_pending', $first['structuredContent']['error']);
        self::assertSame(Job::DELIVERING, $this->jobs->get($jobId)?->state, 'its money may be on chain');

        $second = $this->request(AnalyzeTermsJobTool::RESULT, ['jobId' => $jobId], $this->sign(AnalyzeTermsJobTool::RESULT, ['jobId' => $jobId]));
        self::assertSame('result_pending', $second['structuredContent']['error']);
        self::assertCount(2, $this->settled, 'the second payment was never sent to settle');
        self::assertSame(['delivering', 'result_settling', true, 10], array_values(array_diff_key($this->jobStatus($jobId), ['jobId' => 1])));
    }

    public function testARefusedResultSettleMakesTheJobReadyAgain(): void
    {
        $jobId = self::answer($this->start()[0])['jobId'];
        $this->receipts = [['success' => false, 'errorReason' => 'insufficient_funds', 'transaction' => '']];
        $refused = $this->request(AnalyzeTermsJobTool::RESULT, ['jobId' => $jobId], $this->sign(AnalyzeTermsJobTool::RESULT, ['jobId' => $jobId]));

        self::assertSame('insufficient_funds', $refused['structuredContent']['error'], 'the answer is withheld');
        self::assertSame(Job::READY, $this->jobs->get($jobId)?->state);
        $paid = $this->request(AnalyzeTermsJobTool::RESULT, ['jobId' => $jobId], $this->sign(AnalyzeTermsJobTool::RESULT, ['jobId' => $jobId]));
        self::assertTrue($paid['_meta'][X402McpMiddleware::PAYMENT_RESPONSE]['success']);
    }

    public function testAFailureOfOursMakesTheResultFree(): void
    {
        $this->work = static fn () => throw new JobFailed('provider_error');
        $jobId = self::answer($this->start()[0])['jobId'];

        self::assertSame(['failed_ours', 'provider_error', false], [$this->jobStatus($jobId)['state'], $this->jobStatus($jobId)['reason'], $this->jobStatus($jobId)['retryable']]);
        self::assertNull($this->jobs->get($jobId)?->params);
        $result = $this->request(AnalyzeTermsJobTool::RESULT, ['jobId' => $jobId], $this->sign(AnalyzeTermsJobTool::RESULT, ['jobId' => $jobId]));
        self::assertSame('job_failed_ours', $result['structuredContent']['error']);
        self::assertCount(1, $this->settled, 'only the deposit was ever charged');
    }

    public function testAResultIsNotSoldBeforeTheJobIsReady(): void
    {
        // The work outlives the request: here it is simply never run (no slot when kernel.terminate came).
        $job = $this->jobs->create(Job::settling(self::BUYER_ADDRESS, 'k', AnalyzeTermsJobTool::START, ['text' => self::TEXT], time())->to(Job::QUEUED, time()));
        $result = $this->request(AnalyzeTermsJobTool::RESULT, ['jobId' => $job->id], $this->sign(AnalyzeTermsJobTool::RESULT, ['jobId' => $job->id]));

        self::assertSame('job_not_ready', $result['structuredContent']['error']);
        self::assertSame([], $this->settled);
    }

    public function testOfTwoResultPaymentsRacingForOneJobOnlyOneDelivers(): void
    {
        $jobId = self::answer($this->start()[0])['jobId'];
        // Two processes, both past the payer check while the job was ready.
        $a = $this->desk();
        $b = $this->desk();
        $params = ['name' => AnalyzeTermsJobTool::RESULT, 'arguments' => ['jobId' => $jobId]];
        self::assertNull($a->payer($params, self::BUYER_ADDRESS));
        self::assertNull($b->payer($params, self::BUYER_ADDRESS));

        self::assertSame(Job::DELIVERING, $a->deliver($jobId)->state);
        $this->expectException(\App\Job\IllegalTransition::class);
        $b->deliver($jobId);
    }

    public function testAResultHandedOverButNeverSettledIsReadyAgain(): void
    {
        $jobId = self::answer($this->start()[0])['jobId'];
        // The tool delivered, then the middleware withheld the answer without settling (its ledger refused the write).
        self::assertNull($this->desk->payer(['name' => AnalyzeTermsJobTool::RESULT, 'arguments' => ['jobId' => $jobId]], self::BUYER_ADDRESS));
        $this->desk->deliver($jobId);
        $this->desk->afterResponse();

        self::assertSame(Job::READY, $this->jobs->get($jobId)?->state);
    }

    public function testASensitiveDepositKeepsTheResultOutOfTheLedgerButNotTheJobId(): void
    {
        [$started, $deposit] = $this->start(['text' => self::TEXT, 'sensitive' => true]);
        $jobId = self::answer($started)['jobId'];
        self::assertTrue($this->jobs->get($jobId)?->sensitive);

        self::assertFalse($this->desk->sensitive(['name' => AnalyzeTermsJobTool::START, 'arguments' => ['text' => self::TEXT, 'sensitive' => true]]), 'a job id carries no buyer text');
        self::assertSame($jobId, self::answer($this->request(AnalyzeTermsJobTool::START, ['text' => self::TEXT, 'sensitive' => true], $deposit))['jobId'], 'so its retry is replayed');
        self::assertTrue($this->desk->sensitive(['name' => AnalyzeTermsJobTool::RESULT, 'arguments' => ['jobId' => $jobId]]));
        self::assertTrue($this->desk->sensitive(['name' => AnalyzeTermsJobTool::RESULT, 'arguments' => ['jobId' => str_repeat('0', 32)]]), 'unknown: the safe side');

        $plain = self::answer($this->start()[0])['jobId'];
        self::assertFalse($this->desk->sensitive(['name' => AnalyzeTermsJobTool::RESULT, 'arguments' => ['jobId' => $plain]]));
        self::assertFalse($this->desk->sensitive(['name' => 'wisdom', 'arguments' => []]));
        self::assertTrue($this->desk->sensitive(['name' => 'wisdom', 'arguments' => ['sensitive' => true]]), 'other tools: the rules as before');
    }
}
