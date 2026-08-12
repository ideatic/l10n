<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis\Check;

use ideatic\l10n\Domain;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\DomainCheck;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Severity;

/**
 * Detects catalog entries that no longer correspond to any string in the source code.
 *
 * They usually appear when a source text is rewritten (which changes its key) and make the translation files
 * grow indefinitely with content that is never shown.
 */
class ObsoleteTranslations extends DomainCheck
{
    /** Number of sample entries included in the detail */
    private const int SAMPLE_SIZE = 10;

    public function name(): string
    {
        return 'obsolete-translations';
    }

    public function group(): string
    {
        return 'translations';
    }

    public function title(): string
    {
        return 'Obsolete catalog entries';
    }

    public function needsTranslations(): bool
    {
        return true;
    }

    protected function analyzeDomain(Domain $domain, Context $context): array
    {
        $issues = [];

        foreach ($context->locales as $locale) {
            $catalog = $context->catalog($domain, $locale);

            if ($catalog === null || $catalog->entryCount() === 0) {
                continue;
            }

            $obsolete = array_keys(array_diff_key($catalog->getEntries(), $domain->strings));

            if (!$obsolete) {
                continue;
            }

            $sample = array_map(static fn(string $id): string => '"' . Context::preview($id, 60) . '"', array_slice($obsolete, 0, self::SAMPLE_SIZE));

            if (count($obsolete) > self::SAMPLE_SIZE) {
                $sample[] = '(+' . (count($obsolete) - self::SAMPLE_SIZE) . ' more)';
            }

            $issues[] = new Issue(
                check: $this->name(),
                severity: Severity::Warning,
                message: count($obsolete) . ' of ' . $catalog->entryCount() . ' catalog entries no longer exist in the source code',
                domain: $domain->name,
                locale: $locale,
                details: $sample,
                priority: count($obsolete),
            );
        }

        return $issues;
    }
}
