<?php

declare(strict_types=1);

namespace ideatic\l10n\CLI\Tools;

use ideatic\l10n\Catalog\Catalog;
use ideatic\l10n\Catalog\Loader\Loader;
use ideatic\l10n\Catalog\Serializer\Serializer;
use ideatic\l10n\CLI\Environment;
use ideatic\l10n\Domain;
use ideatic\l10n\DomainConfig;
use ideatic\l10n\Project;
use ideatic\l10n\ProjectTranslations;
use ideatic\l10n\Utils\IO;
use ideatic\l10n\Utils\Locale;
use ideatic\l10n\Utils\Utils;
use stdClass;

class Merger
{
    public static function run(Environment $environment): void
    {
        // Obtener dominios disponibles
        $domains = Extractor::scanDomains($environment);

        if (isset($environment->params['domain'])) {
            $domains = array_filter(
                $domains,
                function (Domain $domain) use ($environment) {
                    return in_array($domain->name, explode(',', $environment->params['domain']));
                },
            );
        }

        $domainsConfig = $environment->config->tools->merge ?? new stdClass();
        if (is_array($domainsConfig)) {
            $domainsConfig = ['all' => $domainsConfig];
        } else {
            $domainsConfig = get_object_vars($environment->config->tools->merge ?? new stdClass());
        }

        foreach ($domains as $domain) {
            echo "\n\n#### {$domain->name} domain\n\n";

            $domainConfig = $domainsConfig[$domain->name] ?? $domainsConfig['all'] ?? null;
            if (!isset($domainConfig)) {
                echo "\tNo configuration found for domain '{$domain->name}'\n";
                continue;
            }

            // Obtener primero todos los idiomas a procesar
            $localesToMerge = self::_getLocalesToMerge($domainConfig, $environment);

            foreach ($localesToMerge as $locale) {
                if ($locale == $environment->config->sourceLocale) {
                    continue;
                }

                echo "\n\t## " . Locale::getName($locale) . " ({$locale})\n";

                // Obtener catálogo con las traducciones actualizadas desde todas las fuentes
                $loadedCatalogs = [];
                /** @var DomainConfig|stdClass $translationsSource */
                foreach (Utils::wrapArray($domainConfig) as $sourceIndex => $translationsSource) {
                    $translationsCatalog = self::_getCatalog($domain, $translationsSource, $locale, $environment);

                    if ($translationsCatalog) {
                        $loadedCatalogs[] = $translationsCatalog;

                        if ($translationsSource->addComment ?? null) { // Añadir comentario si el proveedor de la traducción es el actual
                            foreach ($domain->strings as $string) {
                                foreach ($loadedCatalogs as $catalog) {
                                    $translation = $catalog->getTranslation($string[0]);
                                    if (isset($translation)) { // Si la cadena está en el catálogo
                                        if ($catalog === $translationsCatalog) {
                                            // Comprobar si ya existe el comentario en alguna línea
                                            if (!in_array($translationsSource->addComment, explode("\n", $translation->metadata->comments ?? ''))) {
                                                $translation->metadata->comments ??= '';
                                                $translation->metadata->comments = mb_trim("{$translation->metadata->comments}\n{$translationsSource->addComment}");
                                            }
                                        } else {
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                    } else { // Usar traducciones ya existentes en otros proyectos
                        echo "\t\tUnable to get translations for '{$domain->name}', locale {$locale}, source #{$sourceIndex}\n";
                    }
                }
                $domain->translator = new \ideatic\l10n\Translation\Provider\Catalog($loadedCatalogs);

                // Mostrar resumen
                $notFound = array_filter(
                    $domain->strings,
                    fn(array $stringLocations) => $domain->translator->getTranslation($stringLocations[0], $locale) === null,
                );

                if (!empty($notFound)) {
                    echo "\t\t" . strtr(
                            '%count% strings not found: %strings%',
                            [
                                '%count%' => number_format(count($notFound)),
                                '%strings%' => implode(', ', array_map(fn(array $strings) => $strings[0]->id, array_slice($notFound, 0, 5))) . '...',
                            ],
                        ) . "\n";
                }

                // Guardar traducciones en los proyectos que lo requieran
                /** @var Project|\stdClass $project */
                foreach ($environment->config->projects as $project) {
                    /** @var ProjectTranslations|\stdClass $translationsStore */
                    foreach ($project->translations ?? [] as $translationsStore) {
                        if (!isset($translationsStore->path)) {
                            continue;
                        }
                        self::_saveProjectTranslations($project, $domain, $locale, $translationsStore);
                    }
                }
            }
        }
    }

    private static function _getCatalog(Domain $domain, DomainConfig|stdClass $domainConfig, string $locale, Environment $environment): ?Catalog
    {
        if (isset($domainConfig->locales) && !in_array($locale, $domainConfig->locales)) {
            return null;
        }

        $replacements = [
            '{domain}' => $domain->name,
            '{locale}' => $locale,
        ];
        foreach ($environment->config->projects as $project) {
            $replacements["{{$project->name}}"] = $project->path;
        }

        if (isset($domainConfig->source)) {
            $location = strtr($domainConfig->source, $replacements);

            if (str_contains($domainConfig->source, '://')) { // Descargar de Internet
                echo "\t\tDownloading {$location}...";
                $content = @file_get_contents($location);
            } else {
                echo "\t\tReading {$location}...";
                $content = @file_get_contents($location);
            }
        } elseif (isset($domainConfig->script)) { // Ejecutar comando
            echo "\t\tExecuting script...";
            exec(strtr($domainConfig->script, $replacements), $content);
        } else {
            // echo "\t\tNo source defined for domain '{$domain->name}' locale {$locale}\n";
            return null;
        }

        if (empty($content)) {
            echo "\033[31m\n\t\tEmpty response for domain '{$domain->name}' locale {$locale}\033[0m\n";
            // throw new \Exception("\tEmpty response for domain '{$domain->name}' locale {$locale}\n");
            return null;
        }

        // Procesar archivo recibido
        $loader = Loader::factory($domainConfig->format ?? 'po');
        $catalog = $loader->load($content, $locale);
        $catalog->removeComments(); // Eliminar comentarios de las traducciones para que no se incluyan al serializar

        echo " {$catalog->entryCount()} entries\n";

        return $catalog;
    }

    /**
     * @param array<DomainConfig|stdClass> $sources
     * @param Environment $environment
     * @return list<string>
     */
    public static function _getLocalesToMerge(array|DomainConfig|stdClass $sources, Environment $environment): array
    {
        $localesToMerge = [];
        /** @var DomainConfig $translationsSource */
        foreach (Utils::wrapArray($sources) as $translationsSource) {
            $locales = $translationsSource->locales ?? array_map(fn(string|stdClass $info): string => is_object($info) ? $info->id : $info, $environment->config->locales);
            if (isset($environment->params['lang']) || isset($environment->params['locale'])) {
                $locales = explode(',', $environment->params['locale'] ?? $environment->params['lang']);
            }
            $localesToMerge = array_merge($localesToMerge, $locales);
        }
        return array_unique($localesToMerge);
    }

    /**
     * Guarda las cadenas traducidas del proyecto indicado en la ubicación especificada
     */
    public static function _saveProjectTranslations(stdClass|Project $project, Domain $domain, string $locale, ProjectTranslations|\stdClass $translationsStore): void
    {
        $destinyFormat = $translationsStore->format;
        $serializer = Serializer::factory($destinyFormat);
        $serializer->locale = $locale;
        if (isset($translationsStore->includeLocations)) {
            $serializer->includeLocations = $translationsStore->includeLocations;
        }
        if (isset($serializer->transformICU, $translationsStore->transformICU)) {
            $serializer->transformICU = $translationsStore->transformICU;
        }

        $fileName = strtr(
            $translationsStore->template,
            [
                '{domain}' => $domain->name,
                '{locale}' => $locale,
                '{format}' => $serializer->fileExtension ?? $destinyFormat,
            ],
        );

        if (!is_dir($translationsStore->path)) {
            mkdir($translationsStore->path);
        }

        $catalogPath = IO::combinePaths($translationsStore->path, $fileName);
        file_put_contents($catalogPath, $serializer->generate([$domain], $project));

        echo "\t\t" . strtr('Written %path%', ['%path%' => $catalogPath,]) . "\n";
    }
}
