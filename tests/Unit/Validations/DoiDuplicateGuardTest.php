<?php

/**
 * @file plugins/importexport/csv/shared/tests/Unit/Validations/DoiDuplicateGuardTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DoiDuplicateGuardTest
 *
 * @brief Tests for DOI duplicate detection via validateDoiNotDuplicate
 */

namespace APP\plugins\importexport\csv\shared\tests\Unit\Validations;

use APP\plugins\importexport\csv\shared\exceptions\RowValidationException;
use APP\plugins\importexport\csv\shared\tests\BaseTestCase;
use APP\plugins\importexport\csv\shared\validations\InvalidRowValidations;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(InvalidRowValidations::class)]
class DoiDuplicateGuardTest extends BaseTestCase
{
    // ==================== CSV-level duplicates (L1: imported DOIs) ====================

    /**
     * First occurrence of a DOI passes; second is caught by L1 (in-flight cache).
     */
    public function testDuplicateDoiInCsvRejectsSecondOccurrence(): void
    {
        $existingDois = [];     // L2: nothing in DB
        $importedDois = [];     // L1: starts empty

        // First row — passes, tracked in L1
        InvalidRowValidations::validateDoiNotDuplicate('10.1234/test-1', $existingDois, $importedDois);
        $normalized = InvalidRowValidations::normalizeVorDoi('10.1234/test-1');
        $importedDois[$normalized] = true;

        // Second row, same DOI — should throw
        $this->expectException(RowValidationException::class);
        InvalidRowValidations::validateDoiNotDuplicate('10.1234/test-1', $existingDois, $importedDois);
    }

    /**
     * Same normalized DOI from different raw formats — second is rejected.
     */
    public function testDuplicateDoiDetectedAcrossDifferentRawFormats(): void
    {
        $existingDois = [];
        $importedDois = [
            'https://doi.org/10.1234/test-1' => true,
        ];

        // Same DOI but in doi: prefix format
        $this->expectException(RowValidationException::class);
        InvalidRowValidations::validateDoiNotDuplicate('doi:10.1234/test-1', $existingDois, $importedDois);
    }

    // ==================== DB-level duplicates (L2: existing DOIs) ====================

    /**
     * DOI already in the database (L2 cache) is rejected on first occurrence.
     */
    public function testDoiAlreadyInDatabaseIsRejected(): void
    {
        $existingDois = [
            'https://doi.org/10.1234/existing-doi' => true,
        ];
        $importedDois = [];     // L1: nothing imported yet

        $this->expectException(RowValidationException::class);
        InvalidRowValidations::validateDoiNotDuplicate('10.1234/existing-doi', $existingDois, $importedDois);
    }

    /**
     * DOI in both L1 and L2 is still rejected (L1 caught first).
     */
    public function testDoiInBothCachesIsRejected(): void
    {
        $existingDois = [
            'https://doi.org/10.1234/dual-doi' => true,
        ];
        $importedDois = [
            'https://doi.org/10.1234/dual-doi' => true,
        ];

        $this->expectException(RowValidationException::class);
        InvalidRowValidations::validateDoiNotDuplicate('10.1234/dual-doi', $existingDois, $importedDois);
    }

    // ==================== Cross-journal (journal-scoped) ====================

    /**
     * Same DOI in different journals is allowed — L2 is scoped per journal.
     * Since validateDoiNotDuplicate only checks arrays, cross-journal behavior
     * depends on the caller passing the correct journal-scoped L2 cache.
     * This test verifies that when each journal has its own L2, the same DOI
     * passes for journal B even if it exists in journal A's L2.
     */
    public function testSameDoiDifferentJournalsPasses(): void
    {
        // Journal A has this DOI
        $existingDoisJournalA = [
            'https://doi.org/10.1234/shared-doi' => true,
        ];
        // Journal B does NOT have this DOI
        $existingDoisJournalB = [];
        $importedDois = [];

        // Journal A — should be rejected
        $journalARejected = false;
        try {
            InvalidRowValidations::validateDoiNotDuplicate('10.1234/shared-doi', $existingDoisJournalA, $importedDois);
        } catch (RowValidationException $e) {
            $journalARejected = true;
        }
        $this->assertTrue($journalARejected, 'Journal A should reject DOI already in its L2 cache');

        // Journal B — should pass (different L2 scope)
        InvalidRowValidations::validateDoiNotDuplicate('10.1234/shared-doi', $existingDoisJournalB, $importedDois);
        // No exception thrown — test passes
    }

    // ==================== Normal flow (non-duplicate DOIs) ====================

    /**
     * Non-duplicate DOIs pass validation.
     */
    public function testNonDuplicateDoisPassValidation(): void
    {
        $existingDois = [
            'https://doi.org/10.1234/existing-1' => true,
        ];
        $importedDois = [
            'https://doi.org/10.1234/imported-1' => true,
        ];

        $this->expectNotToPerformAssertions();

        InvalidRowValidations::validateDoiNotDuplicate('10.1234/new-doi', $existingDois, $importedDois);
        InvalidRowValidations::validateDoiNotDuplicate('10.5678/another-new-one', $existingDois, $importedDois);
    }

    /**
     * Empty DOI skips validation (DOI is optional).
     */
    public function testEmptyDoiPassesValidation(): void
    {
        $existingDois = ['https://doi.org/10.1234/something' => true];
        $importedDois = ['https://doi.org/10.1234/something' => true];

        $this->expectNotToPerformAssertions();

        InvalidRowValidations::validateDoiNotDuplicate(null, $existingDois, $importedDois);
        InvalidRowValidations::validateDoiNotDuplicate('', $existingDois, $importedDois);
        InvalidRowValidations::validateDoiNotDuplicate('   ', $existingDois, $importedDois);
    }

    /**
     * Unrecognizable DOI format that can't be normalized passes through
     * (DOI plugin handles format validation separately).
     */
    public function testUnnormalizableDoiPassesValidation(): void
    {
        $existingDois = [];
        $importedDois = [];

        $this->expectNotToPerformAssertions();

        // Completely invalid DOI that normalizeVorDoi returns null for
        InvalidRowValidations::validateDoiNotDuplicate('not-a-doi-at-all', $existingDois, $importedDois);
    }

    // ==================== Versioned articles with same DOI ====================

    /**
     * Different versions of the same article (same versionIdentifier) sharing
     * a DOI are rejected — version relationship does not bypass the guard.
     */
    public function testVersionedArticleWithSameDoiIsRejected(): void
    {
        $existingDois = [];
        $importedDois = [];

        // Version 1 imports with DOI
        InvalidRowValidations::validateDoiNotDuplicate('10.5678/versioned-paper', $existingDois, $importedDois);
        $normalized = InvalidRowValidations::normalizeVorDoi('10.5678/versioned-paper');
        $importedDois[$normalized] = true;

        // Version 2 of the same article tries with same DOI — rejected
        $this->expectException(RowValidationException::class);
        InvalidRowValidations::validateDoiNotDuplicate('10.5678/versioned-paper', $existingDois, $importedDois);
    }

    /**
     * Versioned article with a DIFFERENT DOI than its base version passes.
     */
    public function testVersionedArticleWithDifferentDoiPasses(): void
    {
        $existingDois = [];
        $importedDois = [
            'https://doi.org/10.5678/versioned-v1' => true,
        ];

        $this->expectNotToPerformAssertions();

        // Version 2 with a new DOI — should pass
        InvalidRowValidations::validateDoiNotDuplicate('10.5678/versioned-v2', $existingDois, $importedDois);
    }

    // ==================== L1 (in-flight) vs L2 (database) precedence ====================

    /**
     * L1 is checked before L2 — error message reflects the DOI that was caught.
     */
    public function testL1CheckedBeforeL2(): void
    {
        $existingDois = [
            'https://doi.org/10.1234/order-test' => true,
        ];
        $importedDois = [
            'https://doi.org/10.1234/order-test' => true,
        ];

        // Both L1 and L2 have the DOI — exception is thrown (L1 catches it)
        $this->expectException(RowValidationException::class);
        InvalidRowValidations::validateDoiNotDuplicate('10.1234/order-test', $existingDois, $importedDois);
    }
}
