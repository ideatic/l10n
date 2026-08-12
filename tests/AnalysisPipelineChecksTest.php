<?php

declare(strict_types=1);

use ideatic\l10n\Tools\Analysis\Check\ConfigHygiene;
use ideatic\l10n\Tools\Analysis\Check\EmptyCatalog;
use ideatic\l10n\Tools\Analysis\Check\ExcludedStrings;
use ideatic\l10n\Tools\Analysis\Check\UnconfiguredDomain;
use ideatic\l10n\Tools\Analysis\Severity;
use PHPUnit\Framework\TestCase;

require_once 'AnalysisFixtures.php';

class AnalysisPipelineChecksTest extends TestCase
{
    use AnalysisFixtures;

    private ?string $_temporaryDirectory = null;

    protected function tearDown(): void
    {
        if ($this->_temporaryDirectory !== null && is_dir($this->_temporaryDirectory)) {
            $this->_removeDirectory($this->_temporaryDirectory);
        }

        $this->_temporaryDirectory = null;
    }

    public function testDetectsPersonalAbsolutePathsInTheConfiguration(): void
    {
        $path = $this->_writeConfig([
            'name' => 'demo',
            'exports' => ['format' => 'po', 'path' => '/Users/someone/Downloads/'],
        ]);

        $issues = $this->_run(new ConfigHygiene(), [$this->_string('Hello')], configuration: ['path' => $path]);

        $this->assertTrue($this->_hasMessage($issues, '"exports.path" points to the absolute path "/Users/someone/Downloads/"'));
    }

    public function testAcceptsRelativePathsInTheConfiguration(): void
    {
        $path = $this->_writeConfig(['name' => 'demo', 'exports' => ['format' => 'po', 'path' => './translations/']]);

        $issues = $this->_run(new ConfigHygiene(), [$this->_string('Hello')], configuration: ['path' => $path]);

        $this->assertFalse($this->_hasMessage($issues, 'absolute path'));
    }

    public function testDetectsReferencesToDomainsThatNoLongerExist(): void
    {
        $issues = $this->_run(
            new ConfigHygiene(),
            [$this->_string('Hello', ['domain' => 'app'])],
            configuration: [
                'exports' => (object)['domains' => ['app', 'ghost']],
                'sources' => (object)['app' => (object)['format' => 'po']],
            ]
        );

        $this->assertTrue($this->_hasMessage($issues, "\"exports\" references the domain 'ghost'"));
        $this->assertFalse($this->_hasMessage($issues, "the domain 'app'"));
    }

    public function testDetectsTargetLocalesUnknownToIcu(): void
    {
        $issues = $this->_run(new ConfigHygiene(), [$this->_string('Hello')], ['es', 'xx-YY']);

        $this->assertTrue($this->_hasMessage($issues, "Target locale 'xx-YY' is not recognised by ICU"));
        $this->assertFalse($this->_hasMessage($issues, "'es' is not recognised"));
    }

    public function testDetectsAMissingSourceLocale(): void
    {
        $issues = $this->_run(new ConfigHygiene(), [$this->_string('Hello')], configuration: ['sourceLocale' => null]);

        $this->assertTrue($this->_hasMessage($issues, 'No "sourceLocale" is defined'));
    }

    public function testDetectsDomainsMissingFromThePipeline(): void
    {
        $issues = $this->_run(new UnconfiguredDomain(), [$this->_string('Hello', ['domain' => 'jackpot'])]);

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Warning, $issues[0]->severity);
        $this->assertStringContainsString('no "exports" destiny includes it', $issues[0]->message);
        $this->assertStringContainsString('no "sources" entry covers it', $issues[0]->message);
    }

    public function testDomainCoveredByBothSectionsIsNotReported(): void
    {
        $issues = $this->_run(
            new UnconfiguredDomain(),
            [$this->_string('Hello', ['domain' => 'app'])],
            configuration: [
                'sources' => (object)['app' => (object)['format' => 'po']],
                'exports' => (object)['domains' => ['app']],
            ]
        );

        $this->assertSame([], $issues);
    }

    public function testCatchAllConfigurationCoversEveryDomain(): void
    {
        $issues = $this->_run(
            new UnconfiguredDomain(),
            [$this->_string('Hello', ['domain' => 'app']), $this->_string('Bye', ['domain' => 'admin'])],
            configuration: [
                'sources' => (object)['all' => (object)['format' => 'po']],
                'exports' => (object)['format' => 'po'], // No domain filter
            ]
        );

        $this->assertSame([], $issues);
    }

    public function testReportsOnlyTheDomainsLeftOutOfTheExports(): void
    {
        $issues = $this->_run(
            new UnconfiguredDomain(),
            [$this->_string('Hello', ['domain' => 'app']), $this->_string('Bye', ['domain' => 'shop'])],
            configuration: [
                'sources' => (object)['all' => (object)['format' => 'po']],
                'exports' => (object)['domains' => ['app']],
            ]
        );

        $this->assertCount(1, $issues);
        $this->assertSame('shop', $issues[0]->domain);
        $this->assertStringContainsString('no "exports" destiny includes it', $issues[0]->message);
    }

    public function testDisabledExportsDoNotCoverAnyDomain(): void
    {
        $issues = $this->_run(
            new UnconfiguredDomain(),
            [$this->_string('Hello', ['domain' => 'app'])],
            configuration: [
                'sources' => (object)['all' => (object)['format' => 'po']],
                'exports' => (object)['format' => 'po', 'enabled' => false],
            ]
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('no "exports" destiny includes it', $issues[0]->message);
    }

    public function testDetectsDeclaredLocalesWithoutACatalog(): void
    {
        $issues = $this->_run(
            new EmptyCatalog(),
            [$this->_string('Hello')],
            ['es', 'fr'],
            ['app@es' => $this->_catalog('es', ['Hello' => 'Hola'])]
        );

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Error, $issues[0]->severity);
        $this->assertSame('fr', $issues[0]->locale);
        $this->assertStringContainsString('has no catalog file', $issues[0]->message);
    }

    public function testDetectsEmptyCatalogs(): void
    {
        $issues = $this->_run(
            new EmptyCatalog(),
            [$this->_string('Hello')],
            ['es', 'fr'],
            [
                'app@es' => $this->_catalog('es', ['Hello' => 'Hola']),
                'app@fr' => $this->_catalog('fr', []),
            ]
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('has an empty catalog', $issues[0]->message);
    }

    /**
     * When a domain has no catalog in any locale the problem is one of domain configuration,
     * and unconfigured-domain reports it
     */
    public function testDomainWithoutAnyCatalogIsLeftToTheOtherCheck(): void
    {
        $issues = $this->_run(new EmptyCatalog(), [$this->_string('Hello')], ['es', 'fr']);

        $this->assertSame([], $issues);
    }

    public function testFindsLocalizableStringsInsideExcludedPaths(): void
    {
        $project = $this->_createProject([
            'src/app.php' => '<?php echo __("Visible string");',
            'legacy/old.php' => '<?php echo __("Hidden string"); echo __("Another hidden string");',
        ], exclude: ['legacy']);

        $issues = $this->_run(new ExcludedStrings(), [$this->_string('Visible string')], configuration: ['projects' => [$project]]);

        $this->assertCount(1, $issues);
        $this->assertStringContainsString("2 localizable strings found in the excluded path 'legacy'", $issues[0]->message);
        $this->assertStringContainsString('Hidden string', implode('', $issues[0]->details));
    }

    public function testDependencyDirectoriesAreNeverScanned(): void
    {
        $project = $this->_createProject([
            'src/app.php' => '<?php echo __("Visible string");',
            'node_modules/pkg/index.php' => '<?php echo __("Third party string");',
        ], exclude: ['node_modules']);

        $issues = $this->_run(new ExcludedStrings(), [$this->_string('Visible string')], configuration: ['projects' => [$project]]);

        $this->assertSame([], $issues);
    }

    public function testExcludedStringsIsAnExpensiveCheck(): void
    {
        $this->assertTrue(new ExcludedStrings()->isExpensive());
        $this->assertFalse(new UnconfiguredDomain()->isExpensive());
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function _writeConfig(array $configuration): string
    {
        $directory = $this->_makeTemporaryDirectory();
        $path = "{$directory}/l10n.json";

        file_put_contents($path, json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * @param array<string, string> $files    Contenido indexado por ruta relativa
     * @param list<string>          $exclude
     */
    private function _createProject(array $files, array $exclude): stdClass
    {
        $directory = $this->_makeTemporaryDirectory();

        foreach ($files as $relativePath => $content) {
            $path = "{$directory}/{$relativePath}";

            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }

            file_put_contents($path, $content);
        }

        return (object)['name' => 'demo', 'type' => 'php', 'path' => $directory, 'exclude' => $exclude];
    }

    private function _makeTemporaryDirectory(): string
    {
        $this->_temporaryDirectory ??= (function (): string {
            $path = sys_get_temp_dir() . '/l10n-analysis-' . bin2hex(random_bytes(6));
            mkdir($path, 0777, true);
            return $path;
        })();

        return $this->_temporaryDirectory;
    }

    private function _removeDirectory(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = "{$path}/{$entry}";
            is_dir($child) ? $this->_removeDirectory($child) : unlink($child);
        }

        rmdir($path);
    }
}
