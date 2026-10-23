<?php

/**
 * @file plugins/importexport/csv/shared/processors/SectionsProcessor.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SectionsProcessor
 *
 * @ingroup plugins_importexport_csv
 *
 * @brief Processes the section data into the database.
 */

namespace APP\plugins\importexport\csv\shared\processors;

use APP\facades\Repo;
use APP\plugins\importexport\csv\shared\cachedAttributes\CachedEntities;
use APP\publication\Publication;

class SectionsProcessor
{
    /**
     * First three characters of the section title, uppercased (used when CSV omits sectionAbbrev).
     */
    public static function abbrevBaseFromSectionTitle(string $sectionTitle): string
    {
        return mb_strtoupper(mb_substr(trim($sectionTitle), 0, 3));
    }

    /**
     * @param string[] $existingAbbrevs Uppercased abbreviations already used in the journal
     */
    public static function generateUniqueSectionAbbrevFromTitle(string $sectionTitle, array $existingAbbrevs): string
    {
        $base = static::abbrevBaseFromSectionTitle($sectionTitle);
        $existing = array_fill_keys(array_map(
            fn (string $abbrev) => mb_strtoupper(trim($abbrev)),
            $existingAbbrevs
        ), true);

        $candidate = $base;
        $suffix = 0;
        while (isset($existing[$candidate])) {
            $suffix++;
            $candidate = $base . '_' . $suffix;
        }

        return $candidate;
    }

    public static function resolveSectionAbbrev(
        string $sectionTitle,
        string $sectionAbbrevFromCsv,
        int $contextId,
        string $locale
    ): string {
        $sectionAbbrevFromCsv = trim($sectionAbbrevFromCsv);
        if ($sectionAbbrevFromCsv !== '') {
            return mb_strtoupper($sectionAbbrevFromCsv);
        }

        $existingAbbrevs = [];
        $sections = Repo::section()->getCollector()
            ->filterByContextIds([$contextId])
            ->getMany();

        foreach ($sections as $section) {
            $abbrev = $section->getAbbrev($locale);
            if (is_string($abbrev) && $abbrev !== '') {
                $existingAbbrevs[] = $abbrev;
            }
        }

        return static::generateUniqueSectionAbbrevFromTitle($sectionTitle, $existingAbbrevs);
    }

	public static function newSectionToPublication(object $data, int $contextId, Publication $publication): void
    {
        // A row may carry only sectionAbbrev; the abbreviation then names the section as well.
        $sectionTitle = trim($data->sectionTitle ?? '') ?: trim($data->sectionAbbrev ?? '');

        if ($sectionTitle === '') {
            return;
        }

        $sectionAbbrev = static::resolveSectionAbbrev(
            $sectionTitle,
            (string) ($data->sectionAbbrev ?? ''),
            $contextId,
            $data->locale
        );

        $section = Repo::section()->newDataObject();

        $section->setContextId($contextId);
        $section->setSequence(REALLY_BIG_NUMBER);
        $section->setEditorRestricted(false);
        $section->setMetaIndexed(true);
        $section->setMetaReviewed(true);
        $section->setAbstractsNotRequired(false);
        $section->setAbstractWordCount(REALLY_BIG_NUMBER);
        $section->setHideTitle(false);
        $section->setHideAuthor(false);
        $section->setIsInactive(false);
        $section->setTitle($sectionTitle, $data->locale);
        $section->setAbbrev($sectionAbbrev, $data->locale);
        $section->setPath(mb_strtolower($sectionAbbrev));
        $section->setIdentifyType('', $data->locale);
        $section->setPolicy('', $data->locale);

        $sectionId = Repo::section()->add($section);

        $createdSection = Repo::section()->get($sectionId, $contextId);
        CachedEntities::indexSection($createdSection, $contextId);

        PublicationProcessor::updateSectionId($publication, $sectionId);
	}
}
