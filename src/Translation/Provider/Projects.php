<?php

declare(strict_types=1);

namespace ideatic\l10n\Translation\Provider;

use Exception;
use ideatic\l10n\Catalog\Catalog;
use ideatic\l10n\Catalog\Loader\Loader;
use ideatic\l10n\Catalog\Serializer\Serializer;
use ideatic\l10n\Catalog\Translation;
use ideatic\l10n\Config;
use ideatic\l10n\Domain;
use ideatic\l10n\LString;
use ideatic\l10n\ProjectTranslations;
use ideatic\l10n\Translation\Provider;
use ideatic\l10n\Utils\IO;
use ideatic\l10n\Utils\Locale;
use stdClass;

/**
 * A translation provider that uses the projects defined in the configuration as its source
 */
class Projects implements Provider, \ideatic\l10n\Translation\CatalogProvider
{
    /** @var array<string,Catalog> */
    private array $_loadedCatalogs = [];

    public function __construct(public readonly Config $config) {}

    public function getTranslation(LString $string, string $locale, bool $allowFallback = true): ?Translation
    {
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
                return new Translation($string->text);
            }

            // Look it up among the available translations
            if (!array_key_exists($catalogKey, $this->_loadedCatalogs)) { // Try the translations of this domain
                $this->_loadedCatalogs[$catalogKey] = $this->loadCatalog($string->domain ?? $string->domainName, $locale);
            }

            $translation = $this->_loadedCatalogs[$catalogKey]?->getTranslation($string);
            if ($translation !== null) {
                return $translation;
            }
        }

        return null;
    }

    /**
     * Loads the translations for the given locale into the strings of the received group.
     */
    public function loadCatalog(Domain|string $domain, string $locale): ?Catalog
    {
        // Load translations from some project
        foreach ($this->config->projects as $projectConfig) {
            /** @var ProjectTranslations|stdClass $translations */
            foreach ($projectConfig->translations ?? [] as $translations) {
                if (isset($translations->path)) {
                    $path = IO::combinePaths(
                        $translations->path,
                        strtr(
                            $translations->template,
                            [
                                '{domain}' => is_string($domain) ? $domain : $domain->name,
                                '{locale}' => $locale,
                                '{format}' => Serializer::factory($translations->format)->fileExtension ?? $translations->format,
                            ]
                        )
                    );

                    if (file_exists($path)) {
                        $loader = Loader::factory($translations->format);
                        return $loader->load(IO::read($path), $locale);
                    }
                }
            }
        }

        return null;
    }
}
