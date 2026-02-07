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
        echo "    __   ___   ____         
   / /  <  /  / __ \   ____ 
  / /   / /  / / / /  / __ \
 / /   / /  / /_/ /  / / / /
/_/   /_/   \____/  /_/ /_/

";

        $environment = new Environment();
        $environment->parseParams($argv ?? $_SERVER['argv']);
        $environment->config->load(IO::combinePaths($environment->directory, 'l10n.json'));

        switch ($environment->method) {
            case 'extract':
                Extractor::run($environment);
                break;

            case 'import':
                Importer::run($environment);
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
  translate         Translate localizable strings in a codebase to a certain locale
  analyze           Test localizable strings validity and similarity
 ";
                break;
        }

        if ($exit) {
            exit;
        }
    }
}
