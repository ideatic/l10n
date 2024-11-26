<?php

declare(strict_types=1);

namespace ideatic\l10n\CLI\Tools;

use ideatic\l10n\Catalog\Serializer\Serializer;
use ideatic\l10n\CLI\Environment;
use ideatic\l10n\Domain;
use ideatic\l10n\Translation\Provider\Projects;
use ideatic\l10n\Utils\IO;
use ideatic\l10n\Utils\Locale;

class Extractor
{
    public static function run(Environment $environment): void
    {
        $extractorConfig = $environment->config->tools->extractor;
        $domains = self::scanDomains($environment);

        // Definir locales a generar
        $locale = $environment->params['locale'] ?? $environment->params['language'] ?? $environment->params['lang'] ?? null;

        // Definir grupos a generar
        $domainNames = $environment->params['domains'] ?? $environment->params['domain'] ?? '';
        if ($domainNames == 'all') {
            $domainNames = array_column($domains, 'name');
        } elseif ($domainNames) {
            $domainNames = explode(',', $domainNames);
        } elseif (isset($extractorConfig->domains)) {
            $domainNames = $extractorConfig->domains;
        } else {
            $domainNames = array_column($domains, 'name');
        }

        if ($locale == 'all') {
            $locales = $environment->config->locales;
        } else {
            $locales = [$locale];
        }

        // Generar archivos
        foreach ($domains as $domain) {
            if (!in_array($domain->name, $domainNames)) {
                continue;
            }

            echo "\n\n#### {$domain->name} domain\n\n";

            foreach ($locales as $localeInfo) {
                $domain->translator = new Projects($environment->config);

                $locale = is_object($localeInfo) ? $localeInfo->locale : $localeInfo;
                if (count($locales) > 1) {
                    if ($localeInfo) {
                        echo "\n\t## " . (is_object($localeInfo) ? $localeInfo->name : Locale::getName($locale)) . " ({$locale})\n";
                    } else {
                        echo "\n\t## Untranslated\n";
                    }
                }

                $formatName = $environment->params['format'] ?? $extractorConfig->format ?? 'po';
                $serializer = Serializer::factory($formatName);
                $serializer->includeLocations = boolval($environment->params['locations'] ?? $extractorConfig->includeLocations ?? true);
                $serializer->locale = $locale;
                $serializer->referenceTranslation = $extractorConfig->referenceLanguage ?? null;

                // Crear fichero
                $fileName = strtolower(
                    $environment->config->name . ($domain->name == 'app' ? '' : ".{$domain->name}") . ($locale ? ".{$locale}" : '') . "." . ($serializer->fileExtension ?? $formatName),
                );

                echo "\tGenerating {$fileName}...\n";
                $content = $serializer->generate([$domain]);

                $path = IO::combinePaths($extractorConfig->outputPath ?? $environment->directory, $fileName);
                file_put_contents($path, $content);

                echo "\tFile written at {$path}\n";
            }
        }
    }

    /**
     * @return array<Domain>
     */
    public static function scanDomains(Environment $environment): array
    {
        $extractor = new \ideatic\l10n\Tools\Extractor();

        $extractor->projects = $environment->config->projects;

        // Obtener dominios disponibles
        $start = microtime(true);
        echo "\nScanning localizable strings... ";

        $domains = $extractor->getDomains();

        // Mostrar resumen
        $count = number_format(count($domains));
        $stringCount = 0;
        foreach ($domains as $domain) {
            $stringCount += count($domain->strings);
        }
        $stringCount = number_format($stringCount);
        $elapsed = number_format(microtime(true) - $start, 2);
        echo "{$count} domains and {$stringCount} strings found in {$elapsed}s\n";

        return $domains;
    }
}
