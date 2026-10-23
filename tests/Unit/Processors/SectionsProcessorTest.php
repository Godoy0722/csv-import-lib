<?php

/**
 * @file plugins/importexport/csv/shared/tests/Unit/Processors/SectionsProcessorTest.php
 */

namespace APP\plugins\importexport\csv\shared\tests\Unit\Processors;

use APP\plugins\importexport\csv\shared\processors\SectionsProcessor;
use APP\plugins\importexport\csv\shared\tests\BaseTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SectionsProcessor::class)]
class SectionsProcessorTest extends BaseTestCase
{
    public function testAbbrevBaseFromSectionTitleUsesFirstThreeCharactersUppercased(): void
    {
        $this->assertSame('RES', SectionsProcessor::abbrevBaseFromSectionTitle('Research article'));
        $this->assertSame('AB', SectionsProcessor::abbrevBaseFromSectionTitle('Ab'));
    }

    public function testGenerateUniqueSectionAbbrevFromTitleWithoutCollision(): void
    {
        $this->assertSame(
            'COR',
            SectionsProcessor::generateUniqueSectionAbbrevFromTitle('Corrigendum', ['ART', 'RA'])
        );
    }

    public function testGenerateUniqueSectionAbbrevFromTitleWithCollisionSuffixes(): void
    {
        $this->assertSame(
            'RES_1',
            SectionsProcessor::generateUniqueSectionAbbrevFromTitle('Research article', ['RES'])
        );
        $this->assertSame(
            'RES_2',
            SectionsProcessor::generateUniqueSectionAbbrevFromTitle('Research article', ['RES', 'RES_1'])
        );
    }
}
