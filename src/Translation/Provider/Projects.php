<?php

namespace ideatic\l10n\Translation\Provider;

use ideatic\l10n\Catalog\Catalog;
use ideatic\l10n\Catalog\Loader\Loader;
use ideatic\l10n\Config;
use ideatic\l10n\Domain;
use ideatic\l10n\LString;
use ideatic\l10n\Translation\Provider;
use ideatic\l10n\Utils\IO;
use ideatic\l10n\Utils\Locale;

/**
 * Implementa un proveedor de traducciones que utiliza los proyectos definidos en la configuración como fuente
 */
class Projects implements Provider
{
    /** @var Config */
    public $config;

    public function __construct(Config $l10nConfig)
    {
        $this->config = $l10nConfig;
    }

    public function getTranslation(LString $string, string $locale, bool $allowFallback = true): ?string
    {
        /** @var Loader[] $loadedCatalogs */
        static $loadedCatalogs = [];

        if (!$string->domain && !$string->domainName) {
            throw new \Exception("String domain not defined");
        }

        $testLocales = [$locale];
        if ($allowFallback) {
            $testLocales = Locale::getVariants($locale);
            foreach (Locale::getFallback($locale) as $fallback) {
                $testLocales[] = $fallback;
            }
            $testLocales[] = $this->config->fallbackLocale ?? 'en';

            $testLocales = array_unique($testLocales);
        }

        foreach ($testLocales as $locale) {
            $domainName = $string->domain->name ?? $string->domainName;
            $catalogKey = "{$domainName}@{$locale}";

            if ($locale == $this->config->sourceLocale) {
                return $string->text;
            }

            // Buscar en las traducciones disponibles
            if (!array_key_exists($catalogKey, $loadedCatalogs)) { // Intentar traducciones de este dominio
                $loadedCatalogs[$catalogKey] = $this->loadCatalog($string->domain ?? $string->domainName, $locale);
            }

            if (!empty($loadedCatalogs[$catalogKey])) {
                $translation = $loadedCatalogs[$catalogKey]->getTranslation($string);
                if ($translation !== null) {
                    return $translation;
                }
            }
        }

        return null;
    }

    /**
     * Carga las traducciones para el locale indicado en las cadenas del grupo recibido.
     *
     * @param Domain|string
     */
    public function loadCatalog($domain, string $locale): ?Catalog
    {
        // Cargar traducciones desde algún proyecto
        foreach ($this->config->projects as $projectName => $projectConfig) {
            if (isset($projectConfig->translations->path)) {
                $path = IO::combinePaths(
                    $projectConfig->translations->path,
                    strtr(
                        $projectConfig->translations->template,
                        [
                            '{domain}' => is_string($domain) ? $domain : $domain->name,
                            '{locale}' => $locale,
                            '{format}' => $projectConfig->translations->format,
                        ]
                    )
                );

                if (!isset($loadedFiles[$path]) && file_exists($path)) {
                    $loader = Loader::factory($projectConfig->translations->format);
                    return $loader->load(file_get_contents($path), $locale);
                }
            }
        }

        return null;
    }
}