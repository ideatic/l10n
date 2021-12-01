<?php

namespace ideatic\l10n\CLI;


use ideatic\l10n\CLI\Tools\Analyzer;
use ideatic\l10n\CLI\Tools\Extractor;
use ideatic\l10n\CLI\Tools\Merger;
use ideatic\l10n\CLI\Tools\Translator;
use ideatic\l10n\Utils\IO;

class CLI
{
    public static function main(bool $exit = true)
    {
        (new static)->run(null, $exit);
    }

    public function run(array $argv = null, bool $exit = true)
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

            case 'merge':
                Merger::run($environment);
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
  extract           Extract localizable strings to a file
  merge             Import translations from external source and merge them in the projects
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