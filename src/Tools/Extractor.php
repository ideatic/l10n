<?php

namespace ideatic\l10n\Tools;

use ideatic\l10n\Domain;
use ideatic\l10n\Project;
use ideatic\l10n\String\DirectoryProvider;
use ideatic\l10n\String\Format\Angular;
use ideatic\l10n\String\Format\PHP;
use ideatic\l10n\String\MultiProvider;
use ideatic\l10n\Utils\IO;

class Extractor
{
    /** @var Project[] */
    public $projects;

    /**
     * Scan the projects looking for localizable strings
     * @return \ideatic\l10n\Domain[]
     */
    public function getDomains(): array
    {
        $i18nProvider = new MultiProvider();
        foreach ($this->projects as $projectName => $project) {
            $projectProvider = self::getProjectProvider($project);

            $i18nProvider->add($projectProvider);
        }

        // Agrupar cadenas en dominios
        return Domain::generate($i18nProvider->getStrings());
    }

    /**
     * Configura el proveedor de cadenas localizables para el proyecto indicado
     */
    public static function getProjectProvider(Project|\stdClass $project): DirectoryProvider
    {
        $projectProvider = new DirectoryProvider($project->path);

        foreach ($project->exclude ?? [] as $excludedPath) {
            $projectProvider->excludePath(IO::normalizePath(IO::combinePaths($project->path, $excludedPath)));
        }

        // Definir file extensions to analyze
        if ($project->type == 'php') {
            if (isset($project->translatorMethods)) {
                $phpFormat = new PHP(get_object_vars($project->translatorMethods));
            } else {
                $phpFormat = new PHP();
            }
            $projectProvider->addFormat('php', $phpFormat);
        } elseif ($project->type == 'angular') {
            $projectProvider->addFormat('html,js,ts', new Angular());
        } else {
            throw new \Exception("Unrecognized project type '{$project->type}'");
        }

        if (isset($project->defaultDomain)) {
            foreach ($projectProvider->extensions() as $extension => $extensionFormats) {
                foreach ($extensionFormats as $format) {
                    $format->defaultDomain = $project->defaultDomain;
                }
            }
        }

        return $projectProvider;
    }
}