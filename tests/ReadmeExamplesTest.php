<?php

declare(strict_types=1);

namespace Edrard\Tests\WotRatings;

use PHPUnit\Framework\TestCase;

final class ReadmeExamplesTest extends TestCase
{
    public function testPhpExamplesMatchTheirDocumentedJsonResponses(): void
    {
        $root = dirname(__DIR__);
        $readme = str_replace("\r\n", "\n", file_get_contents($root.'/README.md'));
        preg_match_all('/```(php|json)\n(.*?)```/s', $readme, $blocks, PREG_SET_ORDER);
        $imports = 'use Edrard\\WotRatings\\LegacyRatings; use Edrard\\WotRatings\\PlayerDataPreparer; use Edrard\\WotRatings\\WotRatings; ';
        $previousDirectory = getcwd();
        chdir($root);
        $executed = 0;
        try {
            foreach ($blocks as $index => $block) {
                if ($block[1] !== 'php') {
                    continue;
                }
                self::assertSame('json', $blocks[$index + 1][1] ?? null, 'Every PHP usage example must have a JSON response.');
                $exampleCode = preg_match('/^use Edrard/m', $block[2]) === 1 ? $block[2] : $imports.$block[2];
                ob_start();
                try {
                    eval($exampleCode);
                    $printedResponse = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                $expectedResponse = json_decode($blocks[$index + 1][2], true, flags: JSON_THROW_ON_ERROR);
                $actualResponse = json_decode($printedResponse, true, flags: JSON_THROW_ON_ERROR);
                self::assertSame($expectedResponse, $actualResponse, 'README example '.($executed + 1).' differs from its documented response.');
                $executed++;
            }
        } finally {
            chdir($previousDirectory);
        }
        self::assertGreaterThanOrEqual(20, $executed, 'Public methods and responses must remain covered.');
    }
}
