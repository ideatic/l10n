<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis;

use ideatic\l10n\Catalog\Catalog;
use ideatic\l10n\Catalog\Translation;
use ideatic\l10n\Config;
use ideatic\l10n\Domain;
use ideatic\l10n\LString;
use ideatic\l10n\Translation\CatalogProvider;
use ideatic\l10n\Translation\Provider;
use ideatic\l10n\Utils\Utils;
use stdClass;

/**
 * State shared by every check during an analysis
 */
class Context
{
    /** @var array<string, Catalog|null> */
    private array $_catalogs = [];

    /** @var array<string, list<array{domain: Domain, id: string, strings: list<LString>}>>|null */
    private ?array $_textIndex = null;

    /** @var array<string, array{imported: bool, exported: bool}> */
    private array $_pipeline = [];

    /**
     * @param list<Domain> $domains
     * @param list<string> $locales Target locales to check (never includes the source locale)
     */
    public function __construct(
        public readonly Config $config,
        public readonly array $domains,
        public readonly array $locales,
        public readonly Provider $translator,
        public readonly Options $options,
    ) {}

    /**
     * Gets the exact translation of a string, without falling back to any other locale
     */
    public function getTranslation(LString $string, string $locale): ?Translation
    {
        return $this->translator->getTranslation($string, $locale, false);
    }

    /**
     * Gets the catalog of a domain in the given locale, caching the result
     */
    public function catalog(Domain $domain, string $locale): ?Catalog
    {
        if (!($this->translator instanceof CatalogProvider)) {
            return null;
        }

        $key = "{$domain->name}@{$locale}";

        if (!array_key_exists($key, $this->_catalogs)) {
            $this->_catalogs[$key] = $this->translator->loadCatalog($domain, $locale);
        }

        return $this->_catalogs[$key];
    }

    /**
     * Tells whether a domain takes part in the translation pipeline, that is, whether some "sources" entry imports
     * its translations or some "exports" destiny sends its strings out to be translated
     *
     * @return array{imported: bool, exported: bool}
     */
    public function pipeline(Domain $domain): array
    {
        if (isset($this->_pipeline[$domain->name])) {
            return $this->_pipeline[$domain->name];
        }

        $sources = self::_sourceDomains($this->config);
        $exports = self::_exportDomains($this->config);

        return $this->_pipeline[$domain->name] = [
            'imported' => $sources === null || in_array($domain->name, $sources, true),
            'exported' => $exports === null || in_array($domain->name, $exports, true),
        ];
    }

    /**
     * Tells whether a domain ever gets translated.
     *
     * A domain in neither "sources" nor "exports" has no catalog and never will, so checking its translations only
     * repeats, once per string and per locale, what unconfigured-domain already reports once, and buries the rest
     * of the analysis.
     */
    public function isTranslated(Domain $domain): bool
    {
        // When the configuration covers no domain at all there is no pipeline to consult, and a forgotten domain
        // cannot be told apart from a project that simply does not describe this in its l10n.json: check them all
        if (!$this->_hasPipeline()) {
            return true;
        }

        $pipeline = $this->pipeline($domain);

        return $pipeline['imported'] || $pipeline['exported'];
    }

    /** Tells whether the project configuration covers any domain at all */
    private function _hasPipeline(): bool
    {
        $sources = self::_sourceDomains($this->config);
        $exports = self::_exportDomains($this->config);

        return $sources === null || $exports === null || $sources !== [] || $exports !== [];
    }

    /**
     * Domains covered by the import configuration, or null when it covers every one of them
     *
     * @return list<string>|null
     */
    private static function _sourceDomains(Config $config): ?array
    {
        $sources = $config->sources ?? null;

        if ($sources === null) {
            return [];
        }

        if (is_array($sources)) { // A single configuration applying to every domain
            return null;
        }

        $names = array_keys(get_object_vars($sources));

        return in_array('all', $names, true) ? null : array_map(strval(...), $names);
    }

    /**
     * Domains included in some extraction destiny, or null when one of them includes every domain
     *
     * @return list<string>|null
     */
    private static function _exportDomains(Config $config): ?array
    {
        $names = [];

        /** @var stdClass $export */
        foreach (Utils::wrapArray($config->exports ?? []) as $export) {
            if (!($export->enabled ?? true)) {
                continue;
            }

            if (!isset($export->domains)) { // No filter: every domain is included
                return null;
            }

            $names = [...$names, ...array_map(strval(...), (array)$export->domains)];
        }

        return array_values(array_unique($names));
    }

    /**
     * Index of every string in the analysis grouped by its source text, used by the checks that need to compare
     * strings across domains
     *
     * @return array<string, list<array{domain: Domain, id: string, strings: list<LString>}>>
     */
    public function textIndex(): array
    {
        if ($this->_textIndex !== null) {
            return $this->_textIndex;
        }

        $index = [];

        foreach ($this->domains as $domain) {
            foreach ($domain->strings as $stringID => $strings) {
                $text = $strings[0]->translatableText();

                if ($text === '') {
                    continue;
                }

                $index[$text][] = ['domain' => $domain, 'id' => strval($stringID), 'strings' => array_values($strings)];
            }
        }

        return $this->_textIndex = $index;
    }

    /**
     * Gets the places ("file:line") where a string appears
     *
     * @param array<LString> $strings
     *
     * @return list<string>
     */
    public static function locations(array $strings, int $limit = 5): array
    {
        $locations = [];

        foreach ($strings as $string) {
            $file = $string->file ?? null;

            if ($file === null) {
                continue;
            }

            $location = isset($string->line) ? "{$file}:{$string->line}" : $file;

            if (!in_array($location, $locations, true)) {
                $locations[] = $location;
            }
        }

        if ($limit > 0 && count($locations) > $limit) {
            $remaining = count($locations) - $limit;
            $locations = array_slice($locations, 0, $limit);
            $locations[] = "(+{$remaining} more)";
        }

        return $locations;
    }

    /**
     * Prepares a text to be shown on a single line of the report
     */
    public static function preview(?string $text, int $length = 70): string
    {
        $text = strtr($text ?? '', ["\n" => '\n', "\r" => '\r', "\t" => '\t', "\xc2\xa0" => '<nbsp>']);

        if (mb_strlen($text) > $length) {
            $text = mb_substr($text, 0, $length - 1) . '…';
        }

        return $text;
    }
}
