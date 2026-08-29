<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis\Check;

use ideatic\l10n\Tools\Analysis\Check;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Scope;
use ideatic\l10n\Tools\Analysis\Severity;
use ideatic\l10n\Utils\ICU\Plural;
use ideatic\l10n\Utils\IO;
use ideatic\l10n\Utils\Utils;
use stdClass;
use Throwable;

/**
 * Reviews the project configuration itself for mistakes that silently leave strings untranslated
 */
class ConfigHygiene extends Check
{
    /** Paths that only exist on the machine of whoever wrote the configuration */
    private const string PERSONAL_PATH = '#^(/Users/|/home/|/root/|[A-Za-z]:[\\\\/])#';

    public function name(): string
    {
        return 'config';
    }

    /** A configuration problem is worth knowing about whichever half is being audited */
    public function scope(): Scope
    {
        return Scope::Both;
    }

    public function group(): string
    {
        return 'pipeline';
    }

    public function title(): string
    {
        return 'Configuration';
    }

    public function analyze(Context $context): array
    {
        $issues = [
            ...$this->_checkLocales($context),
            ...$this->_checkDomainReferences($context),
            ...$this->_checkPersonalPaths($context),
        ];

        return $issues;
    }

    /**
     * @return list<Issue>
     */
    private function _checkLocales(Context $context): array
    {
        $issues = [];

        if (!isset($context->config->sourceLocale)) {
            $issues[] = new Issue(
                check: $this->name(),
                severity: Severity::Warning,
                message: 'No "sourceLocale" is defined, so translations cannot be told apart from source strings',
                priority: 100,
            );
        }

        foreach ($context->config->targetLocales ?? [] as $localeInfo) {
            $locale = is_object($localeInfo) ? ($localeInfo->code ?? '') : strval($localeInfo);

            if ($locale === '') {
                continue;
            }

            if (!Plural::isKnownLocale($locale)) {
                $issues[] = new Issue(
                    check: $this->name(),
                    severity: Severity::Warning,
                    message: "Target locale '{$locale}' is not recognised by ICU, so its plural rules fall back to the root ones",
                    locale: $locale,
                    priority: 50,
                );
            }
        }

        return $issues;
    }

    /**
     * Checks that the configuration does not reference domains that no longer exist
     *
     * @return list<Issue>
     */
    private function _checkDomainReferences(Context $context): array
    {
        $available = array_column($context->domains, 'name');
        $referenced = [];

        /** @var stdClass $export */
        foreach (Utils::wrapArray($context->config->exports ?? []) as $export) {
            foreach ((array)($export->domains ?? []) as $domainName) {
                $referenced['exports'][] = strval($domainName);
            }
        }

        $sources = $context->config->sources ?? null;
        if (is_object($sources)) {
            foreach (array_keys(get_object_vars($sources)) as $domainName) {
                $referenced['sources'][] = strval($domainName);
            }
        }

        $issues = [];

        foreach ($referenced as $section => $names) {
            foreach (array_unique($names) as $name) {
                if ($name === 'all' || in_array($name, $available, true)) {
                    continue;
                }

                $issues[] = new Issue(
                    check: $this->name(),
                    severity: Severity::Warning,
                    message: "\"{$section}\" references the domain '{$name}', which no longer has any string in the source code",
                    priority: 40,
                );
            }
        }

        return $issues;
    }

    /**
     * Looks for personal absolute paths in the original configuration file
     *
     * @return list<Issue>
     */
    private function _checkPersonalPaths(Context $context): array
    {
        $path = $context->config->path ?? null;

        if ($path === null || !is_readable($path)) {
            return [];
        }

        try {
            $raw = json_decode(IO::read($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        $issues = [];

        foreach (self::_flatten(is_array($raw) ? $raw : []) as $key => $value) {
            if (is_string($value) && preg_match(self::PERSONAL_PATH, $value)) {
                $issues[] = new Issue(
                    check: $this->name(),
                    severity: Severity::Warning,
                    message: "\"{$key}\" points to the absolute path \"{$value}\", which only exists on one machine",
                    details: ['Use a path relative to the config file, or a {project} placeholder'],
                    priority: 60,
                );
            }
        }

        return $issues;
    }

    /**
     * Flattens a nested array into "path.to.the.key" => value pairs
     *
     * @param array<mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function _flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? strval($key) : "{$prefix}.{$key}";

            if (is_array($value)) {
                $flat = [...$flat, ...self::_flatten($value, $path)];
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }
}
