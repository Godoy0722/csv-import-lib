<?php

/**
 * @file plugins/importexport/csv/shared/handlers/ZipExtractor.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ZipExtractor
 *
 * @ingroup plugins_importexport_csv
 *
 * @brief Safely extracts ZIP archives uploaded via the web GUI, with protection
 *        against path traversal, ZIP bombs (entry count, compression ratio, total size).
 */

namespace APP\plugins\importexport\csv\shared\handlers;

use APP\plugins\importexport\csv\shared\exceptions\ZipExtractionException;
use ZipArchive;

class ZipExtractor
{
    /** Maximum total uncompressed size allowed (2 GB) */
    public const MAX_TOTAL_BYTES = 2147483648;

    /** Maximum allowed compression ratio per entry */
    public const MAX_RATIO = 100;

    /** Maximum number of entries allowed in a ZIP */
    public const MAX_ENTRIES = 10000;

    /**
     * Extract a ZIP archive to a unique temp directory under $targetBaseDir.
     *
     * Validates the archive for path traversal, entry-count bombs, per-entry
     * compression-ratio bombs, and total-size bombs before extracting a single byte.
     *
     * @param string      $zipPath       Absolute path to the ZIP file.
     * @param string|null $targetBaseDir Base directory for the extraction target.
     *                                   Defaults to sys_get_temp_dir().
     *
     * @return string Absolute path to the newly-created extraction directory.
     *
     * @throws ZipExtractionException On corrupt archive, path traversal, or bomb detection.
     */
    public function extract(string $zipPath, ?string $targetBaseDir = null): string
    {
        $baseDir = $targetBaseDir ?? sys_get_temp_dir();

        $zip = new ZipArchive();
        $result = $zip->open($zipPath);

        if ($result !== true) {
            throw ZipExtractionException::corruptArchive($zipPath);
        }

        try {
            $entryCount = $zip->count();

            if ($entryCount > self::MAX_ENTRIES) {
                throw ZipExtractionException::bombDetected($entryCount, self::MAX_ENTRIES);
            }

            $totalUncompressed = 0;

            for ($i = 0; $i < $entryCount; $i++) {
                $stat = $zip->statIndex($i);

                if ($stat === false) {
                    continue;
                }

                $name = $stat['name'];

                if (self::zipEntryNameIsUnsafe($name)) {
                    throw ZipExtractionException::pathTraversal($name);
                }

                $compressedSize   = (int) $stat['comp_size'];
                $uncompressedSize = (int) $stat['size'];

                if ($compressedSize > 0) {
                    $ratio = $uncompressedSize / $compressedSize;
                    if ($ratio > self::MAX_RATIO) {
                        throw ZipExtractionException::bombDetected((int) $ratio, self::MAX_RATIO);
                    }
                }

                $totalUncompressed += $uncompressedSize;

                if ($totalUncompressed > self::MAX_TOTAL_BYTES) {
                    throw ZipExtractionException::bombDetected($totalUncompressed, self::MAX_TOTAL_BYTES);
                }
            }

            $extractDir = $baseDir . '/csv_import_' . bin2hex(random_bytes(8));
            mkdir($extractDir, 0700, true);

            if (!$zip->extractTo($extractDir)) {
                throw ZipExtractionException::corruptArchive($zipPath);
            }
        } finally {
            $zip->close();
        }

        return $extractDir;
    }

    /**
     * Reject ZIP entry names that escape the extraction directory.
     * Literal "..." in filenames is allowed; only ".." path segments are blocked.
     */
    private static function zipEntryNameIsUnsafe(string $name): bool
    {
        if (str_starts_with($name, '/') || str_contains($name, "\0")) {
            return true;
        }

        return preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $name) === 1;
    }

    /**
     * Resolve the import source directory from a ZIP extraction root.
     *
     * Allowed layouts (multiple CSV files at the allowed level are fine):
     * - CSV file(s) at the extract root, with optional asset files or subfolders.
     * - Exactly one content subfolder (ignoring {@see shouldSkipArchiveDirectory}) whose
     *   top level contains at least one CSV, with optional assets or subfolders inside it.
     *
     * @param string $extractDir Path returned by extract().
     *
     * @return string Resolved source directory.
     *
     * @throws ZipExtractionException When the layout does not match the rules above.
     */
    public static function resolveSourceDir(string $extractDir): string
    {
        if (self::directoryContainsCsvFile($extractDir)) {
            return $extractDir;
        }

        $contentSubdirs = [];
        foreach (array_diff(scandir($extractDir), ['.', '..']) as $entry) {
            $fullPath = $extractDir . '/' . $entry;
            if (!is_dir($fullPath) || self::shouldSkipArchiveDirectory($entry)) {
                continue;
            }
            $contentSubdirs[] = $fullPath;
        }

        if (count($contentSubdirs) !== 1) {
            throw ZipExtractionException::invalidLayout();
        }

        $wrapperDir = $contentSubdirs[0];
        if (!self::directoryContainsCsvFile($wrapperDir)) {
            throw ZipExtractionException::invalidLayout();
        }

        return $wrapperDir;
    }

    /**
     * Detect ZIP archives by extension or local file header (PK\x03\x04).
     */
    public static function isZipArchive(string $filePath, string $originalFileName = ''): bool
    {
        if (strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION)) === 'zip') {
            return true;
        }

        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            return false;
        }

        $magic = fread($handle, 4);
        fclose($handle);

        return $magic === "PK\x03\x04";
    }

    private static function shouldSkipArchiveDirectory(string $name): bool
    {
        return in_array($name, ['__MACOSX', '.git', 'node_modules'], true)
            || str_starts_with($name, '.');
    }

    private static function directoryContainsCsvFile(string $dir): bool
    {
        foreach (new \DirectoryIterator($dir) as $fileInfo) {
            if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'csv') {
                return true;
            }
        }

        return false;
    }

    /**
     * Delete extraction directories whose mtime is older than $ttlSeconds.
     *
     * Only removes subdirectories; files at the $baseDir root are left untouched.
     * Does nothing if $baseDir does not exist.
     *
     * @param string $baseDir    Directory that contains csv_import_* subdirectories.
     * @param int    $ttlSeconds Age threshold in seconds (default: 3600 = 1 hour).
     */
    public static function cleanupExpired(string $baseDir, int $ttlSeconds = 3600): void
    {
        if (!is_dir($baseDir)) {
            return;
        }

        $cutoff = time() - $ttlSeconds;
        $entries = array_diff(scandir($baseDir), ['.', '..']);

        foreach ($entries as $entry) {
            $fullPath = $baseDir . '/' . $entry;
            if (!is_dir($fullPath)) {
                continue;
            }

            if (filemtime($fullPath) < $cutoff) {
                static::deleteDirectory($fullPath);
            }
        }
    }

    /**
     * Recursively delete a directory and all its contents.
     *
     * @param string $dir Absolute path to the directory.
     */
    public static function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = array_diff(scandir($dir), ['.', '..']);
        foreach ($entries as $entry) {
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                static::deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
