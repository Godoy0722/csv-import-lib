<?php

/**
 * @file plugins/importexport/csv/shared/tests/Unit/CachedAttributes/CachedEntitiesSectionTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class CachedEntitiesSectionTest
 *
 * @brief Tests for the section lookup and cache of CachedEntities
 */

namespace APP\plugins\importexport\csv\shared\tests\Unit\CachedAttributes;

use APP\plugins\importexport\csv\shared\cachedAttributes\CachedEntities;
use APP\plugins\importexport\csv\shared\tests\BaseTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(CachedEntities::class)]
class CachedEntitiesSectionTest extends BaseTestCase
{
    /**
     * Registers the sections the collector returns for any context.
     */
    private function givenSections(array $sections, ?int $expectedLoads = 1): void
    {
        $repository = $this->mockSectionRepository();
        $expectation = $repository->shouldReceive('getCollector');

        if (!is_null($expectedLoads)) {
            $expectation->times($expectedLoads);
        }

        $expectation->andReturnUsing(fn() => $this->createMockSectionCollector($sections));
    }

    /**
     * A row carrying only the title still finds the section.
     */
    public function testFindsSectionByTitleOnly(): void
    {
        $this->givenSections([$this->createMockSection(['id' => 3, 'title' => 'Articles', 'abbrev' => 'ART'])]);

        $section = CachedEntities::getCachedSection('Articles', '', 'en', 1);

        $this->assertNotNull($section);
        $this->assertSame(3, $section->getId());
    }

    /**
     * A row carrying only the abbreviation still finds the section.
     */
    public function testFindsSectionByAbbrevOnly(): void
    {
        $this->givenSections([$this->createMockSection(['id' => 3, 'title' => 'Articles', 'abbrev' => 'ART'])]);

        $section = CachedEntities::getCachedSection('', 'ART', 'en', 1);

        $this->assertNotNull($section);
        $this->assertSame(3, $section->getId());
    }

    /**
     * Abbreviations are stored uppercased, so the CSV casing must not matter.
     */
    public function testMatchesAbbrevIgnoringCaseAndWhitespace(): void
    {
        $this->givenSections([$this->createMockSection(['id' => 3, 'title' => 'Articles', 'abbrev' => 'ART'])]);

        $section = CachedEntities::getCachedSection('', '  art  ', 'en', 1);

        $this->assertNotNull($section);
        $this->assertSame(3, $section->getId());
    }

    /**
     * Trailing whitespace in the CSV must not fork a duplicate section.
     */
    public function testMatchesTitleIgnoringSurroundingWhitespace(): void
    {
        $this->givenSections([$this->createMockSection(['id' => 3, 'title' => 'Articles', 'abbrev' => 'ART'])]);

        $section = CachedEntities::getCachedSection('  Articles  ', '', 'en', 1);

        $this->assertNotNull($section);
        $this->assertSame(3, $section->getId());
    }

    /**
     * When the row provides both fields, both have to match.
     */
    public function testRequiresBothFieldsToMatchWhenBothAreProvided(): void
    {
        $this->givenSections([$this->createMockSection(['id' => 3, 'title' => 'Articles', 'abbrev' => 'ART'])]);

        $this->assertNull(CachedEntities::getCachedSection('Articles', 'REV', 'en', 1));
    }

    /**
     * Several sections may share a title; the lowest id wins.
     */
    public function testReturnsLowestIdWhenSeveralSectionsMatch(): void
    {
        $this->givenSections([
            $this->createMockSection(['id' => 7, 'title' => 'Reviews', 'abbrev' => 'REV']),
            $this->createMockSection(['id' => 2, 'title' => 'Reviews', 'abbrev' => 'RES']),
        ]);

        $section = CachedEntities::getCachedSection('Reviews', '', 'en', 1);

        $this->assertNotNull($section);
        $this->assertSame(2, $section->getId());
    }

    /**
     * No match returns null so the caller can create the section.
     */
    public function testReturnsNullWhenNothingMatches(): void
    {
        $this->givenSections([$this->createMockSection(['id' => 3, 'title' => 'Articles', 'abbrev' => 'ART'])]);

        $this->assertNull(CachedEntities::getCachedSection('Essays', '', 'en', 1));
    }

    /**
     * The section list of a context is read from the database only once.
     */
    public function testLoadsSectionsOnlyOncePerContext(): void
    {
        $this->givenSections([$this->createMockSection(['id' => 3, 'title' => 'Articles', 'abbrev' => 'ART'])], 1);

        CachedEntities::getCachedSection('Articles', 'ART', 'en', 1);
        CachedEntities::getCachedSection('', 'ART', 'en', 1);
        CachedEntities::getCachedSection('Essays', '', 'en', 1);

        $this->assertTrue(true);
    }

    /**
     * Each context keeps its own list.
     */
    public function testLoadsEachContextSeparately(): void
    {
        $this->givenSections([$this->createMockSection(['id' => 3, 'title' => 'Articles', 'abbrev' => 'ART'])], 2);

        CachedEntities::getCachedSection('Articles', '', 'en', 1);
        CachedEntities::getCachedSection('Articles', '', 'en', 2);

        $this->assertTrue(true);
    }

    /**
     * A section created during the import is found afterwards without a new query.
     */
    public function testIndexedSectionIsFoundWithoutReloading(): void
    {
        $this->givenSections([$this->createMockSection(['id' => 3, 'title' => 'Articles', 'abbrev' => 'ART'])], 1);

        CachedEntities::getCachedSection('Articles', '', 'en', 1);

        CachedEntities::indexSection(
            $this->createMockSection(['id' => 9, 'title' => 'Essays', 'abbrev' => 'ESS']),
            1
        );

        $section = CachedEntities::getCachedSection('', 'ESS', 'en', 1);

        $this->assertNotNull($section);
        $this->assertSame(9, $section->getId());
    }

    /**
     * Indexing also feeds the by-id cache used for version inheritance.
     */
    public function testIndexedSectionIsReachableById(): void
    {
        $repository = $this->mockSectionRepository();
        $repository->shouldReceive('get')->never();

        CachedEntities::indexSection(
            $this->createMockSection(['id' => 9, 'title' => 'Essays', 'abbrev' => 'ESS']),
            1
        );

        $section = CachedEntities::getCachedSectionById(9, 1, 'en');

        $this->assertNotNull($section);
        $this->assertSame(9, $section->getId());
    }

    /**
     * Looking a section up by id twice hits the database only once.
     */
    public function testGetCachedSectionByIdCachesTheResult(): void
    {
        $repository = $this->mockSectionRepository();
        $repository->shouldReceive('get')
            ->once()
            ->andReturn($this->createMockSection(['id' => 4, 'title' => 'Notes', 'abbrev' => 'NOT']));

        CachedEntities::getCachedSectionById(4, 1, 'en');
        $section = CachedEntities::getCachedSectionById(4, 1, 'en');

        $this->assertNotNull($section);
        $this->assertSame(4, $section->getId());
    }
}
