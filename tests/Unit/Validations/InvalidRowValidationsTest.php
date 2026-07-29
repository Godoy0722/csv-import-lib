<?php

/**
 * @file plugins/importexport/csv/shared/tests/Unit/Validations/InvalidRowValidationsTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class InvalidRowValidationsTest
 *
 * @brief Tests for InvalidRowValidations, particularly validateDateFormat
 */

namespace APP\plugins\importexport\csv\shared\tests\Unit\Validations;

use APP\plugins\importexport\csv\shared\exceptions\RowValidationException;
use APP\plugins\importexport\csv\shared\tests\BaseTestCase;
use APP\plugins\importexport\csv\shared\validations\InvalidRowValidations;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(InvalidRowValidations::class)]
class InvalidRowValidationsTest extends BaseTestCase
{
    /**
     * Valid Y-m-d dates should not throw an exception.
     */
    public function testValidateDateFormatWithValidFormat(): void
    {
        $this->expectNotToPerformAssertions();

        InvalidRowValidations::validateDateFormat('2024-01-15', 'datePublished', true);
        InvalidRowValidations::validateDateFormat('2023-12-31', 'issuePublicationDate', false);
        InvalidRowValidations::validateDateFormat('2020-02-29', 'datePublished', true); // leap year
    }

    /**
     * Invalid date formats should throw RowValidationException.
     */
    public function testValidateDateFormatWithInvalidFormat(): void
    {
        $this->expectException(RowValidationException::class);
        InvalidRowValidations::validateDateFormat('15-01-2024', 'datePublished', true);
    }

    /**
     * Non-date strings should throw RowValidationException.
     */
    public function testValidateDateFormatWithNonDateString(): void
    {
        $this->expectException(RowValidationException::class);
        InvalidRowValidations::validateDateFormat('not-a-date', 'datePublished', true);
    }

    /**
     * Partially valid dates (e.g., missing day) should throw.
     */
    public function testValidateDateFormatWithPartialDate(): void
    {
        $this->expectException(RowValidationException::class);
        InvalidRowValidations::validateDateFormat('2024-01', 'datePublished', true);
    }

    /**
     * Invalid month (13) should throw.
     */
    public function testValidateDateFormatWithInvalidMonth(): void
    {
        $this->expectException(RowValidationException::class);
        InvalidRowValidations::validateDateFormat('2024-13-01', 'datePublished', true);
    }

    /**
     * Invalid day (32) should throw.
     */
    public function testValidateDateFormatWithInvalidDay(): void
    {
        $this->expectException(RowValidationException::class);
        InvalidRowValidations::validateDateFormat('2024-01-32', 'datePublished', true);
    }

    /**
     * Empty optional date should not throw (field is not required).
     */
    public function testValidateDateFormatWithEmptyOptional(): void
    {
        $this->expectNotToPerformAssertions();

        InvalidRowValidations::validateDateFormat(null, 'issuePublicationDate', false);
        InvalidRowValidations::validateDateFormat('', 'issuePublicationDate', false);
    }

    /**
     * Empty required date should throw RowValidationException.
     */
    public function testValidateDateFormatWithEmptyRequired(): void
    {
        $this->expectException(RowValidationException::class);
        InvalidRowValidations::validateDateFormat('', 'datePublished', true);
    }

    /**
     * Null required date should also throw.
     */
    public function testValidateDateFormatWithNullRequired(): void
    {
        $this->expectException(RowValidationException::class);
        InvalidRowValidations::validateDateFormat(null, 'datePublished', true);
    }

    /**
     * Default $required parameter should be true.
     */
    public function testValidateDateFormatDefaultsToRequired(): void
    {
        $this->expectException(RowValidationException::class);
        InvalidRowValidations::validateDateFormat('', 'someField');
    }
}