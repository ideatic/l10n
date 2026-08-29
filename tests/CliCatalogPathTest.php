<?php

declare(strict_types=1);

use ideatic\l10n\Catalog\Serializer\Serializer;
use ideatic\l10n\CLI\CLI;
use ideatic\l10n\CLI\Environment;
use ideatic\l10n\CLI\Tools\Importer;
use PHPUnit\Framework\TestCase;

/**
 * The catalogs of a project live where l10n.json says, but a one-off round trip (send these strings out, bring these
 * translations back) should not need the configuration to be edited: --output and --input override it for a single run.
 */
class CliCatalogPathTest extends TestCase
{
    private string $_directory;

    protected function setUp(): void
    {
        $this->_directory = sys_get_temp_dir() . '/l10n-cli-' . uniqid();

        mkdir($this->_directory . '/src', recursive: true);

        file_put_contents($this->_directory . '/src/strings.php', "<?php\necho __('Hello world');\necho __('Bye');\n");
        file_put_contents(
            $this->_directory . '/l10n.json',
            json_encode(
                [
                    'name' => 'demo',
                    'sourceLocale' => 'en',
                    'targetLocales' => ['es', 'fr'],
                    'projects' => [
                        'demo' => [
                            'type' => 'php',
                            'path' => './src',
                            'translations' => ['path' => '../translations', 'format' => 'json'],
                        ],
                    ],
                    'exports' => ['format' => 'po', 'path' => './catalogs'],
                ],
                JSON_THROW_ON_ERROR
            )
        );
    }

    protected function tearDown(): void
    {
        $this->_removeDirectory($this->_directory);
    }

    private function _removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $child = "{$path}/{$entry}";
            is_dir($child) ? $this->_removeDirectory($child) : unlink($child);
        }

        rmdir($path);
    }

    /**
     * Runs the command line tool inside the temporary project, without letting its report reach the test output
     * @param list<string> $arguments
     */
    private function _run(array $arguments): string
    {
        $previousDirectory = getcwd();
        $bufferLevel = ob_get_level();
        chdir($this->_directory);

        ob_start();
        try {
            (new CLI())->run(['l10n', ...$arguments], false);

            return ob_get_contents() ?: '';
        } finally {
            // The report is not part of the test output, and an exception must not leave the buffer of PHPUnit behind
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            chdir($previousDirectory);
        }
    }

    /**
     * Builds the environment the way the command line tool does, so the arguments go through the same parsing
     * @param list<string> $arguments
     */
    private function _environment(array $arguments): Environment
    {
        $environment = new Environment();
        $environment->parseParams(['l10n', ...$arguments]);
        $environment->directory = $this->_directory;
        $environment->config->load($this->_directory . '/l10n.json');

        return $environment;
    }


    // The format of a catalog

    public function testTheFormatIsGuessedFromTheExtension(): void
    {
        $this->assertSame('po', Serializer::formatFromFileName('app.es.po'));
        $this->assertSame('po', Serializer::formatFromFileName('app.pot'));
        $this->assertSame('json', Serializer::formatFromFileName('/tmp/extra.JSON'));
        $this->assertSame('php', Serializer::formatFromFileName('translations.php'));
        $this->assertSame('xliff', Serializer::formatFromFileName('app.xlf'));
        $this->assertSame('xliff', Serializer::formatFromFileName('app.xliff'));
    }

    public function testAnUnknownExtensionHasNoFormat(): void
    {
        $this->assertNull(Serializer::formatFromFileName('catalog.txt'));
        $this->assertNull(Serializer::formatFromFileName('catalog'));
    }


    // Extraction at a path given by the user

    public function testTheExtractedCatalogIsWrittenAtTheGivenPath(): void
    {
        $this->_run(['extract', '--output=out/extra.json']);

        $catalog = json_decode(file_get_contents($this->_directory . '/out/extra.json') ?: '', true);

        $this->assertSame(['Bye' => null, 'Hello world' => null], $catalog);
    }

    public function testThePathOverridesTheConfiguredDestinations(): void
    {
        $this->_run(['extract', '--output=out/extra.json']);

        $this->assertDirectoryDoesNotExist($this->_directory . '/catalogs');
    }

    public function testTheFormatOfTheExtractedCatalogCanBeForced(): void
    {
        $this->_run(['extract', '--output=out/extra.json', '--format=json-extended']);

        $catalog = json_decode(file_get_contents($this->_directory . '/out/extra.json') ?: '', true);

        $this->assertSame('Bye', $catalog[0]['original']);
    }

    public function testOneFileIsWrittenPerLocale(): void
    {
        $this->_run(['extract', '--output=out/app.{locale}.po', '--locale=all']);

        $this->assertFileExists($this->_directory . '/out/app.es.po');
        $this->assertFileExists($this->_directory . '/out/app.fr.po');
    }

    public function testADirectoryUsesTheDefaultFileNames(): void
    {
        $this->_run(['extract', '--output=out']);

        $this->assertFileExists($this->_directory . '/out/demo.po');
    }

    public function testSeveralLocalesNeedAPathTellingThemApart(): void
    {
        $this->expectExceptionMessageMatches('/several locales/i');

        $this->_run(['extract', '--output=out/app.po', '--locale=all']);
    }

    public function testAnUnknownExtensionIsRejectedWhenExtracting(): void
    {
        $this->expectExceptionMessageMatches('/--format/');

        $this->_run(['extract', '--output=out/extra.unknown']);
    }


    // Import from a path given by the user

    public function testTheSourceIsBuiltFromTheGivenPath(): void
    {
        touch($this->_directory . '/incoming.es.json');

        $source = Importer::customInputSource($this->_environment(['import', '--input=incoming.es.json', '--locale=es']), 'incoming.es.json');

        $this->assertSame('json', $source->format);
        $this->assertSame(['es'], $source->locales);
        $this->assertSame($this->_directory . '/incoming.es.json', $source->source);
    }

    public function testTheFormatOfTheImportedCatalogCanBeForced(): void
    {
        touch($this->_directory . '/incoming.es.json');

        $source = Importer::customInputSource(
            $this->_environment(['import', '--input=incoming.es.json', '--locale=es', '--format=json-extended']),
            'incoming.es.json'
        );

        $this->assertSame('json-extended', $source->format);
    }

    public function testTheLocaleOfTheImportedCatalogIsRequired(): void
    {
        touch($this->_directory . '/incoming.json');

        $this->expectExceptionMessageMatches('/--locale/');

        Importer::customInputSource($this->_environment(['import', '--input=incoming.json']), 'incoming.json');
    }

    public function testThePlaceholderStandsInForTheLocale(): void
    {
        $source = Importer::customInputSource($this->_environment(['import', '--input=incoming/app.{locale}.po']), 'incoming/app.{locale}.po');

        $this->assertSame(['es', 'fr'], $source->locales);
        $this->assertSame('po', $source->format);
    }

    public function testSeveralLocalesCannotComeFromASingleFile(): void
    {
        touch($this->_directory . '/incoming.json');

        $this->expectExceptionMessageMatches('/several locales/i');

        Importer::customInputSource($this->_environment(['import', '--input=incoming.json', '--locale=all']), 'incoming.json');
    }

    public function testAMissingCatalogIsReportedBeforeReadingIt(): void
    {
        $this->expectExceptionMessageMatches('/not found/i');

        Importer::customInputSource($this->_environment(['import', '--input=nowhere.json', '--locale=es']), 'nowhere.json');
    }

    public function testACatalogThatCannotBeReadIsRejected(): void
    {
        touch($this->_directory . '/incoming.es.xlf');

        $this->expectExceptionMessageMatches('/cannot be imported/i');

        Importer::customInputSource($this->_environment(['import', '--input=incoming.es.xlf', '--locale=es']), 'incoming.es.xlf');
    }


    // Both halves of the round trip

    public function testTheTranslationsComeBackIntoTheProjectCatalogs(): void
    {
        file_put_contents(
            $this->_directory . '/incoming.es.json',
            json_encode(['Hello world' => 'Hola mundo', 'Bye' => 'Adiós'], JSON_THROW_ON_ERROR)
        );

        $this->_run(['import', '--input=incoming.es.json', '--locale=es']);

        $catalog = json_decode(file_get_contents($this->_directory . '/translations/app.es.json') ?: '', true);

        $this->assertSame(['Bye' => 'Adiós', 'Hello world' => 'Hola mundo'], $catalog);
    }

    public function testTheConfiguredSourcesAreNotUsed(): void
    {
        file_put_contents($this->_directory . '/incoming.es.json', json_encode(['Bye' => 'Adiós'], JSON_THROW_ON_ERROR));

        // A source that would fail if it were read, so using it cannot go unnoticed
        $config = json_decode(file_get_contents($this->_directory . '/l10n.json') ?: '', true);
        $config['sources'] = ['all' => [['source' => $this->_directory . '/does-not-exist.po', 'format' => 'po']]];
        file_put_contents($this->_directory . '/l10n.json', json_encode($config, JSON_THROW_ON_ERROR));

        $output = $this->_run(['import', '--input=incoming.es.json', '--locale=es']);

        $this->assertStringContainsString('incoming.es.json', $output);
        $this->assertStringNotContainsString('does-not-exist.po', $output);
    }
}
