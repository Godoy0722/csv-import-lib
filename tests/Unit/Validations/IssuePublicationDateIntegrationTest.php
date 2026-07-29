<?php

/**
 * @file plugins/importexport/csv/shared/tests/Unit/Validations/IssuePublicationDateIntegrationTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class IssuePublicationDateIntegrationTest
 *
 * @brief Integration tests verifying issuePublicationDate flows through IssueProcessor to the Issue object.
 */

namespace APP\plugins\importexport\csv\shared\tests\Unit\Validations;

use APP\issue\Collector as IssueCollector;
use APP\issue\DAO as IssueDAO;
use APP\issue\Issue;
use APP\issue\Repository as IssueRepository;
use APP\plugins\importexport\csv\classes\cachedAttributes\CachedEntities;
use APP\plugins\importexport\csv\classes\processors\IssueProcessor;
use APP\plugins\importexport\csv\shared\tests\BaseTestCase;
use Illuminate\Support\LazyCollection;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\core\Core;

#[CoversClass(IssueProcessor::class)]
class IssuePublicationDateIntegrationTest extends BaseTestCase
{
    private array $issuesCacheBackup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->issuesCacheBackup = CachedEntities::$issues;
        CachedEntities::$issues = [];
    }

    protected function tearDown(): void
    {
        CachedEntities::$issues = $this->issuesCacheBackup;
        parent::tearDown();
    }

    private function createIssueRepositoryMock(): \Mockery\MockInterface
    {
        $collectorMock = Mockery::mock(IssueCollector::class);
        $collectorMock->shouldReceive('filterByContextIds')->andReturnSelf();
        $collectorMock->shouldReceive('filterByVolumes')->andReturnSelf();
        $collectorMock->shouldReceive('filterByNumbers')->andReturnSelf();
        $collectorMock->shouldReceive('filterByYears')->andReturnSelf();
        $collectorMock->shouldReceive('filterByTitles')->andReturnSelf();
        $collectorMock->shouldReceive('limit')->andReturnSelf();
        $collectorMock->shouldReceive('getMany')->andReturn(new LazyCollection([]));

        $issueDaoMock = Mockery::mock(IssueDAO::class)->makePartial();
        $issueDaoMock->shouldReceive('insert')->andReturn(1);
        $issueDaoMock->shouldReceive('update')->andReturn(true);

        $this->backupContainerInstance(IssueRepository::class);

        $mock = Mockery::mock(IssueRepository::class)->makePartial();
        $mock->shouldReceive('getCollector')->andReturn($collectorMock);
        $mock->shouldReceive('add')->andReturn(1);
        $mock->shouldReceive('get')->andReturnUsing(fn() => new Issue());
        $mock->dao = $issueDaoMock;

        app()->instance(IssueRepository::class, $mock);

        return $mock;
    }

    /**
     * When issuePublicationDate is provided, Issue should get that exact date.
     */
    public function testIssuePublicationDateIsSetOnIssueWhenProvided(): void
    {
        $data = (object)[
            'locale' => 'en',
            'issueTitle' => 'Integration Test Issue',
            'issueVolume' => '99',
            'issueNumber' => '99',
            'issueYear' => '2099',
            'issueDescription' => 'Testing issuePublicationDate flow',
            'issuePublicationDate' => '2024-06-15',
        ];

        $datePublished = null;
        $mock = $this->createIssueRepositoryMock();
        $mock->shouldReceive('newDataObject')->andReturnUsing(function () use (&$datePublished) {
            $issue = Mockery::mock(Issue::class)->makePartial();
            $issue->shouldReceive('setJournalId');
            $issue->shouldReceive('setShowVolume');
            $issue->shouldReceive('setShowNumber');
            $issue->shouldReceive('setShowYear');
            $issue->shouldReceive('setShowTitle');
            $issue->shouldReceive('setPublished');
            $issue->shouldReceive('setDatePublished')->andReturnUsing(function ($date) use (&$datePublished) {
                $datePublished = $date;
            });
            $issue->shouldReceive('setDescription');
            $issue->shouldReceive('setAccessStatus');
            $issue->shouldReceive('setData');
            $issue->shouldReceive('stampModified');
            $issue->shouldReceive('setVolume');
            $issue->shouldReceive('setNumber');
            $issue->shouldReceive('setYear');
            $issue->shouldReceive('setTitle');
            return $issue;
        });

        IssueProcessor::process(1, $data);

        $this->assertEquals('2024-06-15', $datePublished,
            'Issue should be created with the provided issuePublicationDate');
    }

    /**
     * When issuePublicationDate is empty, Issue should fall back to Core::getCurrentDate().
     */
    public function testIssueFallsBackToCurrentDateWhenIssuePublicationDateIsEmpty(): void
    {
        $data = (object)[
            'locale' => 'en',
            'issueTitle' => 'Fallback Test Issue',
            'issueVolume' => '88',
            'issueNumber' => '88',
            'issueYear' => '2088',
            'issueDescription' => 'Testing fallback date',
            'issuePublicationDate' => '',
        ];

        $currentDate = Core::getCurrentDate();
        $datePublished = null;
        $mock = $this->createIssueRepositoryMock();
        $mock->shouldReceive('newDataObject')->andReturnUsing(function () use (&$datePublished) {
            $issue = Mockery::mock(Issue::class)->makePartial();
            $issue->shouldReceive('setJournalId');
            $issue->shouldReceive('setShowVolume');
            $issue->shouldReceive('setShowNumber');
            $issue->shouldReceive('setShowYear');
            $issue->shouldReceive('setShowTitle');
            $issue->shouldReceive('setPublished');
            $issue->shouldReceive('setDatePublished')->andReturnUsing(function ($date) use (&$datePublished) {
                $datePublished = $date;
            });
            $issue->shouldReceive('setDescription');
            $issue->shouldReceive('setAccessStatus');
            $issue->shouldReceive('setData');
            $issue->shouldReceive('stampModified');
            $issue->shouldReceive('setVolume');
            $issue->shouldReceive('setNumber');
            $issue->shouldReceive('setYear');
            $issue->shouldReceive('setTitle');
            return $issue;
        });

        IssueProcessor::process(1, $data);

        $this->assertEquals($currentDate, $datePublished,
            'Issue should fall back to current date when issuePublicationDate is empty');
    }
}
