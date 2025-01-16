<?php

declare(strict_types=1);

namespace ideatic\l10n;

use Exception;
use ideatic\l10n\Utils\IO;
use ideatic\l10n\Utils\Utils;
use stdClass;

class Config
{
    public string $name;
    /** @var array<Project|stdClass>|stdClass */
    public array|stdClass $projects;
    public string $sourceLocale;
    public string $fallbackLocale;

    /** @var array<string | object{id: string, name: string}> */
    public array $locales;

    public ConfigTools|stdClass $tools;

    /**
     * Lee la configuración
     */
    public function load(string $configPath): void
    {
        // Leer configuración
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

        // Validar configuración
        foreach ($config->projects as $projectName => $project) {
            $project->name = $projectName;
            $project->path = realpath(is_dir($project->path) ? $project->path : IO::combinePaths(dirname($configPath), $project->path));

            // Comprobar y ajustar rutas
            if (!$project->path) {
                throw new Exception("Invalid path for project {$projectName}");
            }

            // Establecer valores por defecto
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
    }

    private function _createDefaultConfig(string $path): void
    {
        $defaultConfig = new self();
        $defaultConfig->name = basename(__DIR__);
        $defaultConfig->sourceLocale = 'en';

        $defaultConfig->tools = new stdClass();
        $defaultConfig->tools->extractor = new stdClass();
        $defaultConfig->tools->extractor->format = 'po';
        $defaultConfig->tools->extractor->path = './';

        /** @var Project&stdClass $defaultProject */
        $defaultProject = new stdClass();
        $defaultProject->type = 'php';
        $defaultProject->path = './';
        $defaultProject->translations = new stdClass();
        $defaultProject->translations->path = 'translations';
        $defaultProject->translations->format = 'json';
        $defaultProject->exclude = ['.git', 'vendor', 'node_modules', 'dist'];

        $defaultConfig->projects = [$defaultConfig->name => $defaultProject];

        $result = file_put_contents($path, json_encode($defaultConfig, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

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
 * @property ProjectTranslations|\stdClass|array<ProjectTranslations|\stdClass> $translations
 * @property string[] $translatorMethods
 * @property string[] $exclude
 */
interface Project {}

/**
 * @property string $path
 * @property string $format
 * @property string $template
 * @property bool|null $includeLocations
 * @property bool|null $transformICU
 */
interface ProjectTranslations {}

/**
 * @property ConfigExtractor|stdClass|array<ConfigExtractor|stdClass> $extractor
 * @property array<string, DomainConfig | list<DomainConfig>> $merge
 */
interface ConfigTools {}

/**
 * @property string $format
 * @property string $referenceLanguage
 * @property string $path
 * @property bool $includeLocations
 * @property bool|null $onlyPending
 * @property string[] $domains
 */
interface ConfigExtractor {}

/**
 * @property string|null $source
 * @property string $script
 * @property string $format
 * @property string[] $locales
 */
interface DomainConfig {}


