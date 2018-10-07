<?php

declare(strict_types=1);

/*
 * This file is part of the ApiTestCase package.
 *
 * (c) Łukasz Chruściel
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ApiTestCase;

use Coduo\PHPMatcher\Matcher;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManager;
use Fidry\AliceDataFixtures\LoaderInterface;
use Fidry\AliceDataFixtures\ProcessorInterface;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ResettableContainerInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;

abstract class ApiTestCase extends WebTestCase
{
    /** @var Kernel */
    protected static $sharedKernel;

    /** @var Client */
    protected $client;

    /** @var string */
    protected $expectedResponsesPath;

    /** @var string */
    protected $mockedResponsesPath;

    /** @var string */
    protected $dataFixturesPath;

    /** @var LoaderInterface */
    private $fixtureLoader;

    /** @var EntityManager */
    private $entityManager;

    /**
     * @beforeClass
     */
    public static function createSharedKernel(): void
    {
        static::$sharedKernel = static::createKernel(['debug' => false]);
        static::$sharedKernel->boot();
    }

    /**
     * @afterClass
     */
    public static function ensureSharedKernelShutdown(): void
    {
        if (null !== static::$sharedKernel) {
            $container = static::$sharedKernel->getContainer();
            static::$sharedKernel->shutdown();
            if ($container instanceof ResettableContainerInterface) {
                $container->reset();
            }
        }
    }

    /**
     * @before
     */
    public function setUpClient(): void
    {
        $this->client = static::createClient(['debug' => false]);
    }

    /**
     * @before
     */
    public function setUpDatabase(): void
    {
        if (isset($_SERVER['IS_DOCTRINE_ORM_SUPPORTED']) && $_SERVER['IS_DOCTRINE_ORM_SUPPORTED']) {
            $this->entityManager = static::$sharedKernel->getContainer()->get('doctrine.orm.entity_manager');
            $this->entityManager->getConnection()->connect();

            $this->fixtureLoader = static::$sharedKernel->getContainer()->get('fidry_alice_data_fixtures.loader.doctrine');

            $this->purgeDatabase();
        }
    }

    public function tearDown(): void
    {
        if (null !== $this->client && null !== $this->client->getContainer()) {
            foreach (array_keys($this->client->getContainer()->getMockedServices()) as $id) {
                $this->client->getContainer()->unmock($id);
            }
        }

        \Mockery::close();
        $this->client = null;
        $this->entityManager = null;
        $this->fixtureLoader = null;

        parent::tearDown();
    }

    abstract protected function buildMatcher(): Matcher;

    /**
     * @return array|ProcessorInterface[]
     */
    protected function getFixtureProcessors(): array
    {
        return [];
    }

    protected static function getKernelClass(): string
    {
        if (isset($_SERVER['KERNEL_CLASS'])) {
            return '\\' . ltrim($_SERVER['KERNEL_CLASS'], '\\');
        }

        return parent::getKernelClass();
    }

    protected function purgeDatabase(): void
    {
        $purger = new ORMPurger($this->getEntityManager());
        $purger->purge();

        $this->getEntityManager()->clear();
    }

    /**
     * Gets service from DIC.
     */
    protected function get(string $id): object
    {
        return $this->client->getContainer()->get($id);
    }

    protected function assertResponseCode(Response $response, int $statusCode): void
    {
        self::assertEquals($statusCode, $response->getStatusCode(), $response->getContent());
    }

    protected function assertHeader(Response $response, string $contentType): void
    {
        self::assertContains(
            $contentType,
            $response->headers->get('Content-Type')
        );
    }

    protected function assertResponseContent(string $actualResponse, string $filename, string $mimeType): void
    {
        $responseSource = $this->getExpectedResponsesFolder();

        $actualResponse = trim($actualResponse);
        $expectedResponse = trim(file_get_contents(PathBuilder::build($responseSource, sprintf('%s.%s', $filename, $mimeType))));

        $matcher = $this->buildMatcher();
        $result = $matcher->match($actualResponse, $expectedResponse);

        if (!$result) {
            $diff = new \Diff(explode(\PHP_EOL, $expectedResponse), explode(\PHP_EOL, $actualResponse), []);

            self::fail($matcher->getError() . \PHP_EOL . $diff->render(new \Diff_Renderer_Text_Unified()));
        }
    }

    /**
     * @throws \Exception
     */
    protected function showErrorInBrowserIfOccurred(Response $response): void
    {
        if (!$response->isSuccessful()) {
            $openCommand = $_SERVER['OPEN_BROWSER_COMMAND'] ?? 'open %s';
            $tmpDir = $_SERVER['TMP_DIR'] ?? sys_get_temp_dir();

            $filename = PathBuilder::build(rtrim($tmpDir, \DIRECTORY_SEPARATOR), uniqid() . '.html');
            file_put_contents($filename, $response->getContent());
            system(sprintf($openCommand, escapeshellarg($filename)));

            throw new \Exception('Internal server error.');
        }
    }

    /**
     * Provides array from decoded json file. Requires MOCKED_RESPONSE_DIR defined variable to work properly.
     *
     * @throws \Exception
     */
    protected function getJsonResponseFixture(string $filename): array
    {
        $responseSource = $this->getMockedResponsesFolder();

        return json_decode(file_get_contents(PathBuilder::build($responseSource, $filename . '.json')), true);
    }

    protected function loadFixturesFromDirectory(string $source = ''): array
    {
        $source = $this->getFixtureRealPath($source);
        $this->assertSourceExists($source);

        $finder = new Finder();
        $finder->files()->name('*.yml')->in($source);

        if (0 === $finder->count()) {
            throw new \RuntimeException(sprintf('There is no files to load in folder %s', $source));
        }

        $files = [];
        foreach ($finder as $file) {
            $files[] = $file->getRealPath();
        }

        return $this->getFixtureLoader()->load($files);
    }

    protected function loadFixturesFromFile(string $source): array
    {
        $source = $this->getFixtureRealPath($source);
        $this->assertSourceExists($source);

        return $this->getFixtureLoader()->load([$source]);
    }

    /**
     * @param array|string[] $sources
     *
     * @return array|object[]
     */
    protected function loadFixturesFromFiles(array $sources): array
    {
        $realPaths = [];

        foreach ($sources as $source) {
            $source = $this->getFixtureRealPath($source);
            $this->assertSourceExists($source);

            $realPaths[] = $source;
        }

        return $this->getFixtureLoader()->load($realPaths);
    }

    protected function getFixtureLoader(): LoaderInterface
    {
        if (null === $this->fixtureLoader) {
            throw new \RuntimeException('Please, set up a database before you will try to use a fixture loader');
        }

        return $this->fixtureLoader;
    }

    protected function getEntityManager(): EntityManager
    {
        if (null === $this->entityManager || !$this->entityManager->getConnection()->isConnected()) {
            static::fail('Could not establish test database connection.');
        }

        return $this->entityManager;
    }

    private function getFixtureRealPath(string $source): string
    {
        $baseDirectory = $this->getFixturesFolder();

        return PathBuilder::build($baseDirectory, $source);
    }

    private function getFixturesFolder(): string
    {
        if (null === $this->dataFixturesPath) {
            $this->dataFixturesPath = isset($_SERVER['FIXTURES_DIR']) ?
                PathBuilder::build($this->getRootDir(), $_SERVER['FIXTURES_DIR']) :
                PathBuilder::build($this->getCalledClassFolder(), '..', 'DataFixtures', 'ORM');
        }

        return $this->dataFixturesPath;
    }

    private function getExpectedResponsesFolder(): string
    {
        if (null === $this->expectedResponsesPath) {
            $this->expectedResponsesPath = isset($_SERVER['EXPECTED_RESPONSE_DIR']) ?
                PathBuilder::build($this->getRootDir(), $_SERVER['EXPECTED_RESPONSE_DIR']) :
                PathBuilder::build($this->getCalledClassFolder(), '..', 'Responses', 'Expected');
        }

        return $this->expectedResponsesPath;
    }

    private function getMockedResponsesFolder(): string
    {
        if (null === $this->mockedResponsesPath) {
            $this->mockedResponsesPath = isset($_SERVER['MOCKED_RESPONSE_DIR']) ?
                PathBuilder::build($this->getRootDir(), $_SERVER['MOCKED_RESPONSE_DIR']) :
                PathBuilder::build($this->getCalledClassFolder(), '..', 'Responses', 'Mocked');
        }

        return $this->mockedResponsesPath;
    }

    private function getCalledClassFolder(): string
    {
        $calledClass = get_called_class();
        $calledClassFolder = dirname((new \ReflectionClass($calledClass))->getFileName());

        $this->assertSourceExists($calledClassFolder);

        return $calledClassFolder;
    }

    private function assertSourceExists(string $source): void
    {
        if (!file_exists($source)) {
            throw new \RuntimeException(sprintf('File %s does not exist', $source));
        }
    }

    private function getRootDir(): string
    {
        return $this->get('kernel')->getRootDir();
    }
}
