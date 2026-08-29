<?php

declare(strict_types=1);

namespace ideatic\l10n\CLI;


use ideatic\l10n\CLI\Tools\Analyzer;
use ideatic\l10n\CLI\Tools\Extractor;
use ideatic\l10n\CLI\Tools\Importer;
use ideatic\l10n\CLI\Tools\Translator;
use ideatic\l10n\Utils\IO;

/**
 * Entry point of the command line tool: it parses the arguments, loads the project configuration and hands control
 * over to the requested tool (extract, import, translate or analyze).
 */
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

        // With machine-readable output, standard output is reserved for the report. This only applies to
        // analyze: in the other tools --format picks the format of the generated catalogs.
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

Extract options:
  --output=PATH         Write the catalog at this path instead of the destinations of l10n.json.
                        A file uses its extension to pick the format, a directory uses the default names.
                        Accepts the {domain}, {locale} and {format} placeholders
  --format=NAME         Catalog format: po, json, json-extended, php or xliff
  --locale=all|es       Locale of the generated catalog (none = source strings)
  --domain=all|app,web  Domains to include
  --reference=es,fr     Locales added as a reference translation
  --locations=false     Do not include the source code locations

Analyze options:
  --list-checks         List the available checks and exit
  --scope=source        Audit only the source strings (needs no catalog), only their 'translations', or 'all'
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
