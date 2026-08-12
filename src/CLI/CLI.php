<?php

declare(strict_types=1);

namespace ideatic\l10n\CLI;


use ideatic\l10n\CLI\Tools\Analyzer;
use ideatic\l10n\CLI\Tools\Extractor;
use ideatic\l10n\CLI\Tools\Importer;
use ideatic\l10n\CLI\Tools\Translator;
use ideatic\l10n\Utils\IO;

class CLI
{
    public static function main(bool $exit = true): void
    {
        /** @phpstan-ignore-next-line */
        (new static)->run(null, $exit);
    }

    public function run(?array $argv = null, bool $exit = true): void
    {
        $environment = new Environment();
        $environment->parseParams($argv ?? $_SERVER['argv']);

        $banner = "    __   ___   ____
   / /  <  /  / __ \   ____
  / /   / /  / / / /  / __ \
 / /   / /  / /_/ /  / / / /
/_/   /_/   \____/  /_/ /_/

";

        // Con salida legible por máquinas, la salida estándar queda reservada para el informe. Solo aplica a
        // analyze: en el resto de herramientas --format elige el formato de los catálogos generados.
        if ($environment->method === 'analyze' && ($environment->params['format'] ?? null) === 'json') {
            fwrite(STDERR, $banner);
        } else {
            echo $banner;
        }

        $environment->config->load(IO::combinePaths($environment->directory, 'l10n.json'));

        switch ($environment->method) {
            case 'extract':
                Extractor::run($environment);
                break;

            case 'import':
                Importer::run($environment);
                break;

            case 'io':
                Importer::run($environment);
                Extractor::run($environment);
                break;

            case 'translate':
                Translator::run($environment);
                break;

            case 'analyze':
                Analyzer::run($environment);
                break;

            default:
                echo "
Usage:
  {$environment->executableName} tool [arguments]
  
Available tools:
  extract           Extract localizable strings
  import            Import translations from sources and write them to each project's translation files
  io                Import translations and then extract localizable strings
  translate         Translate localizable strings in a codebase to a certain locale
  analyze           Lint localizable strings, their translations and the l10n configuration

Analyze options:
  --list-checks         List the available checks and exit
  --checks=a,b          Run only these checks or groups
  --skip=a,b            Exclude these checks or groups
  --locale=all|es,fr    Locales used by the translation checks (default: every target locale)
  --domain=all|app      Limit the analysis to these domains
  --severity=error      Minimum severity to report (error, warning or info)
  --limit=N             Maximum issues per check and domain (0 = no limit, default 20)
  --format=text|json    Output format
  --t=N, --threshold=N  Similarity threshold used by similar-strings (default 90)
  --deep                Also run the expensive checks
  --strict              Exit with an error code on warnings too
  --no-color            Disable coloured output
 ";
                break;
        }

        if ($exit) {
            exit($environment->exitCode);
        }
    }
}
