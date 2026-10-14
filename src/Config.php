<?php

declare(strict_types=1);

namespace ideatic\l10n;

use Exception;
use ideatic\l10n\Utils\IO;
use ideatic\l10n\Utils\Utils;
use stdClass;

/**
 * Project configuration, read from the l10n.json file.
 *
 * It describes where the source strings live ("projects"), where they are sent to be translated ("exports"), where
 * the finished translations come back from ("sources") and which locales the application offers.
 */
class Config
{
    /** Path of the configuration file that was loaded */
    public string $path;

    public string $name;
    /** @var array<Project|stdClass>|stdClass */
    public array|stdClass $projects;
    public string $sourceLocale;
    public string $fallbackLocale;

    /** @var array<string | object{id: string, name: string}> */
    public array $targetLocales;

    /**
     * Destination(s) of the strings found in the source code
     * @var ExtractionDestiny|stdClass|list<ExtractionDestiny|stdClass>
     */
    public ExtractionDestiny|stdClass|array $exports;

    /**
     * Translation sources. An associative array whose key is the domain name and whose value is its translation sources.
     * @var stdClass|array<string, ImportSource | list<ImportSource>>
     */
    public object|array $sources;

    /**
     * Options of the analysis tool
     * @var Analysis|stdClass|null
     */
    public Analysis|stdClass|null $analyze = null;

    /**
     * Reads the configuration
     */
    public function load(string $configPath): void
    {
        // Read the configuration
        if (!file_exists($configPath)) {
            $this->_createDefaultConfig($configPath);
        }

        if (!is_readable($configPath)) {
            throw new Exception("Unable to read l10n config at '{$configPath}'");
        }

        /** @var Config|null $config */
        $config = json_decode(IO::read($configPath));
        if (!$config || json_last_error() != JSON_ERROR_NONE) {
            throw new Exception("Unable to parse l10n config: " . json_last_error_msg());
        }

        // Validate the configuration
        foreach ($config->projects as $projectName => $project) {
            $project->name = $projectName;
            $project->path = realpath(IO::isAbsolutePath($project->path) ? $project->path : IO::combinePaths(dirname($configPath), $project->path)); // Relative paths start at the config file, not at the working directory

            // Check and adjust the paths
            if (!$project->path) {
                throw new Exception("Invalid path for project {$projectName}");
            }

            // Apply the default values
            if (isset($project->translations)) {
                $project->translations = Utils::wrapArray($project->translations);
                /** @var ProjectTranslations|\stdClass $translations */
                foreach ($project->translations as $translations) {
                    $translations->path = IO::combinePaths($project->path, $translations->path);
                    $translations->format ??= 'po';
                    $translations->template ??= "{domain}.{locale}.{format}";
                }
            }
        }

        Utils::set($this, get_object_vars($config));

        $this->path = $configPath;
    }

    private function _createDefaultConfig(string $path): void
    {
        $defaultConfig = new self();
        $defaultConfig->name = basename(__DIR__);
        $defaultConfig->sourceLocale = 'en';

        $defaultConfig->exports = new stdClass();
        $defaultConfig->exports->format = 'po';
        $defaultConfig->exports->path = './';

        /** @var Project&stdClass $defaultProject */
        $defaultProject = new stdClass();
        $defaultProject->type = 'php';
        $defaultProject->path = './';
        $defaultProject->translations = new stdClass();
        $defaultProject->translations->path = 'translations';
        $defaultProject->translations->format = 'json';
        $defaultProject->exclude = ['.git', 'vendor', 'node_modules', 'dist'];

        $defaultConfig->projects = [$defaultConfig->name => $defaultProject];

        // Options without a value are left out, so the generated configuration stays clean
        $values = array_filter(get_object_vars($defaultConfig), static fn(mixed $value): bool => $value !== null);

        $result = file_put_contents($path, json_encode($values, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        if (!$result) {
            throw new Exception("Unable to write default config at {$path}. Please create the file manually.");
        }

        echo "Created default config at {$path}\n";
    }
}

/**
 * @property string $type
 * @property string $name
 * @property string $path
 * @property string $defaultDomain
 * @property ProjectTranslations|\stdClass|array<ProjectTranslations|\stdClass> $translations Location of the translation resources of this project
 * @property string[] $translatorMethods
 * @property string[] $exclude
 */
interface Project {}

/**
 * @property string $path
 * @property string $format
 * @property string $template
 * @property bool|null $includeLocations
 * @property string|list<string> $referenceTranslations
 * @property bool|null $transformICU
 */
interface ProjectTranslations {}

/**
 * @property string $format
 * @property string|list<string> $referenceLanguage
 * @property string $path
 * @property bool $includeLocations
 * @property object{status: bool, hasComment: string, limit: int}|null $filter
 * @property string[] $domains
 * @property ?bool $enabled
 */
interface ExtractionDestiny {}

/**
 * @property string|null $name
 * @property string|null $source
 * @property string $script
 * @property string $format
 * @property string[] $locales
 * @property ?string $addComment
 */
interface ImportSource {}

/**
 * @property float|null $threshold Minimum similarity percentage between strings
 * @property string|list<string>|null $skip Checks or groups skipped by default
 * @property string|null $commentLanguage Language expected in the translator comments
 * @property list<string>|null $countPlaceholders Placeholder names standing for a quantity
 * @property list<string>|null $ambiguousWords Regular expressions of words that always need a comment
 * @property object{allowSurroundingText: bool, maxParameters: int, allowedTypes: list<string>, allowNesting: bool}|null $icuRuntime Limitations of the client ICU engine
 */
interface Analysis {}


