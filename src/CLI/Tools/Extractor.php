<?php

declare(strict_types=1);

namespace ideatic\l10n\CLI\Tools;

use ideatic\l10n\Catalog\Serializer\Serializer;
use ideatic\l10n\CLI\Environment;
use ideatic\l10n\Domain;
use ideatic\l10n\ExtractionDestiny;
use ideatic\l10n\LString;
use ideatic\l10n\Utils\IO;
use ideatic\l10n\Utils\Locale;
use ideatic\l10n\Utils\Utils;

/**
 * Tool to extract the localizable strings from the source code and generate translation files
 */
class Extractor
{
    public static function run(Environment $environment): void
    {
        $domains = self::scanDomains($environment);

        // An explicit --output overrides every destination configured in l10n.json
        $outputPath = $environment->params['output'] ?? $environment->params['out'] ?? null;
        if (!empty($outputPath)) {
            self::_writeToCustomPath($environment, $domains, $outputPath);
            return;
        }

        $lastGeneratedDomain = null;

        /** @var ExtractionDestiny $extractorConfig */
        foreach (Utils::wrapArray($environment->config->exports) as $extractorConfig) {
            if (!($extractorConfig->enabled ?? true)) {
                continue;
            }

            // Definir locales a generar
            $locale = $environment->params['locale'] ?? $environment->params['language'] ?? $environment->params['lang'] ?? null;
            $locales = $locale === 'all'
                ? array_map(fn(string|\stdClass $info): string => is_object($info) ? $info->code : $info, $environment->config->targetLocales)
                : [$locale];

            // Define the domains to include in the generated files
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

            // Generate the files
            foreach ($domains as $domain) {
                if (!in_array($domain->name, $domainNames)) {
                    continue;
                }

                if ($lastGeneratedDomain !== $domain->name) {
                    echo "\n\n#### {$domain->name} domain\n\n";
                }
                $lastGeneratedDomain = $domain->name;

                foreach ($locales as $localeInfo) {
                    // Use the translation files of the various projects as the source for the translations
                    $domain->translator = new \ideatic\l10n\Translation\Provider\Projects($environment->config);

                    $locale = is_object($localeInfo) ? $localeInfo->code : $localeInfo;
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

                    // Create the file
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

                    if (!is_dir(dirname($path))) {
                        mkdir(dirname($path), 0777, true);
                    }

                    file_put_contents($path, $serializer->generate([$domain]));

                    echo "\tFile written at {$path}\n";
                }
            }
        }
    }

    /**
     * Writes the catalog at the path given by the user with --output, ignoring the destinations configured in
     * l10n.json.
     *
     * The path can be a file (its extension selects the format, unless --format says otherwise) or a directory, and it
     * accepts the same placeholders as the configured destinations: {domain}, {locale}, {format} and {project-name}.
     *
     * @param array<Domain> $domains
     */
    private static function _writeToCustomPath(Environment $environment, array $domains, string $outputPath): void
    {
        $placeholders = [];
        foreach ($environment->config->projects as $project) {
            $placeholders["{{$project->name}}"] = $project->path;
        }
        $outputPath = strtr($outputPath, $placeholders);

        if (!self::_isAbsolutePath($outputPath)) {
            $outputPath = IO::combinePaths($environment->directory, $outputPath);
        }

        // A path whose last segment carries an extension is a file, anything else is a directory
        $isFile = (bool)preg_match('/\.[\w{}\-]+$/', basename($outputPath));

        // The format comes from --format, or from the extension of the file
        $formatName = $environment->params['format'] ?? ($isFile ? Serializer::formatFromFileName($outputPath) : null);
        if (empty($formatName)) {
            if ($isFile) {
                throw new \Exception(
                    "Unable to guess the catalog format of '" . basename($outputPath) . "', please set it with --format (po, json, json-extended, php or xliff)"
                );
            }
            $formatName = 'po';
        }

        // Locales to generate
        $locale = $environment->params['locale'] ?? $environment->params['language'] ?? $environment->params['lang'] ?? null;
        $locales = $locale === 'all'
            ? array_map(fn(string|\stdClass $info): string => is_object($info) ? $info->code : $info, $environment->config->targetLocales)
            : [$locale];

        // Domains to include
        $domainNames = $environment->params['domains'] ?? $environment->params['domain'] ?? '';
        if ($domainNames && $domainNames != 'all') {
            $domainNames = array_unique(explode(',', $domainNames));
            $domains = array_values(array_filter($domains, fn(Domain $domain): bool => in_array($domain->name, $domainNames)));

            if (!$domains) {
                throw new \Exception("No domains found matching '" . implode(', ', $domainNames) . "'");
            }
        }

        $hasDomainPlaceholder = str_contains($outputPath, '{domain}');
        $hasLocalePlaceholder = str_contains($outputPath, '{locale}');

        if ($isFile && count($locales) > 1 && !$hasLocalePlaceholder) {
            throw new \Exception("Writing several locales into a single file is not possible, add the {locale} placeholder to --output or generate one locale at a time");
        }

        // Options of the generated catalog, taken from the command line
        $outputConfig = new \stdClass();
        $outputConfig->referenceLanguage = array_filter(explode(',', (string)($environment->params['reference'] ?? '')));

        foreach ($locales as $locale) {
            $serializer = Serializer::factory($formatName);
            $serializer->includeLocations = self::_boolParam($environment, 'locations', true);
            $serializer->locale = $locale;
            if (property_exists($serializer, 'transformICU') && array_key_exists('transform-icu', $environment->params)) {
                $serializer->transformICU = self::_boolParam($environment, 'transform-icu', true);
            }
            $serializer->referenceTranslation = self::_getReferenceTranslations($outputConfig, $environment);

            // Use the translation files of the various projects as the source for the translations
            foreach ($domains as $domain) {
                $domain->translator = new \ideatic\l10n\Translation\Provider\Projects($environment->config);
            }

            // One file with every domain, or one file per domain when the path can tell them apart
            $groups = $isFile && !$hasDomainPlaceholder ? [$domains] : array_map(fn(Domain $domain): array => [$domain], $domains);

            foreach ($groups as $group) {
                $extension = $serializer->fileExtension ?? $formatName;

                if ($isFile) {
                    $path = strtr(
                        $outputPath,
                        [
                            '{domain}' => $group[0]->name,
                            '{locale}' => $locale ?? '',
                            '{format}' => $extension,
                        ]
                    );
                } else {
                    $path = IO::combinePaths(
                        $outputPath,
                        strtolower(
                            $environment->config->name . ($group[0]->name == 'app' ? '' : ".{$group[0]->name}") . ($locale ? ".{$locale}" : '') . ".{$extension}",
                        ),
                    );
                }

                if (!is_dir(dirname($path))) {
                    mkdir(dirname($path), 0777, true);
                }

                $domainNames = implode(', ', array_map(fn(Domain $domain): string => $domain->name, $group));
                echo "\n\tGenerating {$domainNames}" . ($locale ? " ({$locale})" : '') . "...";

                file_put_contents($path, $serializer->generate(array_values($group)));

                echo "\tFile written at {$path}";
            }
        }

        echo "\n";
    }

    /**
     * Reads a boolean argument. An argument written without a value (--locations) is true
     */
    private static function _boolParam(Environment $environment, string $name, bool $default): bool
    {
        if (!array_key_exists($name, $environment->params)) {
            return $default;
        }

        $value = $environment->params[$name];

        return $value === null || !in_array(mb_strtolower((string)$value), ['0', 'false', 'no', 'off'], true);
    }

    private static function _isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || (bool)preg_match('/^[a-zA-Z]:[\\\\\/]/', $path);
    }

    /**
     * Gets the reference locales to be included in the generated files
     * @return list<string>
     */
    public static function _getReferenceTranslations(ExtractionDestiny|\stdClass $extractorConfig, Environment $environment): array
    {
        $referenceTranslation = [];
        foreach (Utils::wrapArray($extractorConfig->referenceLanguage ?? []) as $referenceLocale) {
            if ($referenceLocale === 'source') {
                $referenceLocale = $environment->config->sourceLocale;
            }

            if ($referenceLocale === 'all') {
                $referenceTranslation = array_merge(
                    $referenceTranslation,
                    array_map(fn(string|\stdClass $info): string => is_object($info) ? $info->code : $info, $environment->config->targetLocales),
                );
            } elseif ($referenceLocale === 'pending') {
                $referenceTranslation[] = 'pending:' . implode(
                        ',',
                        array_map(fn(string|\stdClass $info): string => is_object($info) ? $info->code : $info, $environment->config->targetLocales)
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
    public static function _filterStrings(Domain $domain, ExtractionDestiny|\stdClass $destinyConfig, Serializer $serializer): array
    {
        $filtered = array_filter($domain->strings, function (/** @param list<LString> $strings */ array $strings) use ($destinyConfig, $domain, $serializer) {
            $valid = true;


            $referenceLocales = $serializer->referenceTranslation ?? [];
            foreach ($referenceLocales as $k => $referenceLocale) {
                if (str_starts_with($referenceLocale, 'pending:')) {
                    unset($referenceLocales[$k]);
                    foreach (explode(',', substr($referenceLocale, strlen('pending:'))) as $localeToCheck) {
                        $referenceLocales[] = $localeToCheck;
                    }
                }
            }

            // Include it when it carries a specific comment
            if ($destinyConfig->filter->hasComment ?? false) {
                $hasSpecificComment = function (LString $string) use ($destinyConfig): bool {
                    if ($destinyConfig->filter->hasComment === true) {
                        if (!!mb_trim($string->comments ?? '')) {
                            return true;
                        }
                    } elseif (str_contains(mb_strtolower($string->comments ?? ''), mb_strtolower($destinyConfig->filter->hasComment))) {
                        return true;
                    }

                    return false;
                };

                if (count(array_filter($strings, $hasSpecificComment)) > 0) {
                    return true;
                }


                // Check the comments of the translations too
                if ($serializer->locale) {
                    $translation = $domain->translator->getTranslation(array_first($strings), $serializer->locale, false);

                    if ($translation?->metadata && $hasSpecificComment($translation->metadata)) {
                        return true;
                    }
                }
                    foreach ($referenceLocales as $locale) {
                        $translation = $domain->translator->getTranslation(array_first($strings), $locale, false);
                        if ($translation?->metadata && $hasSpecificComment($translation->metadata)) {
                            return true;
                        }
                    }

                return false;
            }

            if ($destinyConfig->filter->status ?? false) {
                if ($serializer->locale) {
                    if ($domain->translator->getTranslation(array_first($strings), $serializer->locale, false) !== null) {
                        $valid = $destinyConfig->filter->status == 'pending' ? false : true;
                    }
                } else { // Include it when at least one reference locale has no translation
                    $allTranslated = true;
                    foreach ($referenceLocales as $locale) {
                        if ($domain->translator->getTranslation(array_first($strings), $locale, false) === null) {
                            $allTranslated = false;
                        }
                    }

                    if ($allTranslated) {
                        $valid = $destinyConfig->filter->status == 'pending' ? false : true;
                    }
                }
            }

            return $valid;
        });

        if ($destinyConfig->filter->limit ?? false) {
            $filtered = array_slice($filtered, 0, $destinyConfig->filter->limit);
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

        // Get the available domains
        $start = microtime(true);
        echo "\nScanning localizable strings... ";

        $domains = $extractor->getDomains();

        // Show the summary
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
