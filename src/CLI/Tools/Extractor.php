<?php

declare(strict_types=1);

namespace ideatic\l10n\CLI\Tools;

use ideatic\l10n\Catalog\Serializer\Serializer;
use ideatic\l10n\CLI\Environment;
use ideatic\l10n\Domain;
use ideatic\l10n\Translation\Provider\Projects;
use ideatic\l10n\Utils\IO;
use ideatic\l10n\Utils\Locale;
use ideatic\l10n\Utils\Utils;

class Extractor
{
    public static function run(Environment $environment): void
    {
        $domains = self::scanDomains($environment);

        foreach (Utils::wrapArray($environment->config->tools->extractor) as $extractorConfig) {
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
            $domainNames = array_unique($domainNames);

            if ($locale == 'all') {
                $locales = array_map(fn(string|\stdClass $info): string => is_object($info) ? $info->id : $info, $environment->config->locales);
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

                    $locale = is_object($localeInfo) ? $localeInfo->id : $localeInfo;
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
                    if (isset($serializer->transformICU) && isset($extractorConfig->transformICU)) {
                        $serializer->transformICU = $extractorConfig->transformICU;
                    }

                    if (($extractorConfig->referenceLanguage ?? '') === 'all') {
                        $serializer->referenceTranslation = array_map(
                            fn(string|\stdClass $info): string => is_object($info) ? $info->id : $info,
                            $environment->config->locales,
                        );
                    } else {
                        $serializer->referenceTranslation = $extractorConfig->referenceLanguage ?? null;
                    }

                    // Crear fichero
                    if (!empty($extractorConfig->outputPath) && str_contains($extractorConfig->outputPath, '{domain}')) {
                        $path = strtolower(
                            strtr(
                                $extractorConfig->outputPath,
                                [
                                    '{domain}' => $domain->name == 'app' ? $environment->config->name : $domain->name,
                                    '{locale}' => $locale,
                                    '{format}' => $serializer->fileExtension ?? $formatName,
                                ],
                            ),
                        );
                    } else {
                        $path = IO::combinePaths(
                            $extractorConfig->outputPath ?? $environment->directory,
                            strtolower(
                                $environment->config->name . ($domain->name == 'app' ? '' : ".{$domain->name}") . ($locale ? ".{$locale}" : '') . "." . ($serializer->fileExtension ?? $formatName),
                            ),
                        );
                    }

                    $fileName = basename($path);
                    echo "\tGenerating {$fileName}...\n";

                    file_put_contents($path, $serializer->generate([$domain]));

                    echo "\tFile written at {$path}\n";
                }
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
