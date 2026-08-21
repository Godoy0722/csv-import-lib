<?php

/**
 * @file plugins/importexport/csv/shared/tests/Unit/Processors/ChaptersProcessorTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ChaptersProcessorTest
 *
 * @brief Tests for the ChaptersProcessor chapter lookup and creation logic
 */

namespace APP\plugins\importexport\csv\shared\tests\Unit\Processors;

use APP\monograph\Chapter;
use APP\monograph\ChapterDAO;
use APP\plugins\importexport\csv\classes\cachedAttributes\CachedDaos;
use APP\plugins\importexport\csv\classes\cachedAttributes\CachedEntities;
use APP\plugins\importexport\csv\classes\processors\ChaptersProcessor;
use APP\plugins\importexport\csv\shared\exceptions\RowValidationException;
use APP\plugins\importexport\csv\shared\processors\AuthorsProcessor;
use APP\plugins\importexport\csv\shared\tests\BaseTestCase;
use APP\publication\Publication;
use APP\submissionFile\Repository as SubmissionFileRepository;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\db\DAORegistry;
use PKP\db\DAOResultFactory;
use PKP\file\FileManager;
use PKP\services\PKPFileService;
use PKP\submissionFile\DAO as SubmissionFileDAO;
use PKP\submissionFile\SubmissionFile;
use PKP\user\User;

#[CoversClass(ChaptersProcessor::class)]
class ChaptersProcessorTest extends BaseTestCase
{
    /** @var MockInterface ChapterDAO mock registered under 'ChapterDAO' */
    private MockInterface $chapterDao;

    /** @var int Number of times getByPublicationId was invoked */
    private int $scanCount = 0;

    /** @var Chapter[] Chapters returned by getByPublicationId */
    private array $existingChapters = [];

    /** @var Chapter[] Chapters captured by insertChapter */
    private array $createdChapters = [];

    /** @var string Temp source dir with dummy chapter files */
    private string $sourceDir;

    protected function setUp(): void
    {
        parent::setUp();

        CachedDaos::$cachedDaos = [];
        CachedEntities::$chapters = [];
        AuthorsProcessor::$csvAuthorEntryToId = [];
        $this->scanCount = 0;
        $this->existingChapters = [];
        $this->createdChapters = [];
        $this->sourceDir = sys_get_temp_dir() . '/csv_processor_tests_' . uniqid();
        mkdir($this->sourceDir);
        file_put_contents($this->sourceDir . '/chapter1.pdf', 'dummy');
        file_put_contents($this->sourceDir . '/chapter2.pdf', 'dummy');

        $this->chapterDao = Mockery::mock(ChapterDAO::class);
        $this->chapterDao->shouldReceive('newDataObject')
            ->andReturnUsing(fn() => new Chapter());
        $this->chapterDao->shouldReceive('getByPublicationId')
            ->andReturnUsing(function () {
                ++$this->scanCount;
                $result = Mockery::mock(DAOResultFactory::class);
                $result->shouldReceive('toArray')
                    ->andReturn($this->existingChapters);
                return $result;
            });
        $this->chapterDao->shouldReceive('insertChapter')
            ->andReturnUsing(function (Chapter $chapter) {
                $chapter->setId(99);
                $this->createdChapters[] = $chapter;
                return 99;
            });
        $this->chapterDao->shouldReceive('updateObject')
            ->andReturn(true);
        $this->chapterDao->shouldReceive('resequenceChapters')
            ->andReturnNull();

        DAORegistry::registerDAO('ChapterDAO', $this->chapterDao);
    }

    protected function tearDown(): void
    {
        DAORegistry::registerDAO('ChapterDAO', null);
        CachedDaos::$cachedDaos = [];
        CachedEntities::$chapters = [];

        unlink($this->sourceDir . '/chapter1.pdf');
        unlink($this->sourceDir . '/chapter2.pdf');
        rmdir($this->sourceDir);

        parent::tearDown();
    }

    private function mockPublication(): Publication
    {
        $publication = Mockery::mock(Publication::class);
        $publication->shouldReceive('getId')->andReturn(5);
        return $publication;
    }

    private function existingChapter(string $title, string $locale): Chapter
    {
        $chapter = new Chapter();
        $chapter->setId(7);
        $chapter->setData('publicationId', 5);
        $chapter->setTitle($title, $locale);
        return $chapter;
    }

    /**
     * Rows without chapter data return null without touching the database.
     */
    public function testProcessReturnsNullWhenNoChapterData(): void
    {
        $result = ChaptersProcessor::process(
            (object) ['chapterTitle' => null, 'chapterFiles' => null, 'locale' => 'en'],
            $this->mockPublication(),
            sys_get_temp_dir(),
            false,
            []
        );

        $this->assertNull($result);
        $this->assertSame(0, $this->scanCount);
    }

    /**
     * Creates a new chapter when no chapter with the title in that locale exists.
     */
    public function testFindOrCreateChapterCreatesWhenNotFound(): void
    {
        $chapter = ChaptersProcessor::findOrCreateChapter('Introduction', 'en', $this->mockPublication());

        $this->assertInstanceOf(Chapter::class, $chapter);
        $this->assertSame(99, $chapter->getId());
        $this->assertSame(5, $chapter->getData('publicationId'));
        $this->assertSame('Introduction', $chapter->getTitle('en'));
        // The edit form reads the abstract through a strict string|array getter,
        // so a freshly imported chapter must carry an (empty) abstract value.
        $this->assertSame('', $chapter->getAbstract('en'));
    }

    /**
     * Reuses an existing chapter with the same title in the same locale.
     */
    public function testFindOrCreateChapterReusesExistingChapter(): void
    {
        $this->existingChapters = [$this->existingChapter('Introduction', 'en')];

        $chapter = ChaptersProcessor::findOrCreateChapter('Introduction', 'en', $this->mockPublication());

        $this->assertSame(7, $chapter->getId());
    }

    /**
     * A chapter with the same title but in a different locale is not reused
     * (same-locale matching only).
     */
    public function testFindOrCreateChapterDoesNotReuseChapterFromOtherLocale(): void
    {
        $this->existingChapters = [$this->existingChapter('Introduction', 'pt_BR')];

        $chapter = ChaptersProcessor::findOrCreateChapter('Introduction', 'en', $this->mockPublication());

        $this->assertSame(99, $chapter->getId());
    }

    /**
     * The created chapter is cached — a second lookup does not rescan the database.
     */
    public function testFindOrCreateChapterCachesResult(): void
    {
        $publication = $this->mockPublication();

        $first = ChaptersProcessor::findOrCreateChapter('Introduction', 'en', $publication);
        $second = ChaptersProcessor::findOrCreateChapter('Introduction', 'en', $publication);

        $this->assertSame($first->getId(), $second->getId());
        $this->assertSame(1, $this->scanCount);
    }

    /**
     * One row with three titles creates three chapters; subtitle/abstract land
     * only on the positions that name them (empty slots stay empty).
     */
    public function testProcessCreatesMultipleChaptersWithPositionalValues(): void
    {
        $chapter = ChaptersProcessor::process(
            (object) [
                'chapterTitle' => 'Intro;Methods;Conclusion',
                'chapterSubtitle' => 'Intro Sub;;End Sub',
                'chapterAbstract' => 'Intro Abs;;',
                'chapterFiles' => '',
                'chapterContributors' => '',
                'locale' => 'en',
            ],
            $this->mockPublication(),
            $this->sourceDir,
            false,
            []
        );

        $this->assertCount(3, $this->createdChapters);

        $this->assertSame('Intro', $this->createdChapters[0]->getTitle('en'));
        $this->assertSame('Intro Sub', $this->createdChapters[0]->getData('subtitle', 'en'));
        $this->assertSame('Intro Abs', $this->createdChapters[0]->getAbstract('en'));

        $this->assertSame('Methods', $this->createdChapters[1]->getTitle('en'));
        $this->assertNull($this->createdChapters[1]->getData('subtitle', 'en'));
        $this->assertSame('', $this->createdChapters[1]->getAbstract('en'));

        $this->assertSame('Conclusion', $this->createdChapters[2]->getTitle('en'));
        $this->assertSame('End Sub', $this->createdChapters[2]->getData('subtitle', 'en'));
        $this->assertSame('', $this->createdChapters[2]->getAbstract('en'));

        $this->assertSame($this->createdChapters[2], $chapter);
    }

    /**
     * A reused chapter keeps its existing abstract and subtitle when the row
     * provides no values for them.
     */
    public function testProcessKeepsExistingAbstractAndSubtitleWhenRowValuesEmpty(): void
    {
        $existing = $this->existingChapter('Intro', 'en');
        $existing->setAbstract('Existing Abs', 'en');
        $existing->setSubtitle('Existing Sub', 'en');
        $this->existingChapters = [$existing];

        $chapter = ChaptersProcessor::process(
            (object) [
                'chapterTitle' => 'Intro',
                'chapterSubtitle' => '',
                'chapterAbstract' => '',
                'chapterFiles' => '',
                'chapterContributors' => '',
                'locale' => 'en',
            ],
            $this->mockPublication(),
            $this->sourceDir,
            false,
            []
        );

        $this->assertSame('Existing Abs', $chapter->getAbstract('en'));
        $this->assertSame('Existing Sub', $chapter->getData('subtitle', 'en'));

        $this->chapterDao->shouldNotReceive('updateObject');
    }

    /**
     * A reused chapter gets updated abstract and subtitle when the row provides them.
     */
    public function testProcessUpdatesReusedChapterWithProvidedValues(): void
    {
        $existing = $this->existingChapter('Intro', 'en');
        $existing->setAbstract('Old Abs', 'en');
        $this->existingChapters = [$existing];

        $chapter = ChaptersProcessor::process(
            (object) [
                'chapterTitle' => 'Intro',
                'chapterSubtitle' => 'New Sub',
                'chapterAbstract' => 'New Abs',
                'chapterFiles' => '',
                'chapterContributors' => '',
                'locale' => 'en',
            ],
            $this->mockPublication(),
            $this->sourceDir,
            false,
            []
        );

        $this->assertSame('New Abs', $chapter->getAbstract('en'));
        $this->assertSame('New Sub', $chapter->getData('subtitle', 'en'));
        $this->chapterDao->shouldHaveReceived('updateObject')->once();
    }

    /**
     * A contributor that is an exact copy of an authors entry is linked to the
     * chapter through the author the entry produced (CSV-entry keyed map).
     */
    public function testProcessLinksMatchingContributorsToChapter(): void
    {
        AuthorsProcessor::$csvAuthorEntryToId = [
            'John,Doe,john@example.com,0000-0002-1825-0097,Example Institute,Bio text' => 5,
        ];

        // Deviation: mockAuthorRepository() registers under PKP\author\Repository,
        // but Repo::author() resolves APP\author\Repository (see Repo::author()),
        // so the mock must be created from and registered under the APP class.
        // The same mismatch causes 7 pre-existing errors in AuthorsProcessorTest.
        $this->backupContainerInstance(\APP\author\Repository::class);
        $authorRepo = Mockery::mock(\APP\author\Repository::class)->makePartial();
        $authorRepo->shouldReceive('removeChapterAuthors')->andReturnNull();
        $authorRepo->shouldReceive('addToChapter')->andReturnNull();
        app()->instance(\APP\author\Repository::class, $authorRepo);

        ChaptersProcessor::process(
            (object) [
                'chapterTitle' => 'Intro',
                'chapterSubtitle' => '',
                'chapterAbstract' => '',
                'chapterFiles' => '',
                'chapterContributors' => 'John,Doe,john@example.com,0000-0002-1825-0097,Example Institute,Bio text',
                'authors' => 'John,Doe,john@example.com,0000-0002-1825-0097,Example Institute,Bio text',
                'locale' => 'en',
            ],
            $this->mockPublication(),
            $this->sourceDir,
            false,
            []
        );

        $authorRepo->shouldHaveReceived('removeChapterAuthors')->once()
            ->with(Mockery::on(fn(Chapter $chapter) => $chapter->getId() === 99));
        $authorRepo->shouldHaveReceived('addToChapter')->once()
            ->with(5, 99, false, 0);

        $this->addToAssertionCount(1);
    }

    /**
     * A contributor whose entry has no matching author entry (map miss) fails the row.
     */
    public function testProcessThrowsWhenContributorHasNoAuthorEntry(): void
    {
        AuthorsProcessor::$csvAuthorEntryToId = [];

        $this->expectException(RowValidationException::class);

        ChaptersProcessor::process(
            (object) [
                'chapterTitle' => 'Intro',
                'chapterSubtitle' => '',
                'chapterAbstract' => '',
                'chapterFiles' => '',
                'chapterContributors' => 'John,Doe,john@example.com,,,',
                'authors' => 'John,Doe,john@example.com,,,',
                'locale' => 'en',
            ],
            $this->mockPublication(),
            $this->sourceDir,
            false,
            []
        );
    }

    /**
     * An empty contributor group leaves the chapter's contributors untouched.
     */
    public function testProcessLeavesContributorsUntouchedWhenGroupEmpty(): void
    {
        $this->backupContainerInstance(\APP\author\Repository::class);
        $authorRepo = Mockery::mock(\APP\author\Repository::class)->makePartial();
        $authorRepo->shouldNotReceive('removeChapterAuthors');
        $authorRepo->shouldNotReceive('addToChapter');
        app()->instance(\APP\author\Repository::class, $authorRepo);

        ChaptersProcessor::process(
            (object) [
                'chapterTitle' => 'Intro',
                'chapterSubtitle' => '',
                'chapterAbstract' => '',
                'chapterFiles' => '',
                'chapterContributors' => '',
                'locale' => 'en',
            ],
            $this->mockPublication(),
            $this->sourceDir,
            false,
            ['contactEmail' => 'press@example.com']
        );

        $this->addToAssertionCount(1);
    }

    /**
     * Files are positional: ';chapter2.pdf' attaches a file only to the second chapter.
     */
    public function testProcessAttachesFilesOnlyToMatchingPositions(): void
    {
        $this->backupContainerInstance(SubmissionFileRepository::class);

        $submissionFileDao = Mockery::mock(SubmissionFileDAO::class)->makePartial();
        $submissionFileDao->shouldReceive('updateChapterFiles')->andReturnNull();

        $submissionFileRepo = Mockery::mock(SubmissionFileRepository::class)->makePartial();
        $submissionFileRepo->shouldReceive('newDataObject')->andReturnUsing(fn() => new SubmissionFile());
        $capturedFile = null;
        $submissionFileRepo->shouldReceive('add')->andReturnUsing(function (SubmissionFile $submissionFile) use (&$capturedFile) {
            $submissionFile->setId(10);
            $capturedFile = $submissionFile;
            return 10;
        });
        $submissionFileRepo->dao = $submissionFileDao;
        app()->instance(SubmissionFileRepository::class, $submissionFileRepo);

        $fileService = Mockery::mock(PKPFileService::class);
        $fileService->shouldReceive('add')->andReturn(1);

        $fileManager = Mockery::mock(FileManager::class);
        $fileManager->shouldReceive('parseFileExtension')->andReturn('pdf');
        $fileManager->shouldReceive('getDocumentType')->andReturn('application/pdf');

        $user = Mockery::mock(User::class);
        $user->shouldReceive('getId')->andReturn(1);

        ChaptersProcessor::process(
            (object) [
                'chapterTitle' => 'Intro;Methods',
                'chapterSubtitle' => '',
                'chapterAbstract' => '',
                'chapterFiles' => ';chapter2.pdf',
                'chapterContributors' => '',
                'locale' => 'en',
            ],
            $this->mockPublication(),
            $this->sourceDir,
            false,
            [
                'fileManager' => $fileManager,
                'fileService' => $fileService,
                'submissionId' => 10,
                'pressId' => 1,
                'genreId' => 2,
                'user' => $user,
                'locale' => 'en',
                'format' => '%d/%d',
            ]
        );

        $fileService->shouldHaveReceived('add')->once();
        $submissionFileDao->shouldHaveReceived('updateChapterFiles')->once()->with([10], 99);
        $this->assertSame(SubmissionFile::SUBMISSION_FILE_PRODUCTION_READY, $capturedFile->getData('fileStage'));

        $this->addToAssertionCount(1);
    }

    /**
     * Comma separates files within one chapter: both files attach to the same chapter.
     */
    public function testProcessAttachesMultipleFilesToSingleChapter(): void
    {
        $this->backupContainerInstance(SubmissionFileRepository::class);

        $submissionFileDao = Mockery::mock(SubmissionFileDAO::class)->makePartial();
        $submissionFileDao->shouldReceive('updateChapterFiles')->andReturnNull();

        $submissionFileRepo = Mockery::mock(SubmissionFileRepository::class)->makePartial();
        $submissionFileRepo->shouldReceive('newDataObject')->andReturnUsing(fn() => new SubmissionFile());
        $capturedFile = null;
        $submissionFileRepo->shouldReceive('add')->andReturnUsing(function (SubmissionFile $submissionFile) use (&$capturedFile) {
            $submissionFile->setId(10);
            $capturedFile = $submissionFile;
            return 10;
        });
        $submissionFileRepo->dao = $submissionFileDao;
        app()->instance(SubmissionFileRepository::class, $submissionFileRepo);

        $fileService = Mockery::mock(PKPFileService::class);
        $fileService->shouldReceive('add')->andReturn(1);

        $fileManager = Mockery::mock(FileManager::class);
        $fileManager->shouldReceive('parseFileExtension')->andReturn('pdf');
        $fileManager->shouldReceive('getDocumentType')->andReturn('application/pdf');

        $user = Mockery::mock(User::class);
        $user->shouldReceive('getId')->andReturn(1);

        ChaptersProcessor::process(
            (object) [
                'chapterTitle' => 'Intro',
                'chapterSubtitle' => '',
                'chapterAbstract' => '',
                'chapterFiles' => 'chapter2.pdf,chapter1.pdf',
                'chapterContributors' => '',
                'locale' => 'en',
            ],
            $this->mockPublication(),
            $this->sourceDir,
            false,
            [
                'fileManager' => $fileManager,
                'fileService' => $fileService,
                'submissionId' => 10,
                'pressId' => 1,
                'genreId' => 2,
                'user' => $user,
                'locale' => 'en',
                'format' => '%d/%d',
            ]
        );

        $fileService->shouldHaveReceived('add')->twice();
        $submissionFileDao->shouldHaveReceived('updateChapterFiles')->once()->with([10, 10], 99);
        $this->assertSame(SubmissionFile::SUBMISSION_FILE_PRODUCTION_READY, $capturedFile->getData('fileStage'));

        $this->addToAssertionCount(1);
    }
}
