<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis\Check;

use ideatic\l10n\Domain;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\DomainCheck;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Severity;

/**
 * Reports the number of untranslated strings in each target locale
 */
class MissingTranslations extends DomainCheck
{
    /** Number of sample strings included in the detail */
    private const int SAMPLE_SIZE = 10;

    public function name(): string
    {
        return 'missing-translations';
    }

    public function group(): string
    {
        return 'translations';
    }

    public function title(): string
    {
        return 'Missing translations';
    }

    public function needsTranslations(): bool
    {
        return true;
    }

    protected function analyzeDomain(Domain $domain, Context $context): array
    {
        $issues = [];
        $total = count($domain->strings);

        if ($total === 0) {
            return [];
        }

        foreach ($context->locales as $locale) {
            $missing = [];

            foreach ($domain->strings as $stringID => $strings) {
                $translation = $context->getTranslation($strings[0], $locale);

                if ($translation === null || mb_trim($translation->translation) === '') {
                    $missing[] = strval($stringID);
                }
            }

            if (!$missing) {
                continue;
            }

            $percent = number_format(count($missing) / $total * 100, 1);
            $sample = array_map(static fn(string $id): string => '"' . Context::preview($id, 60) . '"', array_slice($missing, 0, self::SAMPLE_SIZE));

            if (count($missing) > self::SAMPLE_SIZE) {
                $sample[] = '(+' . (count($missing) - self::SAMPLE_SIZE) . ' more)';
            }

            $issues[] = new Issue(
                check: $this->name(),
                severity: Severity::Info,
                message: count($missing) . " of {$total} strings ({$percent}%) are not translated",
                domain: $domain->name,
                locale: $locale,
                details: $sample,
                priority: count($missing),
            );
        }

        return $issues;
    }
}
