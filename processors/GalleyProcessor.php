<?php

/**
 * @file plugins/importexport/csv/shared/processors/GalleyProcessor.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class GalleyProcessor
 *
 * @ingroup plugins_importexport_csv
 *
 * @brief Processes the submission galley data into the database.
 */

namespace APP\plugins\importexport\csv\shared\processors;

use APP\facades\Repo;

class GalleyProcessor
{
    public static function process(int $submissionFileId, object $data, string $label, int $publicationId, string $extension, ?string $locale = null): int
    {
        $locale = $locale ?? $data->locale;

        $galley = Repo::galley()->newDataObject();
        $galley->setLabel($label);
        $galley->setLocale($locale);
        $galley->setSequence(REALLY_BIG_NUMBER);
        $galley->setIsApproved(true);
        $galley->setData('submissionFileId', $submissionFileId);
        $galley->setData('publicationId', $publicationId);
        $galley->setName(mb_strtoupper($extension), $locale);

        return Repo::galley()->add($galley);
    }
}
