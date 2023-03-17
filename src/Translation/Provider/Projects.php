<?php

declare(strict_types=1);

namespace ideatic\l10n\Translation\Provider;

use Exception;
use ideatic\l10n\Catalog\Catalog;
use ideatic\l10n\Catalog\Loader\Loader;
use ideatic\l10n\Config;
use ideatic\l10n\Domain;
use ideatic\l10n\LString;
use ideatic\l10n\ProjectTranslations;
use ideatic\l10n\Translation\Provider;
use ideatic\l10n\Utils\IO;
use ideatic\l10n\Utils\Locale;
use stdClass;

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

        if (empty($string->domain) && empty($string->domainName)) {
            throw new Exception("String domain not defined");
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
     */
    public function loadCatalog(Domain|string $domain, string $locale): ?Catalog
    {
        // Cargar traducciones desde algún proyecto
        foreach ($this->config->projects as $projectConfig) {
            /** @var ProjectTranslations|stdClass $translations */
            $translations = $projectConfig->translations ?? null;

            if (isset($translations->path)) {
                $path = IO::combinePaths(
                    $translations->path,
                    strtr(
                        $translations->template,
                        [
                            '{domain}' => is_string($domain) ? $domain : $domain->name,
                            '{locale}' => $locale,
                            '{format}' => $translations->format,
                        ]
                    )
                );

                if (file_exists($path)) {
                    $loader = Loader::factory($translations->format);
                    return $loader->load(file_get_contents($path), $locale);
                }
            }
        }

        return null;
    }
}