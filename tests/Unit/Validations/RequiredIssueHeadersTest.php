<?php

/**
 * @file plugins/importexport/csv/shared/tests/Unit/Validations/RequiredIssueHeadersTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RequiredIssueHeadersTest
 *
 * @brief Required-field waiver for later versions and extra locales
 */

namespace APP\plugins\importexport\csv\shared\tests\Unit\Validations;

use APP\plugins\importexport\csv\classes\validations\RequiredIssueHeaders;
use APP\plugins\importexport\csv\shared\tests\BaseTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(RequiredIssueHeaders::class)]
class RequiredIssueHeadersTest extends BaseTestCase
{
    public function testLaterVersionSkipsRequiredFieldsBeforeTheIdentifierIsSeen(): void
    {
        $row = $this->row(['versionIdentifier' => 'climate-study', 'version' => '2', 'locale' => 'en_US']);

        $this->assertTrue(RequiredIssueHeaders::isMultiVersionOrLocale($row, []));
    }

    public function testExtraLocaleOfAnImportedVersionSkipsRequiredFields(): void
    {
        $processed = [
            'ml-paper-001' => [
                1 => [
                    'en_US' => ['submission' => 1],
                ],
            ],
        ];
        $row = $this->row(['versionIdentifier' => 'ml-paper-001', 'version' => '1', 'locale' => 'fr_CA']);

        $this->assertTrue(RequiredIssueHeaders::isMultiVersionOrLocale($row, $processed));
    }

    public function testSameVersionAndLocaleIsNotAnExtraLocale(): void
    {
        $processed = [
            'ml-paper-001' => [
                1 => [
                    'en_US' => ['submission' => 1],
                ],
            ],
        ];
        $row = $this->row(['versionIdentifier' => 'ml-paper-001', 'version' => '1', 'locale' => 'en_US']);

        $this->assertFalse(RequiredIssueHeaders::isMultiVersionOrLocale($row, $processed));
    }

    public function testVersionOneIsNotWaivedBecauseAnotherVersionOfTheIdentifierExists(): void
    {
        $processed = [
            'climate-study' => [
                2 => [
                    'en_US' => ['submission' => 1],
                ],
            ],
        ];
        $row = $this->row(['versionIdentifier' => 'climate-study', 'version' => '1', 'locale' => 'en_US']);

        $this->assertFalse(RequiredIssueHeaders::isMultiVersionOrLocale($row, $processed));
    }

    public function testFirstVersionOneRowRequiresFields(): void
    {
        $row = $this->row([
            'versionIdentifier' => 'climate-study',
            'version' => '1',
            'locale' => 'en_US',
            'journalPath' => '',
            'articleTitle' => '',
            'authors' => '',
            'datePublished' => '',
        ]);

        $this->assertFalse(RequiredIssueHeaders::isMultiVersionOrLocale($row, []));
        $this->assertFalse(RequiredIssueHeaders::validateRowHasAllRequiredFields($row, []));
    }

    public function testLaterVersionRowMayOmitRequiredFields(): void
    {
        $row = $this->row([
            'versionIdentifier' => 'climate-study',
            'version' => '3',
            'locale' => 'en_US',
            'journalPath' => 'liv',
            'articleTitle' => '',
            'authors' => '',
            'datePublished' => '',
        ]);

        $this->assertTrue(RequiredIssueHeaders::validateRowHasAllRequiredFields($row, []));
    }

    public function testMissingVersionIdentifierIsNotAFollowUpRow(): void
    {
        $row = $this->row(['versionIdentifier' => '', 'version' => '2', 'locale' => 'en_US']);

        $this->assertFalse(RequiredIssueHeaders::isMultiVersionOrLocale($row, []));
    }

    private function row(array $values): object
    {
        return (object) array_merge([
            'journalPath' => 'liv',
            'locale' => 'en_US',
            'versionIdentifier' => '',
            'version' => '',
            'articleTitle' => 'Title',
            'authors' => 'Sarah,Johnson,sarah.j@stats.edu',
            'datePublished' => '2025-01-15',
        ], $values);
    }
}
