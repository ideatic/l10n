<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis\Check;

use ideatic\l10n\Tools\Analysis\Check;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Severity;

/**
 * Detects locales declared as available whose catalog does not exist or is empty.
 *
 * These are the worst translation failures possible: the application offers the locale to the user, but all its
 * content is shown in the source language.
 */
class EmptyCatalog extends Check
{
    public function name(): string
    {
        return 'empty-catalog';
    }

    public function group(): string
    {
        return 'pipeline';
    }

    public function title(): string
    {
        return 'Declared locales without translations';
    }

    public function needsTranslations(): bool
    {
        return true;
    }

    public function analyze(Context $context): array
    {
        $issues = [];

        foreach ($context->domains as $domain) {
            // A domain outside the pipeline has no catalog in any locale by definition: unconfigured-domain
            // reports it once, instead of once per locale
            if (!$context->isTranslated($domain)) {
                continue;
            }

            $catalogs = [];

            foreach ($context->locales as $locale) {
                $catalogs[$locale] = $context->catalog($domain, $locale);
            }

            // If the domain has no catalog in any locale it is a configuration problem of the whole domain,
            // and unconfigured-domain reports it
            if (!array_filter($catalogs)) {
                continue;
            }

            foreach ($catalogs as $locale => $catalog) {
                $problem = match (true) {
                    $catalog === null => 'has no catalog file',
                    $catalog->entryCount() === 0 => 'has an empty catalog',
                    default => null,
                };

                if ($problem === null) {
                    continue;
                }

                $issues[] = new Issue(
                    check: $this->name(),
                    severity: Severity::Error,
                    message: "Locale '{$locale}' is declared as available but {$problem} for domain '{$domain->name}'",
                    domain: $domain->name,
                    locale: strval($locale),
                    details: ['Seed the catalog, or remove the locale from "targetLocales" so it is not offered to users'],
                    priority: count($domain->strings),
                );
            }
        }

        return $issues;
    }
}
