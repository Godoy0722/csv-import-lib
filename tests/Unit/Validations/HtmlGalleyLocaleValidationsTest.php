<?php

/**
 * @file plugins/importexport/csv/shared/tests/Unit/Validations/HtmlGalleyLocaleValidationsTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class HtmlGalleyLocaleValidationsTest
 *
 * @brief Tests for the optional locale of an HTML galley
 */

namespace APP\plugins\importexport\csv\shared\tests\Unit\Validations;

use APP\plugins\importexport\csv\shared\exceptions\RowValidationException;
use APP\plugins\importexport\csv\shared\tests\BaseTestCase;
use APP\plugins\importexport\csv\shared\validations\InvalidRowValidations;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(InvalidRowValidations::class)]
class HtmlGalleyLocaleValidationsTest extends BaseTestCase
{
    private string $sourceDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceDir = sys_get_temp_dir() . '/csv_html_galley_locale_tests_' . uniqid();
        mkdir($this->sourceDir);
        file_put_contents($this->sourceDir . '/article.html', '<p>dummy</p>');
        file_put_contents($this->sourceDir . '/style.css', 'body{}');
    }

    protected function tearDown(): void
    {
        foreach (['article.html', 'style.css'] as $filename) {
            $path = $this->sourceDir . '/' . $filename;
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($this->sourceDir);

        parent::tearDown();
    }

    /**
     * One supported locale for the HTML galley is valid. Dependent files do not need a locale.
     */
    public function testValidateHtmlGalleysAcceptsSingleLocale(): void
    {
        $this->expectNotToPerformAssertions();

        InvalidRowValidations::validateHtmlGalleys(
            'article.html;style.css',
            'pt_BR',
            $this->sourceDir,
            $this->createMockContext([
                'supportedLocales' => ['en', 'pt_BR'],
            ]),
            __('context.context')
        );
    }

    /**
     * More than one locale is rejected because the row has a single HTML galley.
     */
    public function testValidateHtmlGalleysThrowsWhenMoreThanOneLocale(): void
    {
        $this->expectException(RowValidationException::class);
        $this->expectExceptionMessage('invalidNumberOfLocalesAndHtmlGalleys');

        InvalidRowValidations::validateHtmlGalleys(
            'article.html;style.css',
            'en;pt_BR',
            $this->sourceDir,
            $this->createMockContext([
                'supportedLocales' => ['en', 'pt_BR'],
            ]),
            __('context.context')
        );
    }

    /**
     * A trailing semicolon adds an empty locale and must be rejected.
     */
    public function testValidateHtmlGalleysThrowsWhenTrailingDelimiterAddsLocale(): void
    {
        $this->expectException(RowValidationException::class);
        $this->expectExceptionMessage('invalidNumberOfLocalesAndHtmlGalleys');

        InvalidRowValidations::validateHtmlGalleys(
            'article.html',
            'pt_BR;',
            $this->sourceDir,
            $this->createMockContext([
                'supportedLocales' => ['en', 'pt_BR'],
            ]),
            __('context.context')
        );
    }

    /**
     * A locale the journal does not support is rejected with the article locale check.
     */
    public function testValidateHtmlGalleysThrowsWhenLocaleIsNotSupported(): void
    {
        $this->expectException(RowValidationException::class);
        $this->expectExceptionMessage('unknownLocale');

        InvalidRowValidations::validateHtmlGalleys(
            'article.html',
            'fr_CA',
            $this->sourceDir,
            $this->createMockContext([
                'supportedLocales' => ['en'],
            ]),
            __('context.context')
        );
    }

    /**
     * An empty locale cell is valid.
     */
    public function testValidateHtmlGalleysAcceptsEmptyLocale(): void
    {
        $this->expectNotToPerformAssertions();

        InvalidRowValidations::validateHtmlGalleys(
            'article.html;style.css',
            '',
            $this->sourceDir,
            $this->createMockContext(),
            __('context.context')
        );
    }

    /**
     * A missing locale is valid.
     */
    public function testValidateHtmlGalleysAcceptsNullLocale(): void
    {
        $this->expectNotToPerformAssertions();

        InvalidRowValidations::validateHtmlGalleys(
            'article.html',
            null,
            $this->sourceDir,
            $this->createMockContext(),
            __('context.context')
        );
    }

    /**
     * Whitespace is treated as an empty locale.
     */
    public function testValidateHtmlGalleysAcceptsWhitespaceLocale(): void
    {
        $this->expectNotToPerformAssertions();

        InvalidRowValidations::validateHtmlGalleys(
            'article.html',
            '   ',
            $this->sourceDir,
            $this->createMockContext(),
            __('context.context')
        );
    }

    /**
     * An empty cell uses the row locale.
     */
    public function testResolveHtmlGalleyLocaleUsesRowLocaleWhenEmpty(): void
    {
        $this->assertSame(
            'en',
            InvalidRowValidations::resolveHtmlGalleyLocale('', 'en')
        );
    }

    /**
     * A missing cell uses the row locale.
     */
    public function testResolveHtmlGalleyLocaleUsesRowLocaleWhenNull(): void
    {
        $this->assertSame(
            'pt_BR',
            InvalidRowValidations::resolveHtmlGalleyLocale(null, 'pt_BR')
        );
    }

    /**
     * A filled cell keeps that locale.
     */
    public function testResolveHtmlGalleyLocaleKeepsFilledValue(): void
    {
        $this->assertSame(
            'pt_BR',
            InvalidRowValidations::resolveHtmlGalleyLocale(' pt_BR ', 'en')
        );
    }
}
