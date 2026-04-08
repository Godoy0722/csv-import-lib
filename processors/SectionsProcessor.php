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
	public static function newSectionToPublication(object $data, int $contextId, Publication $publication): void
    {
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
        $section->setTitle($data->sectionTitle, $data->locale);
        $section->setAbbrev(mb_strtoupper(trim($data->sectionAbbrev)), $data->locale);
        $section->setPath(mb_strtolower(trim($data->sectionAbbrev)));
        $section->setIdentifyType('', $data->locale);
        $section->setPolicy('', $data->locale);

        $sectionId = Repo::section()->add($section);

        $createdSection = Repo::section()->get($sectionId, $contextId);
        $customSectionKey = $data->sectionTitle . '_' . mb_strtoupper(trim($data->sectionAbbrev));
        CachedEntities::$sections[$customSectionKey] = $createdSection;

        PublicationProcessor::updateSectionId($publication, $sectionId);
	}
}
