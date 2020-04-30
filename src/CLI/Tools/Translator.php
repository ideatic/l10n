<?php

namespace ideatic\l10n\CLI\Tools;

use ideatic\l10n\CLI\Environment;
use ideatic\l10n\Tools\ProjectTranslator;
use ideatic\l10n\Utils\Locale;

/**
 * Reemplaza las cadenas encontradas en los archivos de un directorio por sus versiones traducidas
 */
class Translator
{
    public static function run(Environment $environment)
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

        // Traducir cadenas
        $start = microtime(true);

        $translator = new ProjectTranslator();
        $translatedFiles = $translator->translateDir($environment->config, $projectName, $locale, $directory);

        // Mostrar resumen
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