<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Quebracho\X402\PaymentOutcome;
use Quebracho\X402\PaymentRecord;
use Quebracho\X402\Settlement;

final class PaymentRecordTest extends TestCase
{
    private const NOW = 1794000000;
    private const REQ = ['network' => 'eip155:84532', 'payTo' => '0xcb2d7f50D917FeD02b82ef08cEfBF9827463B794', 'amount' => '10000', 'asset' => '0x036CbD53842c5426634e7929541eC2318f3dCF7e'];

    private string $dir;
    /** @var list<string> */
    private array $errors = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/x402-records-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function record(?string $dir = null): PaymentRecord
    {
        $logger = new class($this->errors) extends AbstractLogger {
            /** @param list<string> $errors */
            public function __construct(private array &$errors)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->errors[] = (string) $message;
            }
        };

        return new PaymentRecord($dir ?? $this->dir, $logger, static fn (): int => self::NOW);
    }

    private static function payment(): array
    {
        return ['x402Version' => 2, 'accepted' => self::REQ, 'payload' => ['signature' => '0xsecret', 'authorization' => ['from' => '0x2c7536E3605D9C16a7a3D7b1898e529396a65c23', 'nonce' => '0x'.str_repeat('1', 64)]]];
    }

    /** @return list<array<string, mixed>> */
    private function lines(): array
    {
        $file = $this->dir.'/'.gmdate('Y-m', self::NOW).'.jsonl';

        return is_file($file) ? array_map(static fn (string $l) => json_decode($l, true), file($file, \FILE_IGNORE_NEW_LINES) ?: []) : [];
    }

    public function testASaleIsOneLineWithoutTheSignature(): void
    {
        $this->record()(new PaymentOutcome('settled', self::payment(), self::REQ, 200, ['success' => true, 'transaction' => '0xabc', 'network' => 'eip155:84532', 'payer' => '0x2c7536E3605D9C16a7a3D7b1898e529396a65c23']));

        self::assertSame([[
            'at' => gmdate('Y-m-d\TH:i:s\Z', self::NOW),
            'status' => 'settled',
            'key' => Settlement::key(self::payment()),
            'network' => 'eip155:84532',
            'payTo' => self::REQ['payTo'],
            'amount' => '10000',
            'asset' => self::REQ['asset'],
            'payer' => '0x2c7536E3605D9C16a7a3D7b1898e529396a65c23',
            'transaction' => '0xabc',
            'reason' => null,
            'handlerStatus' => 200,
        ]], $this->lines());
        self::assertStringNotContainsString('0xsecret', (string) file_get_contents($this->dir.'/'.gmdate('Y-m', self::NOW).'.jsonl'));
    }

    public function testAFailedSettlementIsKeptWithItsReasonAndTheRefusalsAreNot(): void
    {
        $record = $this->record();
        $record(new PaymentOutcome('failed_after_settle', self::payment(), self::REQ, 500, ['success' => true, 'transaction' => '0xdef', 'network' => 'eip155:84532'], 'handler_failed'));
        $record(new PaymentOutcome('rejected', self::payment(), self::REQ, reason: 'invalid_signature'));
        $record(new PaymentOutcome('failed', self::payment(), self::REQ, 500));

        $lines = $this->lines();
        self::assertCount(1, $lines);
        self::assertSame(['failed_after_settle', 'handler_failed', '0x2c7536E3605D9C16a7a3D7b1898e529396a65c23'], [$lines[0]['status'], $lines[0]['reason'], $lines[0]['payer']]);
    }

    public function testAWriteThatFailsIsLoggedAndDoesNotThrow(): void
    {
        $file = $this->dir.'-file';
        touch($file);
        try {
            $this->record($file.'/sub')(new PaymentOutcome('settled', self::payment(), self::REQ, 200, ['success' => true, 'transaction' => '0xabc']));
        } finally {
            unlink($file);
        }

        self::assertCount(1, $this->errors);
        self::assertStringStartsWith('x402 record not written', $this->errors[0]);
    }
}
