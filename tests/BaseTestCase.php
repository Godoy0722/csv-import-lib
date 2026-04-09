<?php

/**
 * @file plugins/importexport/csv/shared/tests/BaseTestCase.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class BaseTestCase
 *
 * @brief Base test case class for shared CSV Import/Export library tests
 */

namespace APP\plugins\importexport\csv\shared\tests;

use APP\author\Author;
use APP\publication\Publication;
use APP\section\Section;
use APP\submission\Submission;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PKP\context\Context;
use PKP\affiliation\Affiliation;
use PKP\affiliation\Repository as AffiliationRepository;
use PKP\author\DAO as AuthorDAO;
use PKP\author\Repository as AuthorRepository;
use PKP\category\Category;
use PKP\category\DAO as CategoryDAO;
use PKP\category\Repository as CategoryRepository;
use PKP\controlledVocab\Repository as ControlledVocabRepository;
use PKP\galley\DAO as GalleyDAO;
use PKP\galley\Galley;
use PKP\galley\Repository as GalleyRepository;
use APP\publication\Repository as PublicationRepository;
use PKP\publication\DAO as PublicationDAO;
use PKP\submission\DAO as SubmissionDAO;
use APP\submission\Repository as SubmissionRepository;
use PKP\submissionFile\DAO as SubmissionFileDAO;
use APP\submissionFile\Repository as SubmissionFileRepository;
use PKP\submissionFile\SubmissionFile;
use PKP\tests\PKPTestCase;
use PKP\user\DAO as UserDAO;
use PKP\user\Repository as UserRepository;
use PKP\user\User;
use PKP\userGroup\Repository as UserGroupRepository;
use PKP\userGroup\UserGroup;
use PKP\user\interest\Repository as UserInterestRepository;
use PKP\emailTemplate\Repository as EmailTemplateRepository;
use PKP\emailTemplate\EmailTemplate;

abstract class BaseTestCase extends PKPTestCase
{
    /**
     * @var array Backup of CachedEntities static properties
     */
    protected array $cachedEntitiesBackup = [];

    /**
     * @var array Store container instances to restore later
     */
    protected array $containerBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupCachedStatics();
    }

    protected function tearDown(): void
    {
        $this->restoreCachedStatics();
        $this->restoreContainerInstances();
        parent::tearDown();
        Mockery::close();
    }

    /**
     * Backup container instances before mocking
     */
    protected function backupContainerInstance(string $abstract): void
    {
        if (!isset($this->containerBackup[$abstract])) {
            try {
                $this->containerBackup[$abstract] = app($abstract);
            } catch (\Exception $e) {
                $this->containerBackup[$abstract] = null;
            }
        }
    }

    /**
     * Restore container instances after test
     */
    protected function restoreContainerInstances(): void
    {
        foreach ($this->containerBackup as $abstract => $instance) {
            if ($instance !== null) {
                app()->instance($abstract, $instance);
            }
        }
        $this->containerBackup = [];
    }

    /**
     * Backup static properties from CachedEntities
     */
    protected function backupCachedStatics(): void
    {
        $cachedEntitiesClass = \APP\plugins\importexport\csv\shared\cachedAttributes\CachedEntities::class;

        $this->cachedEntitiesBackup = [
            'userGroupIds' => $cachedEntitiesClass::$userGroupIds,
            'userGroups' => $cachedEntitiesClass::$userGroups,
            'genreIds' => $cachedEntitiesClass::$genreIds,
            'categories' => $cachedEntitiesClass::$categories,
            'sections' => $cachedEntitiesClass::$sections,
            'users' => $cachedEntitiesClass::$users,
        ];

        // Clear the caches for clean tests
        $cachedEntitiesClass::$userGroupIds = [];
        $cachedEntitiesClass::$userGroups = [];
        $cachedEntitiesClass::$genreIds = [];
        $cachedEntitiesClass::$categories = [];
        $cachedEntitiesClass::$sections = [];
        $cachedEntitiesClass::$users = [];
    }

    /**
     * Restore static properties to CachedEntities
     */
    protected function restoreCachedStatics(): void
    {
        $cachedEntitiesClass = \APP\plugins\importexport\csv\shared\cachedAttributes\CachedEntities::class;

        $cachedEntitiesClass::$userGroupIds = $this->cachedEntitiesBackup['userGroupIds'] ?? [];
        $cachedEntitiesClass::$userGroups = $this->cachedEntitiesBackup['userGroups'] ?? [];
        $cachedEntitiesClass::$genreIds = $this->cachedEntitiesBackup['genreIds'] ?? [];
        $cachedEntitiesClass::$categories = $this->cachedEntitiesBackup['categories'] ?? [];
        $cachedEntitiesClass::$sections = $this->cachedEntitiesBackup['sections'] ?? [];
        $cachedEntitiesClass::$users = $this->cachedEntitiesBackup['users'] ?? [];
    }

    // ==================== Repository Mock Helpers ====================

    protected function mockAuthorRepository(): MockInterface
    {
        $this->backupContainerInstance(AuthorRepository::class);

        $authorDaoMock = Mockery::mock(AuthorDAO::class)->makePartial();
        $authorDaoMock->shouldReceive('update')->andReturn(true);

        $mock = Mockery::mock(AuthorRepository::class)->makePartial();
        $mock->shouldReceive('newDataObject')->andReturnUsing(fn() => new Author());
        $mock->shouldReceive('add')->andReturn(1)->byDefault();
        $mock->shouldReceive('edit')->andReturn(true);
        $mock->dao = $authorDaoMock;

        app()->instance(AuthorRepository::class, $mock);
        return $mock;
    }

    protected function mockAffiliationRepository(): MockInterface
    {
        $this->backupContainerInstance(AffiliationRepository::class);

        $mock = Mockery::mock(AffiliationRepository::class)->makePartial();
        $mock->shouldReceive('newDataObject')->andReturnUsing(fn() => new Affiliation());

        app()->instance(AffiliationRepository::class, $mock);
        return $mock;
    }

    protected function mockUserRepository(): MockInterface
    {
        $this->backupContainerInstance(UserRepository::class);

        $userDaoMock = Mockery::mock(UserDAO::class)->makePartial();
        $userDaoMock->shouldReceive('getByEmail')->andReturn(null);
        $userDaoMock->shouldReceive('getByUsername')->andReturn(null);
        $userDaoMock->shouldReceive('insertObject')->andReturn(1);
        $userDaoMock->shouldReceive('updateObject')->andReturn(true);

        $mock = Mockery::mock(UserRepository::class)->makePartial();
        $mock->shouldReceive('newDataObject')->andReturnUsing(fn() => new User());
        $mock->shouldReceive('add')->andReturn(1)->byDefault();
        $mock->shouldReceive('get')->andReturn(null)->byDefault();
        $mock->shouldReceive('getByUsername')->andReturn(null)->byDefault();
        $mock->shouldReceive('getByEmail')->andReturn(null)->byDefault();
        $mock->shouldReceive('edit')->andReturn(true)->byDefault();
        $mock->dao = $userDaoMock;

        app()->instance(UserRepository::class, $mock);
        return $mock;
    }

    protected function mockPublicationRepository(): MockInterface
    {
        $this->backupContainerInstance(PublicationRepository::class);

        $publicationDaoMock = Mockery::mock(PublicationDAO::class)->makePartial();
        $publicationDaoMock->shouldReceive('update')->andReturn(true);
        $publicationDaoMock->shouldReceive('insert')->andReturn(1)->byDefault();

        $mock = Mockery::mock(PublicationRepository::class)->makePartial();
        $mock->shouldReceive('newDataObject')->andReturnUsing(fn() => new Publication());
        $mock->shouldReceive('add')->andReturn(1)->byDefault();
        $mock->shouldReceive('get')->andReturn(null)->byDefault();
        $mock->shouldReceive('edit')->andReturnUsing(fn($pub) => $pub)->byDefault();
        $mock->shouldReceive('assignCategoriesToPublication')->andReturnNull()->byDefault();
        $mock->dao = $publicationDaoMock;

        app()->instance(PublicationRepository::class, $mock);
        return $mock;
    }

    protected function mockSubmissionRepository(): MockInterface
    {
        $this->backupContainerInstance(SubmissionRepository::class);

        $submissionDaoMock = Mockery::mock(SubmissionDAO::class)->makePartial();
        $submissionDaoMock->shouldReceive('update')->andReturn(true);

        $mock = Mockery::mock(SubmissionRepository::class)->makePartial();
        $mock->shouldReceive('newDataObject')->andReturnUsing(fn() => new Submission());
        $mock->shouldReceive('add')->andReturn(1)->byDefault();
        $mock->shouldReceive('get')->andReturn(null)->byDefault();
        $mock->shouldReceive('edit')->andReturnNull()->byDefault();
        $mock->dao = $submissionDaoMock;

        app()->instance(SubmissionRepository::class, $mock);
        return $mock;
    }

    protected function mockCategoryRepository(): MockInterface
    {
        $this->backupContainerInstance(CategoryRepository::class);

        $categoryDaoMock = Mockery::mock(CategoryDAO::class)->makePartial();
        $categoryDaoMock->shouldReceive('insertObject')->andReturn(1);
        $categoryDaoMock->shouldReceive('update')->andReturn(true);

        $mock = Mockery::mock(CategoryRepository::class)->makePartial();
        $mock->shouldReceive('newDataObject')->andReturnUsing(fn() => new Category());
        $mock->shouldReceive('add')->andReturn(1)->byDefault();
        $mock->dao = $categoryDaoMock;

        app()->instance(CategoryRepository::class, $mock);
        return $mock;
    }

    protected function mockGalleyRepository(): MockInterface
    {
        $this->backupContainerInstance(GalleyRepository::class);

        $galleyDaoMock = Mockery::mock(GalleyDAO::class)->makePartial();
        $galleyDaoMock->shouldReceive('insert')->andReturn(1);

        $mock = Mockery::mock(GalleyRepository::class)->makePartial();
        $mock->shouldReceive('newDataObject')->andReturnUsing(fn() => new Galley());
        $mock->shouldReceive('add')->andReturn(1)->byDefault();
        $mock->dao = $galleyDaoMock;

        app()->instance(GalleyRepository::class, $mock);
        return $mock;
    }

    protected function mockSectionRepository(): MockInterface
    {
        $sectionRepoClass = \APP\section\Repository::class;
        $this->backupContainerInstance($sectionRepoClass);

        $sectionDaoMock = Mockery::mock(\APP\section\DAO::class)->makePartial();
        $sectionDaoMock->shouldReceive('insert')->andReturn(1);
        $sectionDaoMock->shouldReceive('update')->andReturn(true);

        $mock = Mockery::mock($sectionRepoClass)->makePartial();
        $mock->shouldReceive('newDataObject')->andReturnUsing(fn() => new Section());
        $mock->shouldReceive('add')->andReturn(1)->byDefault();
        $mock->shouldReceive('get')->andReturn(null)->byDefault();
        $mock->dao = $sectionDaoMock;

        app()->instance($sectionRepoClass, $mock);
        return $mock;
    }

    protected function createMockSectionCollector(array $sections = []): MockInterface
    {
        $collection = new \Illuminate\Support\LazyCollection($sections);
        $collector = Mockery::mock(\PKP\section\Collector::class);
        $collector->shouldReceive('filterByContextIds')->andReturnSelf();
        $collector->shouldReceive('getMany')->andReturn($collection);
        return $collector;
    }

    protected function mockSubmissionFileRepository(): MockInterface
    {
        $this->backupContainerInstance(SubmissionFileRepository::class);

        $submissionFileDaoMock = Mockery::mock(SubmissionFileDAO::class)->makePartial();
        $submissionFileDaoMock->shouldReceive('insert')->andReturn(1);

        $mock = Mockery::mock(SubmissionFileRepository::class)->makePartial();
        $mock->shouldReceive('newDataObject')->andReturnUsing(fn() => new SubmissionFile());
        $mock->shouldReceive('add')->andReturn(1)->byDefault();
        $mock->shouldReceive('get')->andReturn(null)->byDefault();
        $mock->shouldReceive('edit')->andReturnNull()->byDefault();
        $mock->dao = $submissionFileDaoMock;

        app()->instance(SubmissionFileRepository::class, $mock);
        return $mock;
    }

    protected function mockUserGroupRepository(): MockInterface
    {
        $this->backupContainerInstance(UserGroupRepository::class);

        $mock = Mockery::mock(UserGroupRepository::class)->makePartial();
        $mock->shouldReceive('assignUserToGroup')->andReturn(null)->byDefault();
        $mock->shouldReceive('userInGroup')->andReturn(false)->byDefault();

        app()->instance(UserGroupRepository::class, $mock);
        return $mock;
    }

    protected function mockControlledVocabRepository(): MockInterface
    {
        $this->backupContainerInstance(ControlledVocabRepository::class);

        $mock = Mockery::mock(ControlledVocabRepository::class)->makePartial();
        $mock->shouldReceive('insertBySymbolic')->andReturn(true);
        $mock->shouldReceive('getBySymbolic')->andReturn([]);

        app()->instance(ControlledVocabRepository::class, $mock);
        return $mock;
    }

    protected function mockUserInterestRepository(): MockInterface
    {
        $this->backupContainerInstance(UserInterestRepository::class);

        $mock = Mockery::mock(UserInterestRepository::class)->makePartial();
        $mock->shouldReceive('setInterestsForUser')->andReturnNull()->byDefault();

        app()->instance(UserInterestRepository::class, $mock);
        return $mock;
    }

    protected function mockEmailTemplateRepository(): MockInterface
    {
        $this->backupContainerInstance(EmailTemplateRepository::class);

        $mock = Mockery::mock(EmailTemplateRepository::class)->makePartial();
        $mock->shouldReceive('getByKey')->andReturn(null)->byDefault();

        app()->instance(EmailTemplateRepository::class, $mock);
        return $mock;
    }

    // ==================== Entity Creation Helpers ====================

    protected function createMockUser(array $data = []): User
    {
        $user = new User();
        $user->setId($data['id'] ?? 1);
        $user->setUsername($data['username'] ?? 'testuser');
        $user->setEmail($data['email'] ?? 'test@example.com');
        $user->setGivenName($data['givenName'] ?? 'Test', $data['locale'] ?? 'en');
        $user->setFamilyName($data['familyName'] ?? 'User', $data['locale'] ?? 'en');

        if (isset($data['affiliation'])) {
            $user->setAffiliation($data['affiliation'], $data['locale'] ?? 'en');
        }
        if (isset($data['country'])) {
            $user->setCountry($data['country']);
        }
        if (isset($data['orcid'])) {
            $user->setOrcid($data['orcid']);
        }

        return $user;
    }

    protected function createMockContext(array $data = []): MockInterface
    {
        $context = Mockery::mock(Context::class)->makePartial();

        $context->shouldReceive('getId')->andReturn($data['id'] ?? 1);
        $context->shouldReceive('getSupportedSubmissionLocales')
            ->andReturn($data['supportedLocales'] ?? ['en']);
        $context->shouldReceive('getPrimaryLocale')
            ->andReturn($data['primaryLocale'] ?? 'en');
        $context->shouldReceive('getContactEmail')
            ->andReturn($data['contactEmail'] ?? 'contact@example.com');
        $context->shouldReceive('getData')
            ->with('contactEmail')
            ->andReturn($data['contactEmail'] ?? 'contact@example.com');
        $context->shouldReceive('getData')
            ->with('contactName')
            ->andReturn($data['contactName'] ?? 'Test Admin');

        return $context;
    }

    protected function createMockPublication(array $data = []): Publication
    {
        $publication = new Publication();
        $publication->setId($data['id'] ?? 1);
        $publication->setData('submissionId', $data['submissionId'] ?? 1);
        $publication->setData('status', $data['status'] ?? Submission::STATUS_PUBLISHED);
        $publication->setData('version', $data['version'] ?? 1);

        if (isset($data['title'])) {
            $publication->setData('title', $data['title'], $data['locale'] ?? 'en');
        }
        if (isset($data['abstract'])) {
            $publication->setData('abstract', $data['abstract'], $data['locale'] ?? 'en');
        }
        if (isset($data['datePublished'])) {
            $publication->setData('datePublished', $data['datePublished']);
        }
        if (isset($data['sectionId'])) {
            $publication->setData('sectionId', $data['sectionId']);
        }
        if (isset($data['authors'])) {
            $publication->setData('authors', $data['authors']);
        }
        if (isset($data['keywords'])) {
            $publication->setData('keywords', $data['keywords']);
        }
        if (isset($data['subjects'])) {
            $publication->setData('subjects', $data['subjects']);
        }

        return $publication;
    }

    protected function createMockSubmission(array $data = []): Submission|MockObject
    {
        /** @var Submission|MockObject */
        $submission = $this->getMockBuilder(Submission::class)
            ->onlyMethods(['getCurrentPublication'])
            ->getMock();

        $submission->setId($data['id'] ?? 1);
        $submission->setData('contextId', $data['contextId'] ?? 1);
        $submission->setData('locale', $data['locale'] ?? 'en');
        $submission->setData('status', $data['status'] ?? Submission::STATUS_PUBLISHED);

        if (isset($data['currentPublication'])) {
            $submission->method('getCurrentPublication')->willReturn($data['currentPublication']);
        }

        return $submission;
    }

    protected function createMockAuthor(array $data = []): Author
    {
        $author = new Author();
        $author->setId($data['id'] ?? 1);
        $author->setData('publicationId', $data['publicationId'] ?? 1);
        $author->setSubmissionId($data['submissionId'] ?? 1);
        $author->setGivenName($data['givenName'] ?? 'John', $data['locale'] ?? 'en');
        $author->setFamilyName($data['familyName'] ?? 'Doe', $data['locale'] ?? 'en');
        $author->setEmail($data['email'] ?? 'author@example.com');

        if (isset($data['orcid'])) {
            $author->setOrcid($data['orcid']);
        }

        return $author;
    }

    protected function createMockSection(array $data = []): Section
    {
        $section = new Section();
        $section->setId($data['id'] ?? 1);
        $section->setContextId($data['contextId'] ?? 1);
        $section->setTitle($data['title'] ?? 'Test Section', $data['locale'] ?? 'en');
        $section->setAbbrev($data['abbrev'] ?? 'TS', $data['locale'] ?? 'en');
        $section->setIsInactive($data['isInactive'] ?? false);

        return $section;
    }

    protected function createMockCategory(array $data = []): Category
    {
        $category = new Category();
        $category->setId($data['id'] ?? 1);
        $category->setContextId($data['contextId'] ?? 1);
        $category->setTitle($data['title'] ?? 'Test Category', $data['locale'] ?? 'en');
        $category->setPath($data['path'] ?? 'test-category');

        return $category;
    }

    protected function createMockUserGroup(array $data = []): UserGroup
    {
        $userGroup = new UserGroup();
        $userGroup->id = $data['id'] ?? 1;
        $userGroup->contextId = $data['contextId'] ?? 1;
        $userGroup->roleId = $data['roleId'] ?? \PKP\security\Role::ROLE_ID_AUTHOR;
        $userGroup->name = $data['name'] ?? ['en' => 'Author'];

        return $userGroup;
    }

    protected function createMockGalley(array $data = []): Galley
    {
        $galley = new Galley();
        $galley->setId($data['id'] ?? 1);
        $galley->setData('publicationId', $data['publicationId'] ?? 1);
        $galley->setLabel($data['label'] ?? 'PDF');
        $galley->setLocale($data['locale'] ?? 'en');

        if (isset($data['submissionFileId'])) {
            $galley->setData('submissionFileId', $data['submissionFileId']);
        }

        return $galley;
    }

    // ==================== Database Helpers ====================

    protected function beginDatabaseTransaction(): void
    {
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0');
        \Illuminate\Support\Facades\DB::beginTransaction();
    }

    protected function rollbackDatabaseTransaction(): void
    {
        \Illuminate\Support\Facades\DB::rollBack();
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    // ==================== File System Helpers ====================

    protected function createTempDirectory(): string
    {
        $tempDir = sys_get_temp_dir() . '/csv_plugin_test_' . uniqid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }
        return $tempDir;
    }

    protected function cleanupTempDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->cleanupTempDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    protected function createTestCsvFile(string $dir, string $filename, array $headers, array $rows): string
    {
        $filepath = $dir . '/' . $filename;
        $file = fopen($filepath, 'w');
        fputcsv($file, $headers);
        foreach ($rows as $row) {
            fputcsv($file, $row);
        }
        fclose($file);
        return $filepath;
    }

    protected function createTestFile(string $dir, string $filename, string $content = ''): string
    {
        $filepath = $dir . '/' . $filename;
        file_put_contents($filepath, $content);
        return $filepath;
    }

    // ==================== Data Object Helpers ====================

    protected function createSubmissionDataObject(array $data): object
    {
        return (object) array_merge([
            'contextPath' => 'testcontext',
            'locale' => 'en',
            'versionIdentifier' => '',
            'version' => '',
            'prefix' => '',
            'title' => 'Test Submission',
            'subtitle' => '',
            'abstract' => 'Test abstract',
            'authors' => 'John,Doe,john@example.com,,Test University',
            'keywords' => '',
            'subjects' => '',
            'coverage' => '',
            'categories' => '',
            'doi' => '',
            'coverImageFilename' => '',
            'coverImageAltText' => '',
            'galleyFilenames' => '',
            'galleyLabels' => '',
            'suppFilenames' => '',
            'suppLabels' => '',
            'suppDescriptions' => '',
            'sectionTitle' => 'Articles',
            'sectionAbbrev' => 'ART',
            'datePublished' => '2024-01-15',
            'dateSubmitted' => '',
            'copyrightYear' => '',
            'copyrightHolder' => '',
            'licenseUrl' => '',
            'references' => '',
            'vorDoi' => '',
            'supportingAgencies' => '',
            'username' => '',
            'funders' => '',
        ], $data);
    }

    protected function createUserDataObject(array $data): object
    {
        return (object) array_merge([
            'contextPath' => 'testcontext',
            'firstname' => 'John',
            'lastname' => 'Doe',
            'email' => 'john@example.com',
            'affiliation' => '',
            'country' => '',
            'username' => 'jdoe',
            'tempPassword' => 'password123',
            'roles' => 'Author',
            'reviewInterests' => '',
            'orcid' => '',
        ], $data);
    }
}
