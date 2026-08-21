<?php

/**
 * @file plugins/importexport/csv/shared/tests/Unit/Validations/ChapterValidationsTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ChapterValidationsTest
 *
 * @brief Tests for the chapter-related validations in InvalidRowValidations
 */

namespace APP\plugins\importexport\csv\shared\tests\Unit\Validations;

use APP\plugins\importexport\csv\classes\validations\ChapterValidations;
use APP\plugins\importexport\csv\shared\exceptions\RowValidationException;
use APP\plugins\importexport\csv\shared\tests\BaseTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ChapterValidations::class)]
class ChapterValidationsTest extends BaseTestCase
{
    private string $sourceDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceDir = sys_get_temp_dir() . '/csv_chapter_tests_' . uniqid();
        mkdir($this->sourceDir);
        file_put_contents($this->sourceDir . '/chapter1.pdf', 'dummy');
        file_put_contents($this->sourceDir . '/chapter2.pdf', 'dummy');
    }

    protected function tearDown(): void
    {
        unlink($this->sourceDir . '/chapter1.pdf');
        unlink($this->sourceDir . '/chapter2.pdf');
        rmdir($this->sourceDir);

        parent::tearDown();
    }

    /**
     * Rows without chapter data should pass validation silently.
     */
    public function testValidateChapterFieldsDoesNothingWhenBothFieldsEmpty(): void
    {
        $this->expectNotToPerformAssertions();

        ChapterValidations::validateChapterFields(
            (object) ['chapterTitle' => null, 'chapterFiles' => null],
            $this->sourceDir
        );
    }

    /**
     * Providing chapter files without a chapter title must be rejected.
     */
    public function testValidateChapterFieldsThrowsWhenFilesProvidedWithoutTitle(): void
    {
        $this->expectException(RowValidationException::class);

        ChapterValidations::validateChapterFields(
            (object) ['chapterTitle' => null, 'chapterFiles' => 'chapter1.pdf'],
            $this->sourceDir
        );
    }

    /**
     * A chapter title without files is allowed (chapter without attachments).
     */
    public function testValidateChapterFieldsAllowsTitleWithoutFiles(): void
    {
        $this->expectNotToPerformAssertions();

        ChapterValidations::validateChapterFields(
            (object) ['chapterTitle' => 'Introduction', 'chapterFiles' => null],
            $this->sourceDir
        );
    }

    /**
     * Each semicolon-separated chapter file must exist inside the source dir.
     */
    public function testValidateChapterFieldsThrowsWhenChapterFileMissing(): void
    {
        $this->expectException(RowValidationException::class);

        ChapterValidations::validateChapterFields(
            (object) ['chapterTitle' => 'Introduction', 'chapterFiles' => 'chapter1.pdf;missing.pdf'],
            $this->sourceDir
        );
    }

    /**
     * Files escaping the source dir must be rejected.
     */
    public function testValidateChapterFieldsThrowsWhenFileEscapesSourceDir(): void
    {
        $this->expectException(RowValidationException::class);

        ChapterValidations::validateChapterFields(
            (object) ['chapterTitle' => 'Introduction', 'chapterFiles' => '../outside.pdf'],
            $this->sourceDir
        );
    }

    /**
     * Valid positional data: subtitle/abstract/files only on the chapters they name.
     */
    public function testValidateChapterFieldsPassesWithValidFiles(): void
    {
        $this->expectNotToPerformAssertions();

        ChapterValidations::validateChapterFields(
            (object) [
                'chapterTitle' => 'Intro;Methods;Conclusion',
                'chapterSubtitle' => 'Intro Sub;;End Sub',
                'chapterAbstract' => 'Intro Abs;;',
                'chapterFiles' => ';chapter1.pdf;',
                'chapterContributors' => 'John,Doe,john@example.com,,,||',
                'authors' => 'John,Doe,john@example.com,,,',
            ],
            $this->sourceDir
        );
    }

    /**
     * A subtitle entry on a position without a title must be rejected.
     */
    public function testValidateChapterFieldsThrowsWhenSubtitleWithoutTitle(): void
    {
        $this->expectException(RowValidationException::class);

        ChapterValidations::validateChapterFields(
            (object) ['chapterTitle' => 'Intro;;Conclusion', 'chapterSubtitle' => 'Sub1;Sub2;'],
            $this->sourceDir
        );
    }

    /**
     * An abstract entry on a position without a title must be rejected.
     */
    public function testValidateChapterFieldsThrowsWhenAbstractWithoutTitle(): void
    {
        $this->expectException(RowValidationException::class);

        ChapterValidations::validateChapterFields(
            (object) ['chapterTitle' => 'Intro', 'chapterAbstract' => 'Abs1;Abs2'],
            $this->sourceDir
        );
    }

    /**
     * A list longer than the chapterTitle list must be rejected.
     */
    public function testValidateChapterFieldsThrowsWhenListLongerThanTitles(): void
    {
        $this->expectException(RowValidationException::class);

        ChapterValidations::validateChapterFields(
            (object) ['chapterTitle' => 'Intro;Methods', 'chapterSubtitle' => 'Sub1;Sub2;Sub3'],
            $this->sourceDir
        );
    }

    /**
     * Contributors without any chapterTitle must be rejected.
     */
    public function testValidateChapterFieldsThrowsWhenContributorsWithoutTitle(): void
    {
        $this->expectException(RowValidationException::class);

        ChapterValidations::validateChapterFields(
            (object) [
                'chapterTitle' => null,
                'chapterContributors' => 'John,Doe,john@example.com,,,',
                'authors' => 'John,Doe,john@example.com,,,',
            ],
            $this->sourceDir
        );
    }

    /**
     * A contributor group past the last chapter must be rejected.
     */
    public function testValidateChapterFieldsThrowsWhenContributorGroupBeyondLastChapter(): void
    {
        $this->expectException(RowValidationException::class);

        ChapterValidations::validateChapterFields(
            (object) [
                'chapterTitle' => 'Intro',
                'chapterContributors' => 'John,Doe,john@example.com,,,|John,Doe,john@example.com,,,',
                'authors' => 'John,Doe,john@example.com,,,',
            ],
            $this->sourceDir
        );
    }

    /**
     * Comma separates files within one chapter: 'a.pdf,b.pdf' on a single position.
     */
    public function testValidateChapterFieldsPassesWithCommaSeparatedFilesInOneChapter(): void
    {
        $this->expectNotToPerformAssertions();

        ChapterValidations::validateChapterFields(
            (object) ['chapterTitle' => 'Introduction', 'chapterFiles' => 'chapter1.pdf,chapter2.pdf'],
            $this->sourceDir
        );
    }

    /**
     * A missing file inside a comma-separated chapter list must be rejected.
     */
    public function testValidateChapterFieldsThrowsWhenFileMissingInCommaList(): void
    {
        $this->expectException(RowValidationException::class);

        ChapterValidations::validateChapterFields(
            (object) ['chapterTitle' => 'Introduction', 'chapterFiles' => 'chapter1.pdf,missing.pdf'],
            $this->sourceDir
        );
    }

    /**
     * A contributor that is not an exact raw copy of an authors entry must be rejected.
     */
    public function testValidateChapterFieldsThrowsWhenContributorDoesNotMatchAuthorsColumn(): void
    {
        $this->expectException(RowValidationException::class);

        ChapterValidations::validateChapterFields(
            (object) [
                'chapterTitle' => 'Intro',
                'chapterContributors' => 'Jane,Smith,jane@example.com,,,',
                'authors' => 'John,Doe,john@example.com,,,',
            ],
            $this->sourceDir
        );
    }
}
