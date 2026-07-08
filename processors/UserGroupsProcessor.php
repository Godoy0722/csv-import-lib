<?php

/**
 * @file plugins/importexport/csv/shared/processors/UserGroupsProcessor.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class UserGroupsProcessor
 *
 * @ingroup plugins_importexport_csv
 *
 * @brief Process the user groups data into the database.
 */

namespace APP\plugins\importexport\csv\shared\processors;

use APP\facades\Repo;
use APP\plugins\importexport\csv\shared\cachedAttributes\CachedEntities;

class UserGroupsProcessor
{
    /**
     * Assign all given roles to a user. Used for new users.
     */
    public static function process(array $roles, int $userId, int $contextId, string $locale)
    {
        foreach ($roles as $role) {
            $userGroup = CachedEntities::getCachedUserGroupByName($role, $contextId, $locale);
            if ($userGroup) {
                Repo::userGroup()->assignUserToGroup($userId, $userGroup->id);
            }
        }
    }
}
