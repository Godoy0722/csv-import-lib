<?php

/**
 * @file plugins/importexport/csv/shared/tests/Unit/Handlers/CsvEncodingHandlerTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class CsvEncodingHandlerTest
 *
 * @ingroup plugins_importexport_csv
 *
 * @brief Tests for CsvEncodingHandler — UTF-8 validation and conversion
 */

namespace APP\plugins\importexport\csv\shared\tests\Unit\Handlers;

use APP\plugins\importexport\csv\shared\exceptions\CsvEncodingException;
use APP\plugins\importexport\csv\shared\handlers\CsvEncodingHandler;
use APP\plugins\importexport\csv\shared\tests\BaseTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(CsvEncodingHandler::class)]
class CsvEncodingHandlerTest extends BaseTestCase
{
    /**
     * Test double with no legacy encodings so invalid UTF-8 cannot be auto-detected.
     */
    private static function handlerWithNoConversionEncodings(): CsvEncodingHandler
    {
        return new class () extends CsvEncodingHandler {
            protected static function conversionSourceEncodings(): array
            {
                return [];
            }
        };
    }

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = $this->createTempDirectory();
    }

    protected function tearDown(): void
    {
        $this->cleanupTempDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testLeavesValidUtf8FileUnchanged(): void
    {
        $path = $this->tempDir . '/valid.csv';
        $original = "header\nvalue,café\n";
        file_put_contents($path, $original);

        $result = CsvEncodingHandler::ensureUtf8File($path);

        $this->assertFalse($result['converted']);
        $this->assertFalse($result['bomRemoved']);
        $this->assertNull($result['sourceEncoding']);
        $this->assertSame($original, file_get_contents($path));
    }

    public function testRemovesUtf8Bom(): void
    {
        $path = $this->tempDir . '/bom.csv';
        $content = "header\nvalue\n";
        file_put_contents($path, "\xEF\xBB\xBF" . $content);

        $result = CsvEncodingHandler::ensureUtf8File($path);

        $this->assertFalse($result['converted']);
        $this->assertTrue($result['bomRemoved']);
        $this->assertSame($content, file_get_contents($path));
    }

    public function testConvertsIso88591ToUtf8(): void
    {
        $path = $this->tempDir . '/latin1.csv';
        file_put_contents($path, "title\nCaf\xe9\n");

        $result = CsvEncodingHandler::ensureUtf8File($path);

        $this->assertTrue($result['converted']);
        $this->assertNotNull($result['sourceEncoding']);
        $this->assertTrue(mb_check_encoding(file_get_contents($path), 'UTF-8'));
        $this->assertStringContainsString('Café', file_get_contents($path));
    }

    public function testLeavesUtf8NamesWithDiacriticsUnchanged(): void
    {
        $path = $this->tempDir . '/utf8-names.csv';
        $original = "firstname,lastname\nGarcía,Müller\n";
        file_put_contents($path, $original);

        $result = CsvEncodingHandler::ensureUtf8File($path);

        $this->assertFalse($result['converted']);
        $this->assertNull($result['sourceEncoding']);
        $this->assertSame($original, file_get_contents($path));
    }

    public function testConvertsMacintoshNamesInsteadOfReadingThemAsWindows1252(): void
    {
        $path = $this->tempDir . '/mac-roman-names.csv';
        file_put_contents($path, "firstname,lastname\n" . $this->macintosh('García,Müller') . "\n");

        $result = CsvEncodingHandler::ensureUtf8File($path);

        $converted = file_get_contents($path);
        $this->assertTrue($result['converted']);
        $this->assertSame('Macintosh', $result['sourceEncoding']);
        $this->assertStringContainsString('García', $converted);
        $this->assertStringContainsString('Müller', $converted);
        $this->assertStringNotContainsString('Garc’a', $converted);
        $this->assertStringNotContainsString('MŸller', $converted);
    }

    public function testConvertsOtherMacintoshLatinLetters(): void
    {
        $path = $this->tempDir . '/mac-roman-more.csv';
        file_put_contents($path, $this->macintosh("José,François,Éric,Raúl,Schön\n"));

        $result = CsvEncodingHandler::ensureUtf8File($path);

        $converted = file_get_contents($path);
        $this->assertSame('Macintosh', $result['sourceEncoding']);
        $this->assertStringContainsString('José', $converted);
        $this->assertStringContainsString('François', $converted);
        $this->assertStringContainsString('Éric', $converted);
        $this->assertStringContainsString('Raúl', $converted);
        $this->assertStringContainsString('Schön', $converted);
    }

    public function testConvertsWindows1252Latin1NamesWithoutTreatingThemAsMacintosh(): void
    {
        $path = $this->tempDir . '/windows-1252-names.csv';
        file_put_contents($path, "firstname,lastname\n" . $this->windows1252('García,Müller') . "\n");

        $result = CsvEncodingHandler::ensureUtf8File($path);

        $converted = file_get_contents($path);
        $this->assertTrue($result['converted']);
        $this->assertSame('Windows-1252', $result['sourceEncoding']);
        $this->assertStringContainsString('García', $converted);
        $this->assertStringContainsString('Müller', $converted);
    }

    public function testLeavesWindows1252PunctuationAndCentralEuropeanLetters(): void
    {
        $path = $this->tempDir . '/windows-1252-punctuation.csv';
        $original = "O’Brien,it’s,l’amour,quelqu’un,aujourd’hui,Žižek,Škoda,kaše\n";
        file_put_contents($path, $this->windows1252($original));

        $result = CsvEncodingHandler::ensureUtf8File($path);

        $converted = file_get_contents($path);
        $this->assertSame('Windows-1252', $result['sourceEncoding']);
        $this->assertStringContainsString('O’Brien', $converted);
        $this->assertStringContainsString('it’s', $converted);
        $this->assertStringContainsString('l’amour', $converted);
        $this->assertStringContainsString('quelqu’un', $converted);
        $this->assertStringContainsString('aujourd’hui', $converted);
        $this->assertStringContainsString('Žižek', $converted);
        $this->assertStringContainsString('Škoda', $converted);
        $this->assertStringContainsString('kaše', $converted);
    }

    public function testConvertsInvalidUtf8ByteSequence(): void
    {
        $path = $this->tempDir . '/invalid-seq.csv';
        file_put_contents($path, "\xC3\x28");

        $result = CsvEncodingHandler::ensureUtf8File($path);

        $this->assertTrue($result['converted']);
        $this->assertTrue(mb_check_encoding(file_get_contents($path), 'UTF-8'));
    }

    public function testDetectSourceEncodingReturnsNullWhenNoCandidates(): void
    {
        $this->assertNull(CsvEncodingHandler::detectSourceEncoding("\xC3\x28", []));
    }

    public function testThrowsUndetectableEncodingWhenNoSourceEncodingApplies(): void
    {
        $path = $this->tempDir . '/undetectable.csv';
        file_put_contents($path, "\xC3\x28");

        $handler = self::handlerWithNoConversionEncodings();

        $this->expectException(CsvEncodingException::class);
        $handler::ensureUtf8File($path);
    }

    public function testThrowsWhenFileIsUnreadable(): void
    {
        $path = $this->tempDir . '/missing.csv';

        $this->expectException(CsvEncodingException::class);
        CsvEncodingHandler::ensureUtf8File($path);
    }

    private function macintosh(string $utf8): string
    {
        $converted = iconv('UTF-8', 'MACINTOSH', $utf8);
        $this->assertNotFalse($converted);

        return $converted;
    }

    private function windows1252(string $utf8): string
    {
        $converted = iconv('UTF-8', 'Windows-1252', $utf8);
        $this->assertNotFalse($converted);

        return $converted;
    }
}
