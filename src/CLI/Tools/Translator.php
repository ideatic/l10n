<?php

declare(strict_types=1);

namespace ideatic\l10n\CLI\Tools;

use ideatic\l10n\CLI\Environment;
use ideatic\l10n\Tools\ProjectTranslator;
use ideatic\l10n\Utils\Locale;

/**
 * Replaces the strings found in the files of a directory with their translated versions
 */
class Translator
{
  public static function run(Environment $environment): void
  {
    $locale = $environment->params['locale'] ?? $environment->params['language'] ?? $environment->params['lang'] ?? null;
    $projectName = $environment->params['project'] ?? null;
    $directory = $environment->params['path'] ?? $environment->params['dir'] ?? null;
    $verbose = $environment->params['verbose'] ?? false;

    if (!$locale || !$projectName || !$directory) {
      echo "
Usage:
  {$environment->executableName} {$environment->method} --project=PROJECT_NAME --locale=LOCALE --path=DIRECTORY 
  
IMPORTANT: This tool will overwrite all source files and replace localizable strings with their correspondent translations.
It's recommended to apply this tool in a different folder than the one that hosts the original source code.  
 ";
      return;
    }

    $localeName = Locale::getName($locale);
    echo "Translating {$projectName}@{$directory} to {$localeName} ({$locale})\n";

    // Translate the strings
    $start = microtime(true);

    $translator = new ProjectTranslator();
    $translatedFiles = $translator->translateDir($environment->config, $projectName, $locale, $directory);

    // Show the summary
    if ($verbose) {
      echo "\nUpdated files\n\n";
      foreach ($translatedFiles as $file) {
        echo "{$file}\n";
      }
    }

    $count = number_format(count($translatedFiles));
    $elapsed = number_format(microtime(true) - $start, 2);
    echo "\n{$count} files written in {$elapsed}s\n";
  }
}