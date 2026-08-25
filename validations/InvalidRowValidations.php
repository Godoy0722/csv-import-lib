<?php

/**
 * @file plugins/importexport/csv/shared/validations/InvalidRowValidations.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class InvalidRowValidations
 *
 * @ingroup plugins_importexport_csv
 *
 * @brief Class to validate all necessary requirements for a CSV row to be valid
 */

namespace APP\plugins\importexport\csv\shared\validations;

use APP\plugins\importexport\csv\shared\cachedAttributes\CachedEntities;
use APP\plugins\importexport\csv\shared\exceptions\RowValidationException;
use APP\plugins\importexport\csv\shared\processors\FundersProcessor;
use APP\publication\Publication;
use PKP\context\Context;

class InvalidRowValidations
{

    /** @var string[] */
    static array $coverImageAllowedTypes = ['gif', 'jpg', 'png', 'webp'];

    /**
     * Validates that a resolved file path stays within the source directory.
     * Prevents path traversal attacks via CSV filenames like "../../etc/passwd".
     *
     * @throws RowValidationException
     */
    public static function validatePathWithinSourceDir(string $filename, string $sourceDir): string
    {
        $resolvedSourceDir = realpath($sourceDir);
        if ($resolvedSourceDir === false) {
            throw new RowValidationException(__('plugins.importexport.csv.invalidSourceDir'));
        }

        $hasNullByte = str_contains($filename, "\0");
        $hasTraversal = preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $filename) === 1;
        $isAbsolute = preg_match('#^([a-zA-Z]:)?[\\\\/]#', $filename) === 1;

        if ($hasNullByte || $hasTraversal || $isAbsolute) {
            throw new RowValidationException(__('plugins.importexport.csv.filePathEscapesSourceDir', ['filename' => $filename]));
        }

        $candidatePath = "{$resolvedSourceDir}/{$filename}";
        $resolvedPath = realpath($candidatePath);

        if ($resolvedPath !== false && !str_starts_with($resolvedPath, $resolvedSourceDir . DIRECTORY_SEPARATOR)) {
            throw new RowValidationException(__('plugins.importexport.csv.filePathEscapesSourceDir', ['filename' => $filename]));
        }

        return $resolvedPath !== false ? $resolvedPath : $candidatePath;
    }

    /**
     * Validates whether the email is valid.
     *
     * @throws RowValidationException
     */
    public static function validateEmail(string $email): void
    {
        if (empty($email)) {
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RowValidationException(__('plugins.importexport.csv.invalidEmail', ['email' => $email]));
        }
    }

    /**
     * Validates whether the CSV row contains all fields.
     *
     * @throws RowValidationException
     */
    public static function validateRowContainAllFields(array $fields, int $expectedSize): void
    {
        $fieldCount = count($fields);

        if ($fieldCount < $expectedSize) {
            throw new RowValidationException(__('plugins.importexport.csv.rowDoesntContainAllFields'));
        }

        if ($fieldCount > $expectedSize) {
            throw new RowValidationException(__('plugins.importexport.csv.rowContainsTooManyFields'));
        }
    }

    /**
     * Validates whether the CSV row contains all required fields.
     *
     * @throws RowValidationException
     */
    public static function validateRowHasAllRequiredFieldsCommons(object $data, callable $requiredFieldsValidation): void
    {
        if (!$requiredFieldsValidation($data)) {
            throw new RowValidationException(__('plugins.importexport.csv.verifyRequiredFieldsForThisRow'));
        }
    }

    /**
     * Validates the publication cover image.
     *
     * @throws RowValidationException
     */
    public static function validateCoverImageIsValid(string $coverImageFilename, string $sourceDir): void
    {
        static::validatePathWithinSourceDir($coverImageFilename, $sourceDir);
        $publicationCoverImagePath = "{$sourceDir}/{$coverImageFilename}";

        if (!is_readable($publicationCoverImagePath)) {
            throw new RowValidationException(__('plugins.importexport.csv.invalidCoverImage'));
        }

        $coverImgExtension = mb_strtolower(pathinfo($coverImageFilename, PATHINFO_EXTENSION));

        if (!in_array($coverImgExtension, static::$coverImageAllowedTypes)) {
            throw new RowValidationException(__('plugins.importexport.csv.invalidFileExtension'));
        }
    }

    /**
     * Perform all necessary validations for publication galleys.
     *
     * @throws RowValidationException
     */
    public static function validatePublicationGalleys(string $galleyFilenames, string $galleyLabels, string $sourceDir): void
    {
        $galleyFilenamesArray = array_map('trim', explode(';', $galleyFilenames));
        $galleyLabelsArray = array_map('trim', explode(';', $galleyLabels));

        if (count($galleyFilenamesArray) !== count($galleyLabelsArray)) {
            throw new RowValidationException(__('plugins.importexport.csv.invalidNumberOfLabelsAndGalleys'));
        }

        foreach($galleyFilenamesArray as $galleyFilename) {
            static::validatePathWithinSourceDir($galleyFilename, $sourceDir);
            $galleyPath = "{$sourceDir}/{$galleyFilename}";
            if (!is_readable($galleyPath)) {
                throw new RowValidationException(__('plugins.importexport.csv.invalidGalleyFile', ['filename' => $galleyFilename]));
            }
        }
    }

    /**
     * Perform all necessary validations for HTML galleys.
     *
     * The first file in the semicolon-separated list must have an .html or .htm extension.
     * All files must exist, be readable, and not escape the source directory.
     *
     * @throws RowValidationException
     */
    public static function validateHtmlGalleys(?string $htmlGalley, string $sourceDir): void
    {
        if (empty(trim($htmlGalley ?? ''))) {
            return;
        }

        $htmlGalleyFiles = array_map('trim', explode(';', $htmlGalley));
        $htmlGalleyFiles = array_filter($htmlGalleyFiles, fn(string $f) => $f !== '');

        if (empty($htmlGalleyFiles)) {
            return;
        }

        $firstFile = $htmlGalleyFiles[0];
        $firstExtension = mb_strtolower(pathinfo($firstFile, PATHINFO_EXTENSION));

        if (!in_array($firstExtension, ['html', 'htm'])) {
            throw new RowValidationException(__('plugins.importexport.csv.invalidHtmlGalleyFirstFile', ['filename' => $firstFile]));
        }

        foreach ($htmlGalleyFiles as $file) {
            static::validatePathWithinSourceDir($file, $sourceDir);
            $filePath = "{$sourceDir}/{$file}";
            if (!is_readable($filePath)) {
                throw new RowValidationException(__('plugins.importexport.csv.invalidHtmlGalleyFile', ['filename' => $file]));
            }
        }
    }

    /**
     * Perform all necessary validations for supplementary files.
     *
     * @throws RowValidationException
     */
    public static function validateSupplementaryFiles(string $suppFilenames, string $suppLabels, string $sourceDir): void
    {
        $suppFilenamesArray = array_map('trim', explode(';', $suppFilenames));
        $suppLabelsArray = array_map('trim', explode(';', $suppLabels));

        if (count($suppFilenamesArray) !== count($suppLabelsArray)) {
            throw new RowValidationException(__('plugins.importexport.csv.invalidNumberOfLabelsAndSupplementaryFiles'));
        }

        foreach($suppFilenamesArray as $suppFilename) {
            static::validatePathWithinSourceDir($suppFilename, $sourceDir);
            $suppPath = "{$sourceDir}/{$suppFilename}";
            if (!is_readable($suppPath)) {
                throw new RowValidationException(__('plugins.importexport.csv.invalidSupplementaryFile', ['filename' => $suppFilename]));
            }
        }
    }

    /**
     * Validates the supplementary descriptions count.
     *
     * @throws RowValidationException
     */
    public static function validateSupplementaryDescriptions(string $suppFilenames, string $suppLabels, ?string $suppDescriptions): void
    {
        if (empty($suppDescriptions)) {
            return; // descriptions are optional
        }

        $suppFilenamesArray = array_map('trim', explode(';', $suppFilenames));
        $suppLabelsArray = array_map('trim', explode(';', $suppLabels));
        $suppDescriptionsArray = array_map('trim', explode(';', $suppDescriptions));

        if (
            count($suppDescriptionsArray) !== count($suppFilenamesArray) ||
            count($suppDescriptionsArray) !== count($suppLabelsArray)
        ) {
            throw new RowValidationException(__('plugins.importexport.csv.invalidNumberOfDescriptionsAndSupplementaryFiles'));
        }
    }

    /**
     * Validates whether the context is valid for the CSV row.
     *
     * @throws RowValidationException
     */
    public static function validateContextIsValid(?Context $context, string $contextPath, string $contextMessage): void
    {
        if (!$context) {
            throw new RowValidationException(__('plugins.importexport.csv.unknownContext', ['context' => $contextMessage, 'contextPath' => $contextPath]));
        }
    }

    /**
     * Validates if the context supports the locale provided in the CSV row.
     *
     * @throws RowValidationException
     */
    public static function validateContextLocale(Context $context, string $locale, string $contextMessage): void
    {
        $supportedLocales = $context->getSupportedSubmissionLocales();
        if (!is_array($supportedLocales) || count($supportedLocales) < 1) {
            $supportedLocales = [$context->getPrimaryLocale()];
        }
        if (!in_array($locale, $supportedLocales)) {
            throw new RowValidationException(__('plugins.importexport.csv.unknownLocale', [
                'context' => $contextMessage,
                'locale' => $locale,
                'supportedLocales' => implode(', ', $supportedLocales)
            ]));
        }
    }

    /**
     * Validates if a genre exists for the name provided in the CSV row.
     *
     * @throws RowValidationException
     */
    public static function validateGenreIdValid(?int $genreId, string $genreName): void
    {
        if (!$genreId) {
            throw new RowValidationException(__('plugins.importexport.csv.noGenre', ['genreName' => $genreName]));
        }
    }

    /**
     * Validates if the user group ID is valid.
     *
     * @throws RowValidationException
     */
    public static function validateUserGroupId(?int $userGroupId, string $contextPath, string $contextMessage): void
    {
        if (!$userGroupId) {
            throw new RowValidationException(__('plugins.importexport.csv.noAuthorGroup', [
                'context' => $contextMessage,
                'contextPath' => $contextPath,
            ]));
        }
    }

    /**
     * Validates if all user groups are valid.
     *
     * @throws RowValidationException
     */
    public static function validateAllUserGroupsAreValid(array $roles, int $contextId, string $locale): void
    {
        $userGroups = CachedEntities::getCachedUserGroupsByContextId($contextId);

        $allDbRoles = 0;
        foreach ($roles as $role) {
            $matchingGroups = array_filter($userGroups, fn($userGroup) => mb_strtolower($userGroup->name[$locale]) === mb_strtolower($role));
            $allDbRoles += count($matchingGroups);
        }

        if ($allDbRoles !== count($roles)) {
            throw new RowValidationException(__('plugins.importexport.csv.roleDoesntExist', ['role' => $role]));
        }
    }

    /**
     * Validates publication versioning fields.
     *
     * @throws RowValidationException
     */
    public static function validateContextVersioningFields(object $data): void
    {
        if (!empty($data->versionIdentifier) && empty($data->version)) {
            throw new RowValidationException(__('plugins.importexport.csv.versionRequiredWhenIdentifierProvided'));
        }

        if (!empty($data->version)) {
            if (!is_numeric($data->version) || (int)$data->version < 1) {
                throw new RowValidationException(__('plugins.importexport.csv.versionMustBePositiveInteger'));
            }
        }
    }

     /**
     * Validates that no duplicate version exists for the same publication identifier,
     * version, and locale combination in the current import session
     *
     * @throws RowValidationException
     */
    public static function validateNoDuplicateVersion(object $data, array $processedRows): void
    {
        $identifier = $data->versionIdentifier;
        $version = (int)$data->version;
        $locale = $data->locale;

        if (isset($processedRows[$identifier][$version][$locale])) {
            throw new RowValidationException(__('plugins.importexport.csv.duplicatePublicationVersionLocaleFound', [
                'identifier' => $identifier,
                'version' => $version,
                'locale' => $locale
            ]));
        }
    }

    /**
     * Checks if a version exists in any locale (used for multi-locale imports)
     */
    public static function versionExistsInAnyLocale(object $data, array $processedRows): bool
    {
        $identifier = $data->versionIdentifier;
        $version = (int)$data->version;

        return !empty($processedRows[$identifier][$version]);
    }

    /**
     * Validates the references file.
     *
     * @throws RowValidationException
     */
    public static function validateReferencesFile(?string $referencesFilename, string $sourceDir): void
    {
        if (empty($referencesFilename)) {
            return; // References file is optional
        }

        static::validatePathWithinSourceDir($referencesFilename, $sourceDir);
        $referencesFilePath = "{$sourceDir}/{$referencesFilename}";

        if (!is_readable($referencesFilePath)) {
            throw new RowValidationException(__('plugins.importexport.csv.invalidReferencesFile', ['filename' => $referencesFilename]));
        }

        $extension = pathinfo(mb_strtolower($referencesFilename), PATHINFO_EXTENSION);
        if ($extension !== 'txt') {
            throw new RowValidationException(__('plugins.importexport.csv.invalidReferencesFileExtension'));
        }
    }

    /**
     * Normalizes a VOR DOI value to the full URL format.
     */
    public static function normalizeVorDoi(?string $vorDoi): ?string
    {
        if (empty($vorDoi)) {
            return null;
        }

        $vorDoi = trim($vorDoi);

        // Already a valid DOI URL (https://doi.org/... or http://doi.org/... or https://dx.doi.org/...)
        if (preg_match('/^https?:\/\/(dx\.)?doi\.org\/10\.\d{4,}(\.\d+)*\/\S+$/i', $vorDoi)) {
            // Normalize to https://doi.org format
            return preg_replace('/^https?:\/\/(dx\.)?doi\.org\//i', 'https://doi.org/', $vorDoi);
        }

        // DOI with doi: prefix (doi:10.1234/example)
        if (preg_match('/^doi:(10\.\d{4,}(\.\d+)*\/\S+)$/i', $vorDoi, $matches)) {
            return 'https://doi.org/' . $matches[1];
        }

        // Just the DOI identifier (10.1234/example)
        if (preg_match('/^10\.\d{4,}(\.\d+)*\/\S+$/', $vorDoi)) {
            return 'https://doi.org/' . $vorDoi;
        }

        return null;
    }

    /**
     * Validates the funders string format.
     *
     * Funder format: "FunderName,FunderIdentification,Award1|Award2;FunderName2,FunderIdentification2,Award3"
     * - Each funder is separated by `;`
     * - Funder fields are separated by `,`
     * - Multiple awards for the same funder are separated by `|`
     *
     * @throws RowValidationException
     */
    public static function validateFunders(?string $fundersString): void
    {
        if (empty($fundersString)) {
            return;
        }

        $fundersArray = array_map('trim', explode(';', $fundersString));

        foreach ($fundersArray as $index => $funderString) {
            if (empty($funderString)) {
                continue;
            }

            $funderParts = array_map('trim', explode(',', $funderString));
            $funderName = $funderParts[0] ?? '';

            if (empty($funderName)) {
                throw new RowValidationException(__('plugins.importexport.csv.invalidFunderFormat', ['index' => $index + 1]));
            }
        }
    }

    /**
     * Validates that the Funding plugin is enabled when funders data is provided.
     * Throws RowValidationException if funders data is present but the plugin is not enabled.
     *
     * @throws RowValidationException
     */
    public static function validateFundingPluginEnabled(?string $fundersString, int $contextId, string $contextMessage): void
    {
        if (empty($fundersString)) {
            return;
        }

        if (!FundersProcessor::isFundingPluginEnabled($contextId)) {
            throw new RowValidationException(__('plugins.importexport.csv.fundingPluginNotEnabled', ['context' => $contextMessage]));
        }
    }

    /**
     * Validates that all funders have valid Crossref registry identifications.
     * This validation is only applied when the Funding plugin's 'enableGrantIdValidation'
     * setting is enabled for the context.
     *
     * Throws RowValidationException if any funder lacks a valid Crossref DOI.
     *
     * @throws RowValidationException
     */
    public static function validateFundersCrossrefRegistry(?string $fundersString, int $contextId): void
    {
        if (empty($fundersString)) {
            return;
        }

        if (!FundersProcessor::isCrossrefValidationEnabled($contextId)) {
            return;
        }

        $fundersArray = array_map('trim', explode(';', $fundersString));

        foreach ($fundersArray as $index => $funderString) {
            if (empty($funderString)) {
                continue;
            }

            $funderParts = array_map('trim', explode(',', $funderString));
            $funderName = $funderParts[0] ?? '';
            $funderIdentification = $funderParts[1] ?? '';

            if (empty($funderName)) {
                continue;
            }

            // Check if the funder identification contains a valid Crossref Funder Registry DOI
            // Valid formats: https://doi.org/10.13039/... or http://dx.doi.org/10.13039/...
            if (!empty($funderIdentification)) {
                $hasCrossrefDoi = preg_match('/https?:\/\/(dx\.)?doi\.org\/10\.13039\//i', $funderIdentification);
                if (!$hasCrossrefDoi) {
                    throw new RowValidationException(__('plugins.importexport.csv.funderNotInCrossrefRegistry', [
                        'funderName' => $funderName,
                        'index' => $index + 1
                    ]));
                }
            } else {
                // Funder identification is required for Crossref registry validation
                throw new RowValidationException(__('plugins.importexport.csv.funderMissingCrossrefId', [
                    'funderName' => $funderName,
                    'index' => $index + 1
                ]));
            }
        }
    }

    /**
     * Validates whether a user already exists with the given username.
     *
     * @throws RowValidationException
     */
    public static function validateUserAlreadyExistsWithThisUsername(string $username): void
    {
        $existingUserByUsername = CachedEntities::getCachedUserByUsername($username);
        if (!is_null($existingUserByUsername)) {
            throw new RowValidationException(__('plugins.importexport.csv.userAlreadyExistsWithUsername', ['username' => $username]));
        }
    }

    /**
     * Validates whether a user already exists with the given email.
     *
     * @throws RowValidationException
     */
    public static function validateUserAlreadyExistsWithThisEmail(string $email): void
    {
        $existingUserByEmail = CachedEntities::getCachedUserByEmail($email);
        if (!is_null($existingUserByEmail)) {
            throw new RowValidationException(__('plugins.importexport.csv.userAlreadyExistsWithEmail', ['email' => $email]));
        }
    }

    /**
     * Validates if the publication was successfully retrieved or created.
     *
     * @throws RowValidationException
     */
    public static function validatePublicationWasSuccessfullyCreated(?Publication $publication): void
    {
        if (!$publication) {
            throw new RowValidationException(__('plugins.importexport.csv.errorWhileCreatingPublication'));
        }
    }

    /**
     * Validates the publicationViews field.
     * Must be empty or a non-negative integer.
     *
     * @throws RowValidationException
     */
    public static function validatePublicationViews(?string $submissionViews, string $fieldName): void
    {
        if (empty($submissionViews)) {
            return;
        }

        if (!ctype_digit($submissionViews)) {
            throw new RowValidationException(__('plugins.importexport.csv.invalidSubmissionViews', ['fieldName' => $fieldName]));
        }
    }

    /**
     * Validates the galleyViews field.
     * If provided, must have the same count of semicolon-separated values as galleyLabels,
     * and each non-empty value must be a non-negative integer.
     *
     * @throws RowValidationException
     */
    public static function validateGalleyViews(?string $galleyViews, ?string $galleyLabels): void
    {
        if (empty($galleyViews)) {
            return;
        }

        if (empty($galleyLabels)) {
            throw new RowValidationException(__('plugins.importexport.csv.galleyViewsWithoutGalleys'));
        }

        $galleyViewsArray = array_map('trim', explode(';', $galleyViews));
        $galleyLabelsArray = array_map('trim', explode(';', $galleyLabels));

        if (count($galleyViewsArray) !== count($galleyLabelsArray)) {
            throw new RowValidationException(__('plugins.importexport.csv.invalidNumberOfGalleyViews'));
        }

        foreach ($galleyViewsArray as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            if (!ctype_digit($value)) {
                throw new RowValidationException(__('plugins.importexport.csv.invalidGalleyViewValue', ['value' => $value]));
            }
        }
    }

    /**
     * Validates a date string matches Y-m-d format.
     * When required, empty dates throw an exception.
     * When optional, empty dates pass through.
     *
     * @throws RowValidationException
     */
    public static function validateDateFormat(?string $date, string $fieldName, bool $required = true): void
    {
        if (empty($date)) {
            if ($required) {
                throw new RowValidationException(__('plugins.importexport.csv.invalidDateFormat', ['fieldName' => $fieldName]));
            }
            return;
        }

        $dateObj = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
            throw new RowValidationException(__('plugins.importexport.csv.invalidDateFormat', ['fieldName' => $fieldName]));
        }
    }

    /**
     * Validates that the row identifies a section.
     * sectionTitle and sectionAbbrev form a group: at least one of them must be filled.
     *
     * @throws RowValidationException
     */
    public static function validateSectionFields(object $data): void
    {
        $sectionTitle = trim($data->sectionTitle ?? '');
        $sectionAbbrev = trim($data->sectionAbbrev ?? '');

        if ($sectionTitle === '' && $sectionAbbrev === '') {
            throw new RowValidationException(__('plugins.importexport.csv.incompleteSectionFields'));
        }
    }

    /**
     * Validates that a DOI does not already exist in the journal (database or current import session).
     * Empty DOIs pass through — DOI is optional.
     *
     * @param string|null $doi The raw DOI from the CSV row
     * @param array<string,bool> $existingDois Preloaded DB DOIs keyed by normalized DOI
     * @param array<string,bool> $importedDois DOIs already imported in this run
     *
     * @throws RowValidationException
     */
    public static function validateDoiNotDuplicate(?string $doi, array $existingDois, array $importedDois): void
    {
        if (empty(trim($doi ?? ''))) {
            return;
        }

        $normalized = static::normalizeVorDoi($doi);

        if ($normalized === null) {
            return;
        }

        if (isset($importedDois[$normalized])) {
            throw new RowValidationException(__('plugins.importexport.csv.duplicateDoi', ['doi' => $doi]));
        }

        if (isset($existingDois[$normalized])) {
            throw new RowValidationException(__('plugins.importexport.csv.duplicateDoi', ['doi' => $doi]));
        }
    }
}
