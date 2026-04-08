<?php

/**
 * @file plugins/importexport/csv/shared/cachedAttributes/CachedEntities.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class CachedEntities
 *
 * @ingroup plugins_importexport_csv
 *
 * @brief This class is responsible for retrieving cached entities such as
 * user groups, genres, categories and sections.
 */

namespace APP\plugins\importexport\csv\shared\cachedAttributes;

use APP\facades\Repo;
use APP\section\Section;
use PKP\category\Category;
use PKP\db\DAORegistry;
use PKP\security\Role;
use PKP\submission\GenreDAO;
use PKP\user\User;
use PKP\userGroup\UserGroup;

class CachedEntities
{
    /** @var array<string,int|null> */
    static array $userGroupIds = [];

    /** @var array<int,array<int,UserGroup>> */
    static array $userGroups = [];

    /** @var array<string,int|null> */
    static array $genreIds = [];

    /** @var array<string,Category|null> */
    static array $categories = [];

    /** @var array<string,Section|null> */
    static array $sections = [];

    /** @var array<string,User|null> */
    static array $users = [];

    /** Resets all cached entities. Used after dry-mode rollback to clear stale IDs. */
    public static function reset(): void
    {
        static::$userGroupIds = [];
        static::$userGroups = [];
        static::$genreIds = [];
        static::$categories = [];
        static::$sections = [];
        static::$users = [];
    }

    /** Retrieves a cached userGroup ID by contextId. Returns null if an error occurs. */
    static function getCachedAuthorUserGroupId(string $contextPath, int $contextId): ?int
    {
        // Cache null values as well, so repeated misses aren't retried
        return static::$userGroupIds[$contextPath] ??= Repo::userGroup()->getByRoleIds([Role::ROLE_ID_AUTHOR], $contextId)->first()?->id;
    }

    /** Retrieves a cached User by email. Returns null if an error occurs. */
    static function getCachedUserByEmail(string $email): ?User
    {
        if (!isset(static::$users[$email])) {
            $user = Repo::user()->getByEmail($email);

            if (!is_null($user)) {
                static::$users[$user->getUsername()] = $user;
            }

            static::$users[$email] = $user;
        }

        return static::$users[$email];
    }

    /** Retrieves a cached User by username. Returns null if an error occurs. */
    static function getCachedUserByUsername(string $username, bool $allowDisabled = false): ?User
    {
        if (!isset(static::$users[$username])) {
            $user = Repo::user()->getByUsername($username, $allowDisabled);

            if ($user) {
                static::$users[$user->getEmail()] = $user;
            }

            static::$users[$username] = $user;
        }

        return static::$users[$username];
    }

    /**
     * Retrieves a cached UserGroup by contextId. Returns null if an error occurs.
     *
     * @return UserGroup[]
     */
    static function getCachedUserGroupsByContextId(int $contextId): array
    {
        if (isset(static::$userGroups[$contextId])) {
            return static::$userGroups[$contextId];
        }

        $userGroups = [];
        $userGroupsCollection = UserGroup::withContextIds([$contextId])->get();

        foreach ($userGroupsCollection as $userGroup) {
            $userGroups[$userGroup->id] = $userGroup;
        }

        return static::$userGroups[$contextId] = $userGroups;
    }

    /** Retrieves a cached UserGroup by name and contextId. Returns null if an error occurs. */
    static function getCachedUserGroupByName(string $name, int $contextId, string $locale): ?UserGroup
    {
        $userGroups = static::getCachedUserGroupsByContextId($contextId);

        foreach ($userGroups as $userGroup) {
            if (mb_strtolower($userGroup->name[$locale]) === mb_strtolower($name)) {
                return $userGroup;
            }
        }

        return null;
    }

    /** Retrieves a cached genre ID by genreName and contextId. Returns null if an error occurs. */
    static function getCachedGenreId(string $genreName, int $contextId): ?int
    {
        $genreDao = DAORegistry::getDAO('GenreDAO'); /** @var GenreDAO $genreDao */
        return static::$genreIds[$genreName] ??= $genreDao->getByKey($genreName, $contextId)->getId();
    }

    /** Retrieves a cached Category by categoryName and contextId. Returns null if an error occurs. */
    static function getCachedCategory(string $categoryName, int $contextId): ?Category
    {
        if (isset(static::$categories[$categoryName])) {
            return static::$categories[$categoryName];
        }

        $categories = Repo::category()->getCollector()
            ->filterByContextIds([$contextId])
            ->getMany();

        foreach ($categories as $category) {
            if ($category->getPath() === $categoryName) {
                return static::$categories[$categoryName] = $category;
            }
        }

        return null;
    }

    /** Retrieves a cached Section by sectionTitle, sectionAbbrev, and contextId. Returns null if an error occurs. */
    static function getCachedSection(string $sectionTitle, string $sectionAbbrev, string $locale, int $contextId): ?Section
    {
        $customSectionKey = $sectionTitle . '_' . mb_strtoupper(trim($sectionAbbrev));

        if (isset(static::$sections[$customSectionKey])) {
            return static::$sections[$customSectionKey];
        }

        $sections = Repo::section()->getCollector()
            ->filterByContextIds([$contextId])
            ->getMany();

        foreach ($sections as $section) {
            if ($section->getAbbrev($locale) === $sectionAbbrev && $section->getTitle($locale) === $sectionTitle) {
                static::$sections["sectionId_{$section->getId()}"] = $section;
                return static::$sections[$customSectionKey] = $section;
            }
        }

        return null;
    }

    static function getCachedSectionById(int $baseSectionId, int $contextId, string $locale): ?Section
    {
        // Cache by ID first to avoid redundant lookups
        if (isset(static::$sections["sectionId_{$baseSectionId}"])) {
            return static::$sections["sectionId_{$baseSectionId}"];
        }

        $section = Repo::section()->get($baseSectionId, $contextId);
        if (!$section) {
            return null;
        }

        $sectionTitle = $section->getTitle($locale);
        $sectionAbbrev = $section->getAbbrev($locale);
        $customSectionKey = $sectionTitle . '_' . mb_strtoupper(trim($sectionAbbrev));

        static::$sections["sectionId_{$baseSectionId}"] = $section;
        static::$sections[$customSectionKey] = $section;

        return $section;
    }
}
