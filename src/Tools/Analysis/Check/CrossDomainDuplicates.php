<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis\Check;

use ideatic\l10n\Tools\Analysis\Check;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Severity;

/**
 * Detects identical strings extracted into several domains. Besides duplicating the translation work, each domain
 * keeps its own catalog, so the same sentence can end up translated differently in each of them.
 */
class CrossDomainDuplicates extends Check
{
    public function name(): string
    {
        return 'cross-domain-duplicates';
    }

    public function group(): string
    {
        return 'source';
    }

    public function title(): string
    {
        return 'Duplicated strings across domains';
    }

    public function needsTranslations(): bool
    {
        return true;
    }

    public function analyze(Context $context): array
    {
        $issues = [];

        foreach ($context->textIndex() as $text => $entries) {
            $domains = [];
            foreach ($entries as $entry) {
                // A copy living in an untranslated domain cannot diverge from anything, because it never gets
                // translated: the real duplication is the one between domains that do have a catalog
                if (!$context->isTranslated($entry['domain'])) {
                    continue;
                }

                $domains[$entry['domain']->name] ??= $entry;
            }

            if (count($domains) < 2) {
                continue;
            }

            // Check whether the same string is translated differently in each domain
            $divergences = [];
            foreach ($context->locales as $locale) {
                $translations = [];

                foreach ($domains as $domainName => $entry) {
                    $translation = $context->getTranslation($entry['strings'][0], $locale);

                    if ($translation !== null) {
                        $translations[$domainName] = $translation->translation;
                    }
                }

                if (count(array_unique($translations)) > 1) {
                    $rendered = [];
                    foreach ($translations as $domainName => $translated) {
                        $rendered[] = "{$domainName}: \"" . Context::preview($translated, 40) . '"';
                    }

                    $divergences[] = "{$locale} → " . implode(' | ', $rendered);
                }
            }

            $details = [];
            foreach ($domains as $domainName => $entry) {
                $locations = Context::locations($entry['strings'], 2);
                $details[] = "{$domainName}: {$entry['id']}" . ($locations ? "\n      " . implode(', ', $locations) : '');
            }

            $issues[] = new Issue(
                check: $this->name(),
                severity: $divergences ? Severity::Error : Severity::Warning,
                // PHP turns numeric-looking array keys into integers
                message: '"' . Context::preview(strval($text)) . '" is extracted into ' . count($domains) . ' domains'
                    . ($divergences ? ' with divergent translations' : ''),
                stringID: reset($domains)['id'],
                details: [...$details, ...$divergences],
                priority: count($divergences) * 100 + count($domains),
            );
        }

        return $issues;
    }
}
