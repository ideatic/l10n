<?php

declare(strict_types=1);

use ideatic\l10n\Catalog\Catalog;
use ideatic\l10n\Catalog\Translation;
use ideatic\l10n\Config;
use ideatic\l10n\Domain;
use ideatic\l10n\LString;
use ideatic\l10n\Tools\Analysis\Check;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Options;
use ideatic\l10n\Translation\CatalogProvider;
use ideatic\l10n\Translation\Provider;

/**
 * Proveedor de traducciones en memoria utilizado por las pruebas del analizador
 */
class FixtureTranslator implements Provider, CatalogProvider
{
    /**
     * @param array<string, Catalog> $catalogs Catálogos indexados por "dominio@idioma"
     */
    public function __construct(private readonly array $catalogs = []) {}

    public function getTranslation(LString $string, string $locale, bool $allowFallback = true): ?Translation
    {
        $domainName = $string->domain->name ?? $string->domainName;

        return ($this->catalogs["{$domainName}@{$locale}"] ?? null)?->getTranslation($string);
    }

    public function loadCatalog(Domain|string $domain, string $locale): ?Catalog
    {
        $domainName = is_string($domain) ? $domain : $domain->name;

        return $this->catalogs["{$domainName}@{$locale}"] ?? null;
    }
}

/**
 * Utilidades para construir dominios, catálogos y contextos de análisis en memoria
 */
trait AnalysisFixtures
{
    /**
     * @param array{
     *     id?: string,
     *     context?: string,
     *     comments?: string,
     *     isICU?: bool,
     *     domain?: string,
     *     file?: string,
     *     line?: int,
     *     placeholders?: array<string, string>
     * } $options
     */
    protected function _string(string $text, array $options = []): LString
    {
        $string = new LString();
        $string->text = $text;
        $string->id = $options['id'] ?? $text;
        $string->domainName = $options['domain'] ?? 'app';
        $string->context = $options['context'] ?? null;
        $string->comments = $options['comments'] ?? null;
        $string->isICU = $options['isICU'] ?? false;
        $string->file = $options['file'] ?? 'src/app.php';
        $string->line = $options['line'] ?? 1;

        if (isset($options['placeholders'])) {
            $string->placeholders = $options['placeholders'];
        }

        return $string;
    }

    /**
     * @param list<LString> $strings
     *
     * @return list<Domain>
     */
    protected function _domains(array $strings): array
    {
        return Domain::generate($strings);
    }

    /**
     * @param array<string, string> $translations Traducción indexada por el ID completo de la cadena
     */
    protected function _catalog(string $locale, array $translations): Catalog
    {
        $entries = [];

        foreach ($translations as $id => $translation) {
            $metadata = new LString();
            $metadata->id = strval($id);

            $entries[strval($id)] = new Translation($translation, $metadata);
        }

        return new Catalog($locale, $entries);
    }

    /**
     * @param list<LString>          $strings
     * @param list<string>           $locales
     * @param array<string, Catalog> $catalogs   Catálogos indexados por "dominio@idioma"
     * @param array<string, mixed>   $configuration Propiedades adicionales de la configuración del proyecto
     */
    protected function _context(
        array $strings,
        array $locales = [],
        array $catalogs = [],
        ?Options $options = null,
        array $configuration = [],
    ): Context {
        $config = new Config();
        $config->name = 'test';
        $config->sourceLocale = 'en';
        $config->targetLocales = $locales;
        $config->projects = [];

        foreach ($configuration as $property => $value) {
            if ($value === null) { // Permite comprobar el comportamiento cuando falta una opción
                unset($config->{$property});
            } else {
                $config->{$property} = $value;
            }
        }

        $domains = $this->_domains($strings);
        $translator = new FixtureTranslator($catalogs);

        foreach ($domains as $domain) {
            $domain->translator = $translator;
        }

        return new Context($config, $domains, $locales, $translator, $options ?? new Options());
    }

    /**
     * Ejecuta una comprobación y devuelve sus mensajes
     *
     * @param list<Issue> $issues
     *
     * @return list<string>
     */
    protected function _messages(array $issues): array
    {
        return array_map(static fn(Issue $issue): string => $issue->message, $issues);
    }

    /**
     * Comprueba si alguno de los problemas encontrados contiene el texto indicado
     *
     * @param list<Issue> $issues
     */
    protected function _hasMessage(array $issues, string $needle): bool
    {
        return array_any($issues, static fn(Issue $issue): bool => str_contains($issue->message, $needle));
    }

    /**
     * @param list<LString>          $strings
     * @param list<string>           $locales
     * @param array<string, Catalog> $catalogs
     * @param array<string, mixed>   $configuration
     *
     * @return list<Issue>
     */
    protected function _run(
        Check $check,
        array $strings,
        array $locales = [],
        array $catalogs = [],
        ?Options $options = null,
        array $configuration = [],
    ): array {
        return $check->analyze($this->_context($strings, $locales, $catalogs, $options, $configuration));
    }
}
