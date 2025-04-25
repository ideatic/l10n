<?php

declare(strict_types=1);

namespace ideatic\l10n\CLI\Tools;

use ideatic\l10n\Catalog\Serializer\Serializer;
use ideatic\l10n\CLI\Environment;
use ideatic\l10n\ConfigExtractor;
use ideatic\l10n\Domain;
use ideatic\l10n\LString;
use ideatic\l10n\Utils\IO;
use ideatic\l10n\Utils\Locale;
use ideatic\l10n\Utils\Str;
use ideatic\l10n\Utils\Utils;

class Extractor
{
    public static function run(Environment $environment): void
    {
        $domains = self::scanDomains($environment);
        $lastGeneratedDomain = null;

        /** @var ConfigExtractor $extractorConfig */
        foreach (Utils::wrapArray($environment->config->tools->extractor) as $extractorConfig) {
            if (!($extractorConfig->enabled ?? true)) {
                continue;
            }

            // Definir locales a generar
            $locale = $environment->params['locale'] ?? $environment->params['language'] ?? $environment->params['lang'] ?? null;
            $locales = $locale === 'all'
                ? array_map(fn(string|\stdClass $info): string => is_object($info) ? $info->id : $info, $environment->config->locales)
                : [$locale];

            // Definir dominios a incluir en los archivos generados
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

            // Generar archivos
            foreach ($domains as $domain) {
                if (!in_array($domain->name, $domainNames)) {
                    continue;
                }

                if ($lastGeneratedDomain !== $domain->name) {
                    echo "\n\n#### {$domain->name} domain\n\n";
                }
                $lastGeneratedDomain = $domain->name;

                foreach ($locales as $localeInfo) {
                    // Usar como fuente para las traducciones los ficheros serializados de los distintos proyectos
                    $domain->translator = new \ideatic\l10n\Translation\Provider\Projects($environment->config);

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
                    if (isset($serializer->transformICU, $extractorConfig->transformICU)) {
                        $serializer->transformICU = $extractorConfig->transformICU;
                    }
                    $serializer->referenceTranslation = self::_getReferenceTranslations($extractorConfig, $environment);

                    // Filtrar las cadenas a incluir
                    if (!empty($extractorConfig->filter)) {
                        $domain = clone $domain;
                        $domain->strings = self::_filterStrings($domain, $extractorConfig, $serializer);
                    }

                    // Crear fichero
                    $placeholders = [];
                    foreach ($environment->config->projects as $project) {
                        $placeholders["{{$project->name}}"] = $project->path;
                    }

                    if (!empty($extractorConfig->path) && preg_match('/[\w\}]+\.\w+/', $extractorConfig->path)) {
                        $path = strtolower(
                            strtr(
                                $extractorConfig->path,
                                [
                                    ...$placeholders,
                                    '{domain}' => $domain->name,
                                    '{locale}' => $locale,
                                    '{format}' => $serializer->fileExtension ?? $formatName,
                                ]
                            ),
                        );
                    } else {
                        $path = IO::combinePaths(
                            isset($extractorConfig->path) ? strtr($extractorConfig->path, $placeholders) : $environment->directory,
                            strtolower(
                                $environment->config->name . ($domain->name == 'app' ? '' : ".{$domain->name}") . ($locale ? ".{$locale}" : '') . "." . ($serializer->fileExtension ?? $formatName),
                            ),
                        );
                    }

                    $fileName = str_pad(basename($path) . '...', 20, ' ', STR_PAD_RIGHT);
                    echo "\tGenerating {$fileName}";

                    file_put_contents($path, $serializer->generate([$domain]));

                    echo "\tFile written at {$path}\n";
                }
            }
        }
    }

    /**
     * Obtiene los idiomas de referencia que se van a incluir en los archivos generados
     * @return list<string>
     */
    public static function _getReferenceTranslations(ConfigExtractor|\stdClass $extractorConfig, Environment $environment): array
    {
        $referenceTranslation = [];
        foreach (Utils::wrapArray($extractorConfig->referenceLanguage ?? []) as $referenceLocale) {
            if ($referenceLocale === 'source') {
                $referenceLocale = $environment->config->sourceLocale;
            }

            if ($referenceLocale === 'all') {
                $referenceTranslation = array_merge(
                    $referenceTranslation,
                    array_map(
                        fn(string|\stdClass $info): string => is_object($info) ? $info->id : $info,
                        $environment->config->locales,
                    ),
                );
            } elseif ($referenceLocale && $referenceLocale[0] == '-') { // Eliminar idioma de referencia
                $referenceTranslation = array_diff($referenceTranslation, [substr($referenceLocale, 1)]);
            } elseif ($referenceLocale) {
                $referenceTranslation[] = $referenceLocale;
            }
        }

        return array_unique($referenceTranslation);
    }

    /**
     * Filtra las cadenas que van a ser serializadas
     * @return array<array<LString>>
     */
    public static function _filterStrings(Domain $domain, ConfigExtractor|\stdClass $extractorConfig, Serializer $serializer): array
    {
        $filtered = array_filter($domain->strings, function (/** @param list<LString> $strings */ array $strings) use ($extractorConfig, $domain, $serializer) {
            $valid = true;

            // Incluir si tiene un comentario específico
            if ($extractorConfig->filter->hasComment ?? false) {
                $hasSpecificComment = function (LString $string) use ($extractorConfig): bool {
                    if ($extractorConfig->filter->hasComment === true) {
                        if (!!Str::trim($string->comments)) {
                            return true;
                        }
                    } elseif (str_contains($string->comments ?? '', $extractorConfig->filter->hasComment)) {
                        return true;
                    }

                    return false;
                };

                if (count(array_filter($strings, $hasSpecificComment)) > 0) {
                    return true;
                }


                // Comprobar también los comentarios de las traducciones
                if ($serializer->locale) {
                    $translation = $domain->translator->getTranslation(reset($strings), $serializer->locale, false);
                    if ($translation?->metadata && $hasSpecificComment($translation->metadata)) {
                        return true;
                    }
                } else {
                    foreach ($serializer->referenceTranslation as $locale) {
                        $translation = $domain->translator->getTranslation(reset($strings), $locale, false);
                        if ($translation?->metadata && $hasSpecificComment($translation->metadata)) {
                            return true;
                        }
                    }
                }

                return false;
            }

            if ($extractorConfig->filter->status ?? false) {
                if ($serializer->locale) {
                    if ($domain->translator->getTranslation(reset($strings), $serializer->locale, false) !== null) {
                        $valid = $extractorConfig->filter->status == 'pending' ? false : true;
                    }
                } else { // Incluir si al menos un idioma de referencia no tiene traducción
                    $allTranslated = true;
                    foreach ($serializer->referenceTranslation as $locale) {
                        if ($domain->translator->getTranslation(reset($strings), $locale, false) === null) {
                            $allTranslated = false;
                        }
                    }

                    if ($allTranslated) {
                        $valid = $extractorConfig->filter->status == 'pending' ? false : true;
                    }
                }
            }

            return $valid;
        });

        if ($extractorConfig->filter->limit ?? false) {
            $filtered = array_slice($filtered, 0, $extractorConfig->filter->limit);
        }

        return $filtered;
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
