<?php

/**
 * @file plugins/importexport/csv/shared/processors/HtmlGalleyProcessor.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class HtmlGalleyProcessor
 *
 * @ingroup plugins_importexport_csv
 *
 * @brief Processes HTML galley data with dependent files into the database.
 */

namespace APP\plugins\importexport\csv\shared\processors;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\importexport\csv\shared\exceptions\RowValidationException;
use PKP\core\PKPString;
use PKP\submissionFile\SubmissionFile;
use PKP\user\User;

class HtmlGalleyProcessor
{
    /**
     * Sanitize an HTML file's content using PKP's HTMLPurifier configuration.
     * Reads the file from disk, strips unsafe HTML, and returns the purified content.
     *
     * @throws RowValidationException if the file cannot be read
     */
    public static function sanitizeHtmlFile(string $filePath): string
    {
        $htmlContent = file_get_contents($filePath);

        if ($htmlContent === false) {
            throw new RowValidationException(
                __('plugins.importexport.csv.errorWhileSanitizingHtmlGalley', ['filename' => basename($filePath)])
            );
        }

        return PKPString::stripUnsafeHtml($htmlContent);
    }

    /**
     * Create dependent submission files linked to an HTML galley's primary submission file.
     *
     * Dependent files (CSS, images, JS, fonts, etc.) are stored with:
     *  - fileStage = SubmissionFile::SUBMISSION_FILE_DEPENDENT
     *  - assocType = Application::ASSOC_TYPE_SUBMISSION_FILE
     *  - assocId  = $parentSubmissionFileId
     *
     * This is the same association pattern used by OJS core's grid-based dependent
     * file uploader and the article viewer (ArticleHandler::download).
     *
     * @return int[] Array of created submission file IDs
     */
    public static function createDependentFiles(
        array $dependentFiles,
        int $parentSubmissionFileId,
        string $sourceDir,
        string $destinationDir,
        object $data,
        int $submissionId,
        int $genreId,
        User $fileUploadUser,
        \PKP\services\PKPFileService $fileService
    ): array {
        $dependentFileIds = [];

        foreach ($dependentFiles as $dependentFile) {
            $sourcePath = "{$sourceDir}/{$dependentFile}";
            $extension = pathinfo($dependentFile, PATHINFO_EXTENSION);
            $destPath = $destinationDir . '/' . uniqid() . '.' . $extension;

            try {
                $fileId = $fileService->add($sourcePath, $destPath);
            } catch (\Exception $e) {
                // Cleanup previously created files on failure
                foreach ($dependentFileIds as $createdFileId) {
                    try {
                        $fileService->delete($createdFileId);
                    } catch (\Exception $cleanupError) {
                        error_log('Failed to cleanup dependent file ' . $createdFileId . ': ' . $cleanupError->getMessage());
                    }
                }
                throw new RowValidationException(
                    __('plugins.importexport.csv.errorWhileSavingHtmlDependentFile', ['filename' => $dependentFile])
                );
            }

            $submissionFile = Repo::submissionFile()->newDataObject();
            $submissionFile->setData('submissionId', $submissionId);
            $submissionFile->setData('uploaderUserId', $fileUploadUser->getId());
            $submissionFile->setData('fileId', $fileId);
            $submissionFile->setData('genreId', $genreId);
            $submissionFile->setData('fileStage', SubmissionFile::SUBMISSION_FILE_DEPENDENT);
            $submissionFile->setData('assocType', Application::ASSOC_TYPE_SUBMISSION_FILE);
            $submissionFile->setData('assocId', $parentSubmissionFileId);
            $submissionFile->setData('createdAt', \PKP\core\Core::getCurrentDate());
            $submissionFile->setData('updatedAt', \PKP\core\Core::getCurrentDate());
            $submissionFile->setData('mimeType', PKPString::mime_content_type($sourcePath));
            $submissionFile->setData('locale', $data->locale);
            $submissionFile->setData('name', pathinfo($dependentFile, PATHINFO_BASENAME), $data->locale);
            $submissionFile->setDirectSalesPrice(0);
            $submissionFile->setSalesType('openAccess');

            $dependentFileId = Repo::submissionFile()->add($submissionFile);
            $dependentFileIds[] = $dependentFileId;
        }

        return $dependentFileIds;
    }
}
