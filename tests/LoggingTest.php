<?php

declare(strict_types=1);

namespace Edrard\Tests\WotRatings;

use Edrard\WotRatings\Exception\InvalidInput;
use Edrard\WotRatings\PlayerDataPreparer;
use Edrard\WotRatings\WotRatings;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

final class LoggingTest extends TestCase
{
    private function logger(?\Throwable $failure = null): AbstractLogger
    {
        return new class ($failure) extends AbstractLogger {
            public array $records = [];
            public function __construct(private ?\Throwable $failure)
            {
            }
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
                if ($this->failure !== null) {
                    throw $this->failure;
                }
            }
        };
    }

    public function testOneSummaryPerLoadAndNoLogsPerRatingCall(): void
    {
        $f = require dirname(__DIR__).'/examples/player-data.php';
        $logger = $this->logger();
        $p = PlayerDataPreparer::fromApiResponses(123, $f['accountInfoResponse'], $f['accountTanksResponse'], catalogue: $f['catalogue'], logger: $logger);
        self::assertCount(1, $logger->records);
        self::assertSame('debug', $logger->records[0]['level']);
        self::assertSame(['mismatchModes' => 0, 'unavailableRatings' => 3, 'invalidRatings' => 0], $logger->records[0]['context']);
        for ($i = 0; $i < 1000; ++$i) {
            $p->wn8();
            $p->availableModes('wn8');
        }
        self::assertCount(1, $logger->records);
        $p->setFullTankStatsResponse($f['tankStatsResponse']);
        self::assertCount(1, $logger->records); // Fully ready replacement is silent.
        $short = $f['short'];
        $short[0]['statistics']['battles'] = 99;
        self::assertFalse($p->setData($f['player'], $short, $f['full']));
        self::assertCount(2, $logger->records);
        self::assertSame('warning', $logger->records[1]['level']);
        self::assertSame(1, $logger->records[1]['context']['mismatchModes']);
    }

    public function testFailingLoggerPreservesPreparedResultsAndOriginalValidationFailures(): void
    {
        $f = require dirname(__DIR__).'/examples/player-data.php';
        foreach ([new \RuntimeException('logger secret'), new \Error('logger secret')] as $failure) {
            $p = new PlayerDataPreparer($f['catalogue'], logger: $this->logger($failure));
            self::assertTrue($p->setData($f['player'], $f['short']));
            $r = new WotRatings();
            $r->setExpectedValues($f['expected']);
            self::assertSame(1565, $r->wn8(...$p->wn8()));
            $report = $p->report;
            try {
                $p->setData(['statistics' => null], []);
                self::fail('Invalid player accepted.');
            } catch (InvalidInput $error) {
                self::assertSame('Player requires a statistics mode map.', $error->getMessage());
                self::assertSame($report, $p->report);
            }
        }
    }

    public function testNoLoggerAndSensitiveInputsProduceNoOutputOrSensitiveLogContext(): void
    {
        $f = require dirname(__DIR__).'/examples/player-data.php';
        $f['player']['nickname'] = 'PRIVATE_PLAYER';
        $f['player']['access_token'] = 'PRIVATE_TOKEN';
        $logger = $this->logger();
        $p = new PlayerDataPreparer($f['catalogue'], logger: $logger);
        ob_start();
        try {
            self::assertTrue($p->setData($f['player'], $f['short']));
            $quiet = new PlayerDataPreparer($f['catalogue']);
            self::assertTrue($quiet->setData($f['player'], $f['short']));
            self::assertSame('', ob_get_contents());
            self::assertSame($p->report, $quiet->report);
        } finally {
            ob_end_clean();
        }
        self::assertStringNotContainsString('PRIVATE_', json_encode($logger->records, JSON_THROW_ON_ERROR));
    }
}
