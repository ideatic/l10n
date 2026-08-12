<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools;

use Exception;
use ideatic\l10n\Config;
use ideatic\l10n\Domain;
use ideatic\l10n\LString;
use ideatic\l10n\Project;
use ideatic\l10n\Translation\Provider;
use ideatic\l10n\Translation\Provider\Projects;
use ideatic\l10n\Utils\IO;

/**
 * Rewrites the files of a project replacing every translatable string with its translation, producing a copy of the
 * application already localized into one locale.
 */
class ProjectTranslator
{
    /** @var string[] Names of the domains to translate; when not given, every domain is translated */
    public array $domains;

    public function translateDir(Config $config, string $projectName, string $locale, ?string $path = null): array
    {
        /** @var Project|\stdClass|null $project */
        $project = $config->projects->$projectName ?? null;

        if (!$project) {
            throw new Exception("Project '{$projectName}' not found");
        }

        if (isset($path)) {
            $project = clone $project;
            $project->path = $path;
        }

        // Set up the project provider
        $projectStringsProvider = Extractor::getProjectProvider($project);

        // Extract the strings of the project
        $projectDomains = array_column(Domain::generate($projectStringsProvider->getStrings()), null, 'name');

        // Set up the translation provider
        $translator = new Projects($config);

        // List the files to translate among all the ones found
        $files = [];
        foreach ($projectDomains as $domain) {
            if ($this->domains && !in_array($domain->name, $this->domains)) {
                continue;
            }

            foreach ($domain->strings as $stringLocations) {
                foreach ($stringLocations as $string) {
                    $files[$string->file] = $string->file;
                }
            }
        }

        // Translate the files
        $updatedFiles = [];
        foreach ($files as $file) {
            $originalContent = $content = IO::read($file);
            $content = $this->translateFile($content, $file, $locale, $config, $projectName, $translator);

            if (strcmp($originalContent, $content) != 0) {
                $updatedFiles[$file] = $content;
            }
        }

        // Write the updated files
        foreach ($updatedFiles as $path => $content) {
            if (file_put_contents($path, $content) === false) {
                throw new Exception("Unable to write '{$path}'");
            }
        }

        return array_keys($updatedFiles);
    }

    public function translateFile(
        string $content,
        string $file,
        string $locale,
        Config $config,
        string $projectName,
        ?Provider $translatorProvider = null,
    ): string {
        // Look for the project
        /** @var Project|null $project */
        $project = $config->projects->$projectName ?? null;

        if (!$project) {
            throw new Exception("Project '{$projectName}' not found");
        }

        // Create a translator when none is given
        if (!$translatorProvider) {
            $translatorProvider = new Projects($config);
        }

        // Process the file with every provider defined for its extension
        $extension = IO::getExtension($file);
        foreach (Extractor::getProjectProvider($project)->extensions()[$extension] as $format) {
            $content = $format->translate(
                $content,
                function (LString $string) use ($locale, $translatorProvider): ?string {
                    if ($this->domains && !in_array($string->domainName, $this->domains)) {
                        return null; // Do not translate this string
                    }

                    return $translatorProvider->getTranslation($string, $locale, true)?->translation;
                },
                $file,
            );
        }

        return $content;
    }
}
