<?php

namespace ideatic\l10n;

use ideatic\l10n\Utils\IO;
use ideatic\l10n\Utils\Utils;

class Config
{
    /** @var string */
    public $name;

    /** @var Project[] */
    public $projects;

    /** @var string */
    public $sourceLocale;

    /** @var string */
    public $fallbackLocale;

    /** @var string[] */
    public $locales;

    /** @var ConfigTools */
    public $tools;

    public function __construct()
    {
    }

    /**
     * Lee la configuración
     */
    public function load(string $configPath)
    {
        // Leer configuración
        if (!file_exists($configPath)) {
            $this->_createDefaultConfig($configPath);
        }

        if (!is_readable($configPath)) {
            throw new \Exception("Unable to read l10n config at '{$configPath}'");
        }

        /** @var Config $config */
        $config = json_decode(file_get_contents($configPath));
        if (!$config || json_last_error() != JSON_ERROR_NONE) {
            throw new \Exception("Unable to parse l10n config: " . json_last_error_msg());
        }

        // Validar configuración
        foreach ($config->projects as $projectName => $project) {
            $project->path = realpath(IO::combinePaths(dirname($configPath), $project->path));

            // Comprobar y ajustar rutas
            if (!$project->path) {
                throw new \Exception("Invalid path for project {$projectName}");
            }

            // Establecer valores por defecto
            if (isset($project->translations)) {
                $project->translations->path = IO::combinePaths($project->path, $project->translations->path);
                $project->translations->format = $project->translations->format ?? 'po';
                $project->translations->template = "{domain}.{locale}.{format}";
            }
        }

        Utils::set($this, get_object_vars($config));
    }

    private function _createDefaultConfig(string $path)
    {
        $defaultConfig = new self();
        $defaultConfig->name = basename(__DIR__);
        $defaultConfig->sourceLocale = 'en';

        $defaultConfig->tools = new \stdClass();
        $defaultConfig->tools->extractor = new \stdClass();
        $defaultConfig->tools->extractor->format = 'po';
        $defaultConfig->tools->extractor->outputPath = './';

        /** @var Project $defaultProject */
        $defaultProject = new \stdClass();
        $defaultProject->type = 'php';
        $defaultProject->path = './';
        $defaultProject->translations = new \stdClass();
        $defaultProject->translations->path = 'translations';
        $defaultProject->translations->format = 'json';
        $defaultProject->exclude = ['.git', 'vendor', 'node_modules', 'dist'];

        $defaultConfig->projects = [$defaultConfig->name => $defaultProject];

        $result = file_put_contents($path, json_encode($defaultConfig, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        if (!$result) {
            throw new \Exception("Unable to write default config at {$path}. Please create the file manually.");
        }

        echo "Created default config at {$path}\n";
    }
}

/**
 * @property string              $type
 * @property string              $path
 * @property string              $defaultDomain
 * @property ProjectTranslations $translations
 * @property string[]            $translatorMethods
 * @property string[]            $exclude
 */
interface Project
{
}

/**
 * @property string $path
 * @property string $format
 * @property string $template
 */
interface ProjectTranslations
{
}

/**
 * @property ConfigExtractor $extractor
 * @property DomainConfig    $merge
 */
interface ConfigTools
{
}

/**
 * @property string   $format
 * @property string   $referenceLanguage
 * @property string   $outputPath
 * @property bool     $includeLocations
 * @property string[] $domains
 */
interface ConfigExtractor
{
}

/**
 * @property string   $source
 * @property string   $script
 * @property string   $format
 * @property string[] $locales
 */
interface DomainConfig
{
}


