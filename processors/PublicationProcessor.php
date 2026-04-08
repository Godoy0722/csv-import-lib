<?php

/**
 * @file shared/processors/PublicationProcessor.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PublicationProcessor
 *
 * @ingroup csv_import_shared
 *
 * @brief Shared base processor for publication data common to PKP systems CSV imports.
 * Application-specific behavior (field names, context types) is handled by subclasses.
 */

namespace APP\plugins\importexport\csv\shared\processors;

use APP\facades\Repo;
use APP\plugins\importexport\csv\shared\validations\InvalidRowValidations;
use APP\publication\Publication;
use APP\submission\Submission;
use APP\file\PublicFileManager;
use PKP\file\FileManager;
use Exception;
use PKP\context\Context;

class PublicationProcessor
{
    /**
     * Create a base Publication with shared defaults (version, status).
     * Subclasses add app-specific fields (title, datePublished) before returning.
     */
    public static function createInitialPublication(object $data): Publication
    {
        $publication = Repo::publication()->newDataObject();

        $version = !empty($data->version) ? (int)$data->version : 1;
        $publication->setData('version', $version);
        $publication->setData('status', Submission::STATUS_PUBLISHED);

        return $publication;
    }

    /**
     * Set shared publication data (copyright, license, DOI, references) after submission creation.
     * Subclasses call parent::process() then add app-specific fields (subtitle, abstract, prefix).
     */
    public static function processCommons(Submission $submission, object $data, Context $context, string $sourceDir): Publication
    {
        /** @var Publication */
        $submissionPublication = $submission->getCurrentPublication();

        $submissionPublication->setData('copyrightNotice', $context->getLocalizedData('copyrightNotice', $data->locale));

        if (!empty($data->references)) {
            $referencesString = static::getReferencesContent($data->references, $sourceDir);

            if (!empty($referencesString)) {
                $submissionPublication->setData('citationsRaw', $referencesString);
            }
        }

        $copyrightHolder = $data->copyrightHolder
            ?? $submission->_getContextLicenseFieldValue(null, Submission::PERMISSIONS_FIELD_COPYRIGHT_HOLDER, $submissionPublication);
        $copyrightYear = $data->copyrightYear ?? $submission->_getContextLicenseFieldValue(
            null,
            Submission::PERMISSIONS_FIELD_COPYRIGHT_YEAR,
            $submissionPublication
        );
        $licenseUrl = $data->licenseUrl ?? $submission->_getContextLicenseFieldValue(
            null,
            Submission::PERMISSIONS_FIELD_LICENSE_URL,
            $submissionPublication
        );

        $submissionPublication->setData('copyrightHolder', $copyrightHolder, $data->locale);
        $submissionPublication->setData('copyrightYear', $copyrightYear);
        $submissionPublication->setData('licenseUrl', $licenseUrl);

        if (!empty($data->doi)) {
            $submissionPublication->setStoredPubId('doi', $data->doi);
        }

        return $submissionPublication;
    }

    /** Set the primary contact author on the publication and persist. */
    public static function updatePrimaryContactId(Publication $publication, int $authorId): void
    {
        $publication->setData('primaryContactId', $authorId);
        Repo::publication()->dao->update($publication);
    }

    /** Set the coverage field for a given locale (in-memory only, not persisted). */
    public static function updateCoverage(Publication $publication, string $coverage, string $locale): void
    {
        $publication->setData('coverage', $coverage, $locale);
    }

    /** Set cover image data on the publication for a given locale (in-memory only, not persisted). */
    public static function setCoverImage(Publication $publication, array $coverImageData, string $locale): void
    {
        $publication->setData('coverImage', $coverImageData, $locale);
    }

    /**
     * Validate, sanitize, and copy a cover image file to the public files directory.
     *
     * @throws Exception If the image is invalid or the copy fails
     */
    public static function uploadCoverImage(
        object $data,
        int $contextId,
        string $sourceDir,
        PublicFileManager $publicFileManager,
        FileManager $fileManager
    ): string {
        $reason = InvalidRowValidations::validateCoverImageIsValid($data->coverImageFilename, $sourceDir);
        if (!is_null($reason)) {
            throw new Exception($reason);
        }

        $sanitizedCoverImageName = str_replace([' ', '_', ':'], '-', mb_strtolower($data->coverImageFilename));
        $sanitizedCoverImageName = preg_replace('/[^a-z0-9\.\-]+/', '', $sanitizedCoverImageName);

        $randomPrefix = bin2hex(random_bytes(24));
        $sanitizedFileName = basename($sanitizedCoverImageName);
        $coverImageUploadName = $randomPrefix . '-' . $sanitizedFileName;
        $destFilePath = $publicFileManager->getContextFilesPath($contextId) . '/' . $coverImageUploadName;
        $srcFilePath = "{$sourceDir}/{$data->coverImageFilename}";

        $coverImageSaved = $fileManager->copyFile($srcFilePath, $destFilePath);

        if (!$coverImageSaved) {
            throw new Exception(__('plugin.importexport.csv.shared.erroWhileSavingCoverImage'));
        }

        return $coverImageUploadName;
    }

    /** Build cover image metadata and set it on the publication for the row's locale. */
    public static function updateCoverImage(Publication $publication, object $data, string $uploadName): void
    {
        $coverImage = [
            'dateUploaded' => date('Y-m-d H:i:s'),
            'uploadName' => $uploadName,
            'altText' => $data->coverImageAltText ?? '',
        ];

        $publication->setData('coverImage', $coverImage, $data->locale);
    }

    /** Set the section ID on the publication (in-memory only, not persisted). */
    public static function updateSectionId(Publication $publication, int $sectionId): void
    {
        $publication->setData('sectionId', $sectionId);
    }

    /**
     * Apply shared versioned-publication logic: update localized/non-localized fields,
     * DOI, and references from CSV data, falling back to the base publication's values.
     * Subclasses call this then add app-specific fields (datePublished) and persist.
     */
    public static function processVersionedPublicationCommons(
        Publication $publication,
        object $data,
        Publication $basePublication,
        string $sourceDir,
        array $localizedFields,
        array $nonLocalizedFields
    ): Publication {
        foreach ($localizedFields as $field => $csvField) {
            if (!empty($data->{$csvField})) {
                $value = $field === 'abstract' ? static::normalizeAbstractToHtml($data->{$csvField}) : $data->{$csvField};
                $publication->setData($field, $value, $data->locale);
            } elseif ($basePublication->getLocalizedData($field, $data->locale)) {
                $publication->setData($field, $basePublication->getLocalizedData($field, $data->locale), $data->locale);
            }
        }

        foreach($nonLocalizedFields as $field) {
            if (!empty($data->{$field})) {
                $publication->setData($field, $data->{$field});
            } elseif ($basePublication->getData($field)) {
                $publication->setData($field, $basePublication->getData($field));
            }
        }

        if (!empty($data->doi)) {
            $publication->setStoredPubId('doi', $data->doi);
        }

        if (!empty($data->references)) {
            $referencesString = static::getReferencesContent($data->references, $sourceDir);

            if (!empty($referencesString)) {
                $publication->setData('citationsRaw', $referencesString);
            }
        } elseif (!empty($basePublication->getData('citationsRaw'))) {
            $citationsRaw = (string) $basePublication->getData('citationsRaw');
            $publication->setData('citationsRaw', $citationsRaw);
        }

        return $publication;
    }

    /**
     * Create a new publication version by copying base publication data.
     * Bypasses Repo::publication()->version() to avoid CLI context dependency.
     */
    public static function createPublicationVersionCommons(Publication $basePublication, object $data, Context $context): Publication
    {
        $newPublication = Repo::publication()->newDataObject();
        $newPublication->setData('submissionId', $basePublication->getData('submissionId'));
        $newPublication->setData('version', (int)$data->version);
        $newPublication->setData('status', Submission::STATUS_PUBLISHED);
        $newPublication->setData('datePublished', null);
        $newPublication->setData('copyrightNotice', $context->getLocalizedData('copyrightNotice', $data->locale));
        $newPublication->setData('authors', []);
        $newPublication->setData('primaryContactId', null);
        $newPublication->stampModified();

        $localeFields = ['title', 'subtitle', 'abstract', 'prefix', 'copyrightHolder'];
        foreach ($localeFields as $localeField) {
            if ($basePubValue = $basePublication->getData($localeField, $data->locale)) {
                $newPublication->setData($localeField, $basePubValue, $data->locale);
            }
        }

        $nonLocaleFields = ['copyrightYear', 'licenseUrl'];
        foreach ($nonLocaleFields as $nonLocaleField) {
            if ($basePubValue = $basePublication->getData($nonLocaleField)) {
                $newPublication->setData($nonLocaleField, $basePubValue);
            }
        }

        if ($citationsRaw = $basePublication->getData('citationsRaw')) {
            $newPublication->setData('citationsRaw', (string) $citationsRaw);
        }

        $publicationId = Repo::publication()->dao->insert($newPublication);
        return Repo::publication()->get($publicationId);
    }

    /**
     * Apply shared multi-locale logic: add localized/non-localized fields and copyright notice
     * for a new locale to an existing publication. Subclasses provide the field maps.
     */
    public static function processMultiLocalePublicationCommons(
        Publication $publication,
        object $data,
        Context $context,
        array $localizedFields,
        array $nonLocalizedFields,
    ): Publication
    {
        foreach ($localizedFields as $field => $csvField) {
            if (!empty($data->{$csvField})) {
                $value = $field === 'abstract' ? static::normalizeAbstractToHtml($data->{$csvField}) : $data->{$csvField};
                $publication->setData($field, $value, $data->locale);
            }
        }

        foreach ($nonLocalizedFields as $nonLocaleField) {
            if (!empty($data->{$nonLocaleField})) {
                $publication->setData($nonLocaleField, $data->{$nonLocaleField});
            }
        }

        $publication->setData('copyrightNotice', $context->getLocalizedData('copyrightNotice', $data->locale));
        Repo::publication()->dao->update($publication);

        return $publication;
    }

    /** Read the contents of a references file from the source directory. */
    public static function getReferencesContent(string $referencesFilename, string $sourceDir): string|false
    {
        $referencesFilePath = "{$sourceDir}/{$referencesFilename}";
        return file_get_contents($referencesFilePath);
    }

    /**
     * Update the VOR DOI for a publication.
     * When a VOR DOI is provided, it automatically sets the relationStatus to PUBLISHED (3).
     * The DOI is normalized to URL format (https://doi.org/...) before storing.
     */
    public static function updateVorDoi(Publication $publication, ?string $vorDoi): void
    {
        $normalizedDoi = InvalidRowValidations::normalizeVorDoi($vorDoi);

        $publication->setData('vorDoi', $normalizedDoi);
        $publication->setData('relationStatus', Publication::PUBLICATION_RELATION_PUBLISHED);
        Repo::publication()->dao->update($publication);
    }

    /** Set supporting agencies from CSV data, falling back to the base publication if versioning. */
    public static function processSupportingAgencies(object $data, Publication $publication, ?Publication $basePublication = null): void
    {
        if (empty($data->supportingAgencies) && !is_null($basePublication)) {
            $baseSupportingAgencies = $basePublication->getData('supportingAgencies');
            if (empty($baseSupportingAgencies)) {
                return;
            }

            Repo::publication()->edit($publication, ['supportingAgencies' => $baseSupportingAgencies]);
            return;
        }

        if (empty($data->supportingAgencies)) {
            return;
        }

        $agenciesList = [$data->locale => array_map('trim', explode(';', $data->supportingAgencies))];
        if (empty($agenciesList[$data->locale])) {
            return;
        }

        Repo::publication()->edit($publication, ['supportingAgencies' => $agenciesList]);
    }

    /** Merge supporting agencies for a new locale into the existing agencies array. */
    public static function processSupportingAgenciesMultiLocale(object $data, Publication $publication): void
    {
        if (empty($data->supportingAgencies)) {
            return;
        }

        $existingAgencies = $publication->getData('supportingAgencies') ?? [];

        $newAgencies = array_map('trim', explode(';', $data->supportingAgencies));
        $existingAgencies[$data->locale] = $newAgencies;

        Repo::publication()->edit($publication, ['supportingAgencies' => $existingAgencies]);
    }

    /**
     * Normalize a plain-text abstract into HTML paragraphs.
     *
     * If the text already contains HTML block tags (<p> or <br), it is returned as-is.
     * Otherwise the text is split on literal "\n" sequences and real newlines,
     * and each non-empty segment is wrapped in <p>…</p>.
     */
    public static function normalizeAbstractToHtml(string $abstract): string
    {
        if (preg_match('/<(p|br)\b/i', $abstract)) {
            return $abstract;
        }

        $abstract = str_replace('\n', "\n", $abstract);

        $paragraphs = preg_split('/\r?\n/', $abstract);
        $paragraphs = array_filter(array_map('trim', $paragraphs), fn(string $p) => $p !== '');

        if (empty($paragraphs)) {
            return '';
        }

        return implode('', array_map(fn(string $p) => "<p>{$p}</p>", $paragraphs));
    }
}
