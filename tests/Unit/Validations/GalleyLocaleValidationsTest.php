<?php

/**
 * @file plugins/importexport/csv/shared/tests/Unit/Validations/GalleyLocaleValidationsTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class GalleyLocaleValidationsTest
 *
 * @brief Tests for galleyLocales validation inside publication galley checks
 */

namespace APP\plugins\importexport\csv\shared\tests\Unit\Validations;

use APP\plugins\importexport\csv\shared\exceptions\RowValidationException;
use APP\plugins\importexport\csv\shared\tests\BaseTestCase;
use APP\plugins\importexport\csv\shared\validations\InvalidRowValidations;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(InvalidRowValidations::class)]
class GalleyLocaleValidationsTest extends BaseTestCase
{
    private string $sourceDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceDir = sys_get_temp_dir() . '/csv_galley_locale_tests_' . uniqid();
        mkdir($this->sourceDir);
        file_put_contents($this->sourceDir . '/paper.pdf', 'dummy');
        file_put_contents($this->sourceDir . '/slides.pdf', 'dummy');
        file_put_contents($this->sourceDir . '/data.xlsx', 'dummy');
    }

    protected function tearDown(): void
    {
        foreach (['paper.pdf', 'slides.pdf', 'data.xlsx'] as $filename) {
            $path = $this->sourceDir . '/' . $filename;
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($this->sourceDir);

        parent::tearDown();
    }

    /**
     * Semicolon-separated galley locales that line up with the galley files are valid.
     */
    public function testValidatePublicationGalleysAcceptsMatchingLocales(): void
    {
        $this->expectNotToPerformAssertions();

        InvalidRowValidations::validatePublicationGalleys(
            'paper.pdf; slides.pdf; data.xlsx',
            'PDF; Slides; Data',
            'en; pt_BR; zh_Hans',
            $this->sourceDir,
            $this->createMockContext([
                'supportedLocales' => ['en', 'pt_BR', 'zh_Hans'],
            ]),
            __('context.context')
        );
    }

    /**
     * Fewer locale values than galley files must be rejected.
     */
    public function testValidatePublicationGalleysThrowsWhenFewerLocalesThanFiles(): void
    {
        $this->expectException(RowValidationException::class);
        $this->expectExceptionMessage('invalidNumberOfLocalesAndGalleys');

        InvalidRowValidations::validatePublicationGalleys(
            'paper.pdf;slides.pdf',
            'PDF;Slides',
            'en',
            $this->sourceDir,
            $this->createMockContext(),
            __('context.context')
        );
    }

    /**
     * More locale values than galley files must be rejected.
     */
    public function testValidatePublicationGalleysThrowsWhenMoreLocalesThanFiles(): void
    {
        $this->expectException(RowValidationException::class);
        $this->expectExceptionMessage('invalidNumberOfLocalesAndGalleys');

        InvalidRowValidations::validatePublicationGalleys(
            'paper.pdf;slides.pdf',
            'PDF;Slides',
            'en;pt_BR;fr_CA',
            $this->sourceDir,
            $this->createMockContext(),
            __('context.context')
        );
    }

    /**
     * A trailing semicolon adds an empty locale and must be rejected.
     */
    public function testValidatePublicationGalleysThrowsWhenTrailingDelimiterAddsLocale(): void
    {
        $this->expectException(RowValidationException::class);
        $this->expectExceptionMessage('invalidNumberOfLocalesAndGalleys');

        InvalidRowValidations::validatePublicationGalleys(
            'paper.pdf;slides.pdf',
            'PDF;Slides',
            'en;pt_BR;',
            $this->sourceDir,
            $this->createMockContext(),
            __('context.context')
        );
    }

    /**
     * An empty locale token is rejected when the value count still matches.
     */
    public function testValidatePublicationGalleysThrowsWhenLocaleTokenIsEmpty(): void
    {
        $this->expectException(RowValidationException::class);
        $this->expectExceptionMessage('unknownLocale');

        InvalidRowValidations::validatePublicationGalleys(
            'paper.pdf;slides.pdf;data.xlsx',
            'PDF;Slides;Data',
            'en;;pt_BR',
            $this->sourceDir,
            $this->createMockContext([
                'supportedLocales' => ['en', 'pt_BR'],
            ]),
            __('context.context')
        );
    }

    /**
     * A galley locale is rejected with the same check as the locale column
     * when the journal does not support it.
     */
    public function testValidatePublicationGalleysThrowsWhenLocaleIsNotSupported(): void
    {
        $this->expectException(RowValidationException::class);
        $this->expectExceptionMessage('unknownLocale');

        InvalidRowValidations::validatePublicationGalleys(
            'paper.pdf',
            'PDF',
            'fr_CA',
            $this->sourceDir,
            $this->createMockContext([
                'supportedLocales' => ['en'],
            ]),
            __('context.context')
        );
    }

    /**
     * An empty galleyLocales cell is valid and does not require one locale per file.
     */
    public function testValidatePublicationGalleysAcceptsEmptyLocales(): void
    {
        $this->expectNotToPerformAssertions();

        InvalidRowValidations::validatePublicationGalleys(
            'paper.pdf;slides.pdf;data.xlsx',
            'PDF;Slides;Data',
            '',
            $this->sourceDir,
            $this->createMockContext(),
            __('context.context')
        );
    }

    /**
     * A missing galleyLocales value is valid.
     */
    public function testValidatePublicationGalleysAcceptsNullLocales(): void
    {
        $this->expectNotToPerformAssertions();

        InvalidRowValidations::validatePublicationGalleys(
            'paper.pdf;slides.pdf',
            'PDF;Slides',
            null,
            $this->sourceDir,
            $this->createMockContext(),
            __('context.context')
        );
    }

    /**
     * Whitespace in galleyLocales is treated as empty.
     */
    public function testValidatePublicationGalleysAcceptsWhitespaceLocales(): void
    {
        $this->expectNotToPerformAssertions();

        InvalidRowValidations::validatePublicationGalleys(
            'paper.pdf',
            'PDF',
            '   ',
            $this->sourceDir,
            $this->createMockContext(),
            __('context.context')
        );
    }

    /**
     * When galleyLocales is empty, every galley uses the row locale.
     */
    public function testResolveGalleyLocalesUsesRowLocaleWhenEmpty(): void
    {
        $locales = InvalidRowValidations::resolveGalleyLocales(
            '',
            'paper.pdf;slides.pdf;data.xlsx',
            'fr_CA'
        );

        $this->assertSame(['fr_CA', 'fr_CA', 'fr_CA'], $locales);
    }

    /**
     * When galleyLocales is missing, every galley uses the row locale.
     */
    public function testResolveGalleyLocalesUsesRowLocaleWhenNull(): void
    {
        $locales = InvalidRowValidations::resolveGalleyLocales(
            null,
            'paper.pdf; slides.pdf',
            'pt_BR'
        );

        $this->assertSame(['pt_BR', 'pt_BR'], $locales);
    }

    /**
     * A filled galleyLocales cell keeps one locale per galley file.
     */
    public function testResolveGalleyLocalesKeepsFilledValues(): void
    {
        $locales = InvalidRowValidations::resolveGalleyLocales(
            'en; pt_BR',
            'paper.pdf;slides.pdf',
            'fr_CA'
        );

        $this->assertSame(['en', 'pt_BR'], $locales);
    }
}
