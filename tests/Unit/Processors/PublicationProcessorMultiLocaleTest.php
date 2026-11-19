<?php

/**
 * @file plugins/importexport/csv/shared/tests/Unit/Processors/PublicationProcessorMultiLocaleTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PublicationProcessorMultiLocaleTest
 *
 * @brief Tests that a row adding a locale to an imported publication stores the new locale next to the first one
 */

namespace APP\plugins\importexport\csv\shared\tests\Unit\Processors;

use APP\facades\Repo;
use APP\plugins\importexport\csv\shared\processors\KeywordsProcessor;
use APP\plugins\importexport\csv\shared\processors\PublicationProcessor;
use APP\plugins\importexport\csv\shared\processors\SubjectsProcessor;
use APP\plugins\importexport\csv\shared\tests\BaseTestCase;
use APP\publication\Publication;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PublicationProcessor::class)]
#[CoversClass(KeywordsProcessor::class)]
#[CoversClass(SubjectsProcessor::class)]
class PublicationProcessorMultiLocaleTest extends BaseTestCase
{
    private const PUBLICATION_ID = 5;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockStoringPublicationRepository();
    }

    /** The publication as the en row left it: stored with its en metadata. */
    private function storeEnglishPublication(): Publication
    {
        return $this->storePublication(self::PUBLICATION_ID, [
            'title' => ['en' => 'Sustainable Urban Design'],
            'abstract' => ['en' => '<p>Cities for people.</p>'],
            'coverage' => ['en' => 'global'],
            'copyrightHolder' => ['en' => 'PKP'],
            'keywords' => ['en' => [['name' => 'urban design'], ['name' => 'cities']]],
            'subjects' => ['en' => [['name' => 'architecture']]],
            'supportingAgencies' => ['en' => [['name' => 'PKP']]],
        ]);
    }

    private function portugueseRow(): object
    {
        return (object) [
            'locale' => 'pt_BR',
            'title' => 'Design Urbano Sustentável',
            'abstract' => 'Cidades para pessoas.',
            'coverage' => 'mundial',
            'copyrightHolder' => 'PKP Brasil',
            'keywords' => 'design urbano;cidades',
            'subjects' => 'arquitetura',
            'supportingAgencies' => 'PKP Brasil',
            'doi' => '',
        ];
    }

    /**
     * Runs the multi-locale steps in the order the import commands run them.
     */
    private function importPortugueseRow(): Publication
    {
        $data = $this->portugueseRow();

        $publication = PublicationProcessor::processMultiLocalePublicationCommons(
            Repo::publication()->get(self::PUBLICATION_ID),
            $data,
            ['title' => 'title', 'abstract' => 'abstract', 'coverage' => 'coverage', 'copyrightHolder' => 'copyrightHolder'],
            []
        );
        KeywordsProcessor::processMultiLocale($data, $publication);
        SubjectsProcessor::processMultiLocale($data, $publication);
        PublicationProcessor::processSupportingAgenciesMultiLocale($data, $publication);

        return $publication;
    }

    private function names(array $entries): array
    {
        return array_map(fn ($entry) => is_array($entry) ? $entry['name'] : $entry, $entries);
    }

    /**
     * Keywords, subjects and supporting agencies keep the en values and gain the pt_BR ones.
     * The en values used to be deleted (the save strips the vocabularies from the object it returns),
     * and the pt_BR keywords and subjects used to be overwritten by the next step's save.
     */
    public function testVocabulariesKeepBothLocales(): void
    {
        $this->storeEnglishPublication();

        $this->importPortugueseRow();

        $stored = Repo::publication()->get(self::PUBLICATION_ID);
        foreach ([
            'keywords' => [['urban design', 'cities'], ['design urbano', 'cidades']],
            'subjects' => [['architecture'], ['arquitetura']],
            'supportingAgencies' => [['PKP'], ['PKP Brasil']],
        ] as $field => [$english, $portuguese]) {
            $value = $stored->getData($field);
            $this->assertSame(['en', 'pt_BR'], array_keys($value), "{$field} locales");
            $this->assertSame($english, $this->names($value['en']), "{$field}[en]");
            $this->assertSame($portuguese, $this->names($value['pt_BR']), "{$field}[pt_BR]");
        }
    }

    /**
     * The localized publication fields keep the en value and gain the pt_BR one.
     */
    public function testLocalizedFieldsKeepBothLocales(): void
    {
        $this->storeEnglishPublication();

        $this->importPortugueseRow();

        $stored = Repo::publication()->get(self::PUBLICATION_ID);
        $this->assertSame(['en' => 'Sustainable Urban Design', 'pt_BR' => 'Design Urbano Sustentável'], $stored->getData('title'));
        $this->assertSame(['en' => 'global', 'pt_BR' => 'mundial'], $stored->getData('coverage'));
        $this->assertSame(['en' => 'PKP', 'pt_BR' => 'PKP Brasil'], $stored->getData('copyrightHolder'));
        $this->assertSame('<p>Cities for people.</p>', $stored->getData('abstract', 'en'));
        $this->assertStringContainsString('Cidades para pessoas.', $stored->getData('abstract', 'pt_BR'));
    }

    /**
     * A later save of the returned publication (the series update, for instance) keeps both locales.
     */
    public function testLaterSaveOfTheReturnedPublicationKeepsBothLocales(): void
    {
        $this->storeEnglishPublication();

        $publication = $this->importPortugueseRow();
        $publication->setData('seriesId', 3);
        Repo::publication()->dao->update($publication);

        $stored = Repo::publication()->get(self::PUBLICATION_ID);
        $this->assertSame(3, $stored->getData('seriesId'));
        foreach (['keywords', 'subjects', 'supportingAgencies', 'title', 'coverage'] as $field) {
            $this->assertSame(['en', 'pt_BR'], array_keys($stored->getData($field)), "{$field} locales");
        }
    }
}
