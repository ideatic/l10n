<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools;

use Exception;
use ideatic\l10n\Domain;
use ideatic\l10n\Project;
use ideatic\l10n\String\DirectoryProvider;
use ideatic\l10n\String\Format\Angular;
use ideatic\l10n\String\Format\PHP;
use ideatic\l10n\String\MultiProvider;
use ideatic\l10n\Utils\IO;
use stdClass;

/**
 * Walks the source code of the configured projects looking for translatable strings, and groups what it finds into
 * domains.
 */
class Extractor
{
  /** @var Project[] */
  public $projects;

  /**
   * Scan the projects looking for localizable strings
   * @return Domain[]
   */
  public function getDomains(): array
  {
    $i18nProvider = new MultiProvider();
    foreach ($this->projects as $project) {
      $projectProvider = self::getProjectProvider($project);

      $i18nProvider->add($projectProvider);
    }

    // Group the strings into domains
    return Domain::generate($i18nProvider->getStrings());
  }

  /**
   * Sets up the localizable string provider for the given project
   */
  public static function getProjectProvider(Project|stdClass $project): DirectoryProvider
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
      throw new Exception("Unrecognized project type '{$project->type}'");
    }

    if (isset($project->defaultDomain)) {
      foreach ($projectProvider->extensions() as $extensionFormats) {
        foreach ($extensionFormats as $format) {
          $format->defaultDomain = $project->defaultDomain;
        }
      }
    }

    return $projectProvider;
  }
}
