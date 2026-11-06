<?php

declare(strict_types=1);

namespace App\Tests\X402;

use App\X402\PaymentLog;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use Quebracho\X402\PaymentIntent;
use Quebracho\X402\PaymentOutcome;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class PaymentLogTest extends KernelTestCase
{
    private const SIGNATURE = '0xdeadbeefSIGNATURE';
    private const BEARER = 'Bearer sk-secret-token';

    private const REQ = ['network' => 'eip155:84532', 'payTo' => '0x000000000000000000000000000000000000dEaD', 'amount' => '10000', 'asset' => '0xUSDC', 'extra' => ['url' => 'https://facilitator.example/secret']];

    /** Records (level, message, context) like Monolog's TestHandler would. */
    private function recorder(): object
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: string, message: string, context: array}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    /** The $channels locator as services.yaml builds it: 'x402' only when the channel exists. */
    private function channels(?object $channel): ServiceLocator
    {
        return new ServiceLocator(null === $channel ? [] : ['x402' => static fn () => $channel]);
    }

    private function outcome(string $status, ?array $receipt = null, ?string $reason = null): PaymentOutcome
    {
        $payment = ['accepted' => ['network' => 'eip155:84532'], 'payload' => ['signature' => self::SIGNATURE, 'authorization' => ['from' => '0x1']]];

        return new PaymentOutcome($status, $payment, self::REQ, 200, $receipt, $reason, new \RuntimeException('POST https://facilitator.example/secret '.self::BEARER));
    }

    public function testAnIntentIsLoggedAtInfoWithItsFieldsOnly(): void
    {
        $logger = $this->recorder();
        $intent = new PaymentIntent('settling', str_repeat('a', 64), ['network' => 'eip155:84532', 'from' => '0x1'], ['network' => 'eip155:84532', 'payTo' => self::REQ['payTo'], 'amount' => '10000', 'asset' => '0xUSDC']);

        (new PaymentLog($logger, $this->channels(null)))->intent($intent);

        self::assertCount(1, $logger->records);
        self::assertSame('info', $logger->records[0]['level']);
        self::assertSame('x402 intent', $logger->records[0]['message']);
        self::assertSame(['phase', 'key', 'network', 'payTo', 'amount', 'asset'], array_keys($logger->records[0]['context']));
        self::assertSame('settling', $logger->records[0]['context']['phase']);
    }

    public function testWithoutTheChannelItLogsThroughThePsrLoggerWithThePrefix(): void
    {
        $logger = $this->recorder();

        (new PaymentLog($logger, $this->channels(null)))->outcome($this->outcome('settled', ['success' => true, 'transaction' => '0xabc']));

        self::assertSame('x402 outcome', $logger->records[0]['message']);
    }

    public function testWithTheChannelItLogsThereWithoutThePrefix(): void
    {
        $fallback = $this->recorder();
        $channel = $this->recorder();

        (new PaymentLog($fallback, $this->channels($channel)))->outcome($this->outcome('settled', ['success' => true, 'transaction' => '0xabc']));

        self::assertSame([], $fallback->records);
        self::assertCount(1, $channel->records);
        self::assertSame('outcome', $channel->records[0]['message']);
        self::assertSame('info', $channel->records[0]['level']);
    }

    public function testTheContainerGivesTheChannelWhenTheBundleIsInstalled(): void
    {
        // The dev/test kernel has symfony/monolog-bundle (require-dev): config/packages/x402_log.php defines the channel.
        self::bootKernel();
        $log = self::getContainer()->get(PaymentLog::class);

        $channels = (new \ReflectionProperty(PaymentLog::class, 'channels'))->getValue($log);
        self::assertTrue($channels->has('x402'));
        self::assertSame(self::getContainer()->get('monolog.logger.x402'), $channels->get('x402'));
    }

    public static function levels(): array
    {
        return [
            'settled' => ['settled', 'info'],
            'delivered' => ['delivered', 'info'],
            'not_settled' => ['not_settled', 'warning'],
            'rejected' => ['rejected', 'warning'],
            'failed' => ['failed', 'warning'],
            'failed_after_settle' => ['failed_after_settle', 'error'],
        ];
    }

    #[DataProvider('levels')]
    public function testAnOutcomeIsLoggedAtItsLevel(string $status, string $level): void
    {
        $logger = $this->recorder();

        (new PaymentLog($logger, $this->channels(null)))->outcome($this->outcome($status, ['success' => false, 'transaction' => '0xabc', 'errorReason' => 'invalid_settle']));

        self::assertSame($level, $logger->records[0]['level']);
        self::assertSame('x402 outcome', $logger->records[0]['message']);
        self::assertSame($status, $logger->records[0]['context']['status']);
        self::assertSame('0xabc', $logger->records[0]['context']['transaction']);
        self::assertSame('invalid_settle', $logger->records[0]['context']['reason']);
    }

    public function testTheLogNeverHoldsSecretsOrTheBody(): void
    {
        $logger = $this->recorder();
        $log = new PaymentLog($logger, $this->channels(null));

        $log->intent(new PaymentIntent('handling', str_repeat('b', 64), ['network' => 'eip155:84532', 'from' => '0x1'], ['network' => 'eip155:84532', 'payTo' => self::REQ['payTo'], 'amount' => '1', 'asset' => '0xUSDC'], 'pid', '0xabc'));
        $log->outcome($this->outcome('failed_after_settle', ['success' => true, 'transaction' => '0xabc'], null));

        self::assertCount(2, $logger->records);
        foreach ($logger->records as $record) {
            foreach (['signature', 'bearer', 'body', 'error', 'payment', 'receipt', 'url'] as $forbidden) {
                self::assertArrayNotHasKey($forbidden, $record['context']);
            }
            $dump = json_encode($record, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString(self::SIGNATURE, $dump);
            self::assertStringNotContainsString('sk-secret-token', $dump);
            self::assertStringNotContainsString('facilitator.example', $dump);
        }
    }
}
