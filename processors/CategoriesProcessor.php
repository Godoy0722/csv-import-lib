<?php

/**
 * @file plugins/importexport/csv/classes/processors/CategoriesProcessor.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class CategoriesProcessor
 *
 * @ingroup plugins_importexport_csv
 *
 * @brief Processes the categories data into the database.
 */

namespace APP\plugins\importexport\csv\shared\processors;

use APP\facades\Repo;
use APP\plugins\importexport\csv\classes\cachedAttributes\CachedEntities;
use APP\publication\Publication;
use PKP\category\Category;
use PKP\publication\PublicationCategory;

class CategoriesProcessor
{
    public static function process(string $categories, string $locale, int $contextId, int $publicationId)
    {
        if (empty(trim($categories))) {
            return;
        }

        $categoriesArray = array_map('trim', explode(';', $categories));
        $publicationCategories = [];

        foreach ($categoriesArray as $categoryPath) {
            if (empty($categoryPath)) {
                continue;
            }

            $lowerCategoryPath = mb_strtolower($categoryPath);
            $category = CachedEntities::getCachedCategory($lowerCategoryPath, $contextId);

            if (!is_null($category)) {
                $categoryId = $category->getId();
                $publicationCategories[] = $categoryId;
                continue;
            }

            $category = $category = static::createNewCategory($contextId, $categoryPath, $locale);
            $categoryId = Repo::category()->add($category);
            $publicationCategories[] = $categoryId;
        }

        if (!empty($publicationCategories)) {
            Repo::publication()->assignCategoriesToPublication($publicationId, $publicationCategories);
        }

	}

    /**
     * Process categories for a versioned publication
     * Clears existing categories and adds new ones from CSV data or clones from base publication
     */
    public static function processForVersion(
        string $categories,
        string $locale,
        int $contextId,
        int $publicationId,
        ?Publication $basePublication = null
    ): void {
        PublicationCategory::where('publication_id', $publicationId)->delete();

        if (empty(trim($categories)) && !is_null($basePublication)) {
            $categoryIds = PublicationCategory::withPublicationId($basePublication->getId())->pluck('category_id')->toArray();
            if (!empty($categoryIds)) {
                Repo::publication()->assignCategoriesToPublication($publicationId, $categoryIds);
                return;
            }
        }

        static::process($categories, $locale, $contextId, $publicationId);
    }

    /**
     * Process categories for multi-locale import
     * This handles adding locale-specific data to existing categories
     */
    public static function processMultiLocale(string $categories, string $locale, int $contextId, int $publicationId): void
    {
        if (empty(trim($categories))) {
            return;
        }

        $categoriesArray = array_map('trim', explode(';', $categories));

        foreach ($categoriesArray as $categoryPath) {
            if (empty($categoryPath)) {
                continue;
            }

            $lowerCategoryPath = mb_strtolower($categoryPath);
            $category = CachedEntities::getCachedCategory($lowerCategoryPath, $contextId);

            if (!is_null($category)) {
                $existingTitle = $category->getLocalizedData('title', $locale);

                if (empty($existingTitle) || $existingTitle !== $categoryPath) {
                    $category->setTitle($categoryPath, $locale);
                    Repo::category()->dao->update($category);
                }

                continue;
            }

            $category = static::createNewCategory($contextId, $categoryPath, $locale);
            $categoryId = Repo::category()->add($category);

            $existingCategoryIds = PublicationCategory::withPublicationId($publicationId)
                ->pluck('category_id')
                ->toArray();

            if (!in_array($categoryId, $existingCategoryIds)) {
                Repo::publication()->assignCategoriesToPublication($publicationId, array_merge($existingCategoryIds, [$categoryId]));
            }
        }
    }

    private static function createNewCategory(int $contextId, string $categoryPath, string $locale): Category
    {
        $category = Repo::category()->newDataObject();
        $category->setContextId($contextId);
        $category->setTitle($categoryPath, $locale);
        $category->setParentId(null);
        $category->setSequence(REALLY_BIG_NUMBER);
        $category->setPath(mb_strtolower($categoryPath));

        return $category;
    }
}
