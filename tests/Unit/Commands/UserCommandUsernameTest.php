<?php

/**
 * @file plugins/importexport/csv/shared/tests/Unit/Commands/UserCommandUsernameTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 */

namespace APP\plugins\importexport\csv\shared\tests\Unit\Commands;

use APP\plugins\importexport\csv\shared\tests\BaseTestCase;
use PKP\security\Validation;

class UserCommandUsernameTest extends BaseTestCase
{
    private const OJS_USERNAME_PATTERN = '/^[a-z0-9]+([\-_][a-z0-9]+)*$/';

    public function testSuggestUsernameForMultiWordFamilyNameHasNoSpaces(): void
    {
        $username = Validation::suggestUsername('Alex', 'North River');

        $this->assertStringNotContainsString(' ', $username);
        $this->assertMatchesRegularExpression(self::OJS_USERNAME_PATTERN, $username);
        $this->assertStringStartsWith('a', $username);
        $this->assertStringContainsString('north', $username);
        $this->assertStringContainsString('river', $username);
    }

    public function testSuggestUsernameMatchesOjsUsernameRules(): void
    {
        $cases = [
            ['Homer', 'Simpson'],
            ['Lisa', 'Simpson'],
            ['Christopher', 'Bartholomew'],
        ];

        foreach ($cases as [$firstname, $lastname]) {
            $username = Validation::suggestUsername($firstname, $lastname);
            $this->assertStringNotContainsString(' ', $username);
            $this->assertMatchesRegularExpression(self::OJS_USERNAME_PATTERN, $username);
        }
    }
}
