<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis\Check;

use ideatic\l10n\Domain;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\DomainCheck;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Severity;

/**
 * Detects strings marked as translatable that hold no text
 */
class EmptyStrings extends DomainCheck
{
    public function name(): string
    {
        return 'empty-strings';
    }

    public function group(): string
    {
        return 'source';
    }

    public function title(): string
    {
        return 'Empty strings';
    }

    protected function analyzeDomain(Domain $domain, Context $context): array
    {
        $issues = [];

        foreach ($domain->strings as $stringID => $strings) {
            $text = $strings[0]->translatableText();

            if (mb_trim(str_replace("\xc2\xa0", ' ', $text)) !== '') {
                continue;
            }

            $issues[] = new Issue(
                check: $this->name(),
                severity: Severity::Error,
                message: $text === '' ? 'Empty localizable string' : 'Localizable string contains only whitespace',
                domain: $domain->name,
                stringID: strval($stringID),
                locations: Context::locations($strings),
                priority: count($strings),
            );
        }

        return $issues;
    }
}
