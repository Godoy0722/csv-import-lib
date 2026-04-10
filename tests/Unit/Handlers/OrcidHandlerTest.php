<?php

/**
 * @file plugins/importexport/csv/shared/tests/Unit/Handlers/OrcidHandlerTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OrcidHandlerTest
 *
 * @ingroup plugins_importexport_csv
 *
 * @brief Tests for OrcidHandler — ORCID normalization and validation logic.
 */

namespace APP\plugins\importexport\csv\shared\tests\Unit\Handlers;

use APP\plugins\importexport\csv\shared\exceptions\RowValidationException;
use APP\plugins\importexport\csv\shared\handlers\OrcidHandler;
use APP\plugins\importexport\csv\shared\tests\BaseTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(OrcidHandler::class)]
class OrcidHandlerTest extends BaseTestCase
{
    // ==================== Normalization Tests ====================

    #[DataProvider('validNormalizationProvider')]
    public function testNormalizeWithValidFormats(string $input, string $expected): void
    {
        $result = OrcidHandler::normalize($input);

        $this->assertEquals($expected, $result);
    }

    public static function validNormalizationProvider(): array
    {
        return [
            'Full HTTPS URL' => [
                'https://orcid.org/0000-0002-1825-0097',
                'https://orcid.org/0000-0002-1825-0097',
            ],
            'Sandbox URL' => [
                'https://sandbox.orcid.org/0000-0002-1825-0097',
                'https://sandbox.orcid.org/0000-0002-1825-0097',
            ],
            'Dashed format' => [
                '0000-0002-1825-0097',
                'https://orcid.org/0000-0002-1825-0097',
            ],
            'Numeric format (16 digits)' => [
                '0000000218250097',
                'https://orcid.org/0000-0002-1825-0097',
            ],
            'With X checksum (dashed)' => [
                '0000-0002-1694-233X',
                'https://orcid.org/0000-0002-1694-233X',
            ],
            'With X checksum (numeric)' => [
                '000000021694233X',
                'https://orcid.org/0000-0002-1694-233X',
            ],
            'With leading/trailing whitespace' => [
                '  0000-0002-1825-0097  ',
                'https://orcid.org/0000-0002-1825-0097',
            ],
        ];
    }

    #[DataProvider('invalidNormalizationProvider')]
    public function testNormalizeWithInvalidFormats(?string $input): void
    {
        $result = OrcidHandler::normalize($input);

        $this->assertNull($result);
    }

    public static function invalidNormalizationProvider(): array
    {
        return [
            'Empty string' => [''],
            'Null' => [null],
            'Whitespace only' => ['   '],
            'Too short' => ['0000-0002-1825'],
            'Too long' => ['0000-0002-1825-00971'],
            'Invalid characters' => ['0000-0002-182A-0097'],
            'Random text' => ['invalid-orcid'],
        ];
    }

    // ==================== Validation Tests ====================

    #[DataProvider('validOrcidProvider')]
    public function testValidateWithValidOrcids(string $orcid): void
    {
        OrcidHandler::validate($orcid);
        $this->assertTrue(true);
    }

    public static function validOrcidProvider(): array
    {
        return [
            'Full URL' => ['https://orcid.org/0000-0002-1825-0097'],
            'Sandbox URL' => ['https://sandbox.orcid.org/0000-0002-1825-0097'],
            'Dashed format' => ['0000-0002-1825-0097'],
            'Numeric format' => ['0000000218250097'],
            'With X checksum' => ['0000-0002-1694-233X'],
        ];
    }

    public function testValidateWithEmptyValueDoesNotThrow(): void
    {
        OrcidHandler::validate('');
        $this->assertTrue(true);
    }

    public function testValidateWithNullValueDoesNotThrow(): void
    {
        OrcidHandler::validate(null);
        $this->assertTrue(true);
    }

    public function testValidateWithInvalidFormatThrows(): void
    {
        $this->expectException(RowValidationException::class);
        OrcidHandler::validate('invalid-orcid');
    }

    public function testValidateWithInvalidChecksumThrows(): void
    {
        $this->expectException(RowValidationException::class);
        OrcidHandler::validate('0000-0002-1825-0098');
    }
}
