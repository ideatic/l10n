<?php

declare(strict_types=1);

namespace ideatic\l10n\CLI\Tools;

use ideatic\l10n\Catalog\Catalog;
use ideatic\l10n\Catalog\Loader\Loader;
use ideatic\l10n\Catalog\Serializer\Serializer;
use ideatic\l10n\CLI\Environment;
use ideatic\l10n\Domain;
use ideatic\l10n\ImportSource;
use ideatic\l10n\LString;
use ideatic\l10n\Project;
use ideatic\l10n\ProjectTranslations;
use ideatic\l10n\Utils\IO;
use ideatic\l10n\Utils\Locale;
use ideatic\l10n\Utils\Utils;
use stdClass;

class Importer
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

        $domainsConfig = $environment->config->sources ?? new stdClass();
        if (is_array($domainsConfig)) {
            $domainsConfig = ['all' => $domainsConfig];
        } else {
            $domainsConfig = get_object_vars($environment->config->sources ?? new stdClass());
        }

        foreach ($domains as $domain) {
            echo "\n\n#### {$domain->name} domain\n\n";

            $domainConfig = $domainsConfig[$domain->name] ?? $domainsConfig['all'] ?? null;
            if (!isset($domainConfig)) {
                echo "\tNo configuration found for domain '{$domain->name}'\n";
                continue;
            }

            foreach (self::_getLocalesToWrite($domainConfig, $environment) as $locale) {
                if ($locale == $environment->config->sourceLocale) {
                    continue;
                }

                echo "\n\t## " . Locale::getName($locale) . " ({$locale})\n";

                // Importar las traducciones (solo del idioma actual) actualizadas desde todas las fuentes
                $domain->translator = self::_prepareTranslator($domainConfig, $domain, $locale, $environment);

                // Mostrar resumen
                $notFound = array_filter(
                    $domain->strings,
                    fn(array $stringLocations) => $domain->translator->getTranslation($stringLocations[0], $locale) === null,
                );

                if (!empty($notFound)) {
                    $summaryString = function (LString $string): string {
                        $string = mb_trim(str_replace(["\n", "\r"], ['\n', '\r'], $string->text));
                        if (mb_strlen($string) > 50) {
                            $string = mb_substr($string, 0, 47) . '...';
                        }
                        return "'{$string}'";
                    };
                    echo "\t\t" . strtr(
                            '%count% strings not found: %strings%',
                            [
                                '%count%' => number_format(count($notFound)),
                                '%strings%' => implode(', ', array_map(fn(array $strings) => $summaryString($strings[0]), array_slice($notFound, 0, 5))) . '...',
                            ],
                        ) . "\n";
                }

                // Escribir traducciones en los proyectos
                /** @var Project|\stdClass $project */
                foreach ($environment->config->projects as $project) {
                    /** @var ProjectTranslations|\stdClass $translationsStore */
                    foreach ($project->translations ?? [] as $translationsStore) {
                        if (isset($translationsStore->path)) {
                            self::_saveProjectTranslations($project, $translationsStore, $domain, $locale);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param array<ImportSource|stdClass> $sources
     * @param Environment $environment
     * @return list<string>
     */
    public static function _getLocalesToWrite(array|ImportSource|stdClass $sources, Environment $environment): array
    {
        $localesToMerge = [];
        /** @var ImportSource $translationsSource */
        foreach (Utils::wrapArray($sources) as $translationsSource) {
            $locales = $translationsSource->locales ?? array_map(fn(string|stdClass $info): string => is_object($info) ? $info->code : $info,
                $environment->config->targetLocales);
            if (isset($environment->params['lang']) || isset($environment->params['locale'])) {
                $locales = explode(',', $environment->params['locale'] ?? $environment->params['lang']);
            }
            $localesToMerge = array_merge($localesToMerge, $locales);
        }
        return array_unique($localesToMerge);
    }

    /**
     * @param ImportSource|stdClass|list<ImportSource> $sources
     */
    public static function _prepareTranslator(
        ImportSource|stdClass|array $sources,
        Domain $domain,
        string $locale,
        Environment $environment
    ): \ideatic\l10n\Translation\Provider {
        $loadedCatalogs = [];
        /** @var ImportSource|stdClass $source */
        foreach (Utils::wrapArray($sources) as $sourceIndex => $source) {
            $translationsCatalog = self::_getCatalogFromSource($domain, $source, $locale, $environment);

            if ($translationsCatalog) {
                $loadedCatalogs[] = $translationsCatalog;

                if ($source->addComment ?? null) { // Añadir comentario si el proveedor de la traducción es el actual
                    foreach ($domain->strings as $string) {
                        foreach ($loadedCatalogs as $catalog) {
                            $translation = $catalog->getTranslation($string[0]);
                            if (isset($translation)) { // Si la cadena está en el catálogo
                                if ($catalog === $translationsCatalog) {
                                    // Comprobar si ya existe el comentario en alguna línea
                                    if (!in_array($source->addComment, explode("\n", $translation->metadata->comments ?? ''))) {
                                        $translation->metadata->comments ??= '';
                                        $translation->metadata->comments = mb_trim("{$translation->metadata->comments}\n{$source->addComment}");
                                    }
                                } else {
                                    break;
                                }
                            }
                        }
                    }
                }
            } else {
                $sourceName = isset($source->name) ? "'{$source->name}'" : "#{$sourceIndex}";
                echo "\t\tUnable to get translations for '{$domain->name}', locale {$locale}, source {$sourceName}\n";
            }
        }

        // Configurar el traductor del dominio para que utilice las traducciones cargadas, dando prioridad a las fuentes más recientes
        $domain->translator ??= new \ideatic\l10n\Translation\Provider\Fallback();
        if (!($domain->translator instanceof \ideatic\l10n\Translation\Provider\Fallback)) {
            throw new \Exception("Domain '{$domain->name}' already has a translator assigned, but it's not a Fallback provider, can't merge translations!");
        }

        foreach (array_reverse($loadedCatalogs) as $catalog) {
            $domain->translator->prependTranslator(new \ideatic\l10n\Translation\Provider\Catalog($catalog));
        }

        // Añadir como último recurso las traducciones ya existentes en los archivos de traducción de los proyectos
        $domain->translator->addTranslator(new \ideatic\l10n\Translation\Provider\Projects($environment->config));

        return $domain->translator;
    }

    private static function _getCatalogFromSource(Domain $domain, ImportSource|stdClass $source, string $locale, Environment $environment): ?Catalog
    {
        if (isset($source->locales) && !in_array($locale, $source->locales)) {
            return null;
        }

        $replacements = [
            '{domain}' => $domain->name,
            '{locale}' => $locale,
        ];
        foreach ($environment->config->projects as $project) {
            $replacements["{{$project->name}}"] = $project->path;
        }

        if (isset($source->source)) {
            $location = strtr($source->source, $replacements);

            if (str_contains($source->source, '://')) { // Descargar de Internet
                echo "\t\tDownloading {$location}...";
                $content = @file_get_contents($location);
            } else {
                echo "\t\tReading {$location}...";
                $content = @file_get_contents($location);
            }
        } elseif (isset($source->script)) { // Ejecutar comando
            echo "\t\tExecuting script...";
            exec(strtr($source->script, $replacements), $content);
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
        try {
            $loader = Loader::factory($source->format ?? 'po');
            $catalog = $loader->load($content, $locale);
            $catalog->removeComments(); // Eliminar comentarios de las traducciones para que no se incluyan al serializar
        } catch (\Exception $e) {
            echo "\033[31m\n\t\tError loading translations for domain '{$domain->name}' locale {$locale}: {$e->getMessage()}\033[0m\n";
            throw $e;
        }

        echo " {$catalog->entryCount()} translations\n";

        return $catalog;
    }

    /**
     * Guarda las cadenas traducidas del proyecto indicado en la ubicación especificada
     */
    public static function _saveProjectTranslations(stdClass|Project $project, ProjectTranslations|\stdClass $translationsStore, Domain $domain, string $locale): void
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
        if (!empty($translationsStore->referenceTranslations)) {
            $serializer->referenceTranslation = $translationsStore->referenceTranslations;
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
            mkdir($translationsStore->path, recursive: true);
        }

        $catalogPath = IO::combinePaths($translationsStore->path, $fileName);
        file_put_contents($catalogPath, $serializer->generate([$domain], $project));

        echo "\t\t" . strtr('Written %path%', ['%path%' => $catalogPath,]) . "\n";
    }
}
