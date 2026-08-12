<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis\Check;

use ideatic\l10n\Domain;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\DomainCheck;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Severity;

/**
 * Detects typographic problems in the source strings.
 *
 * Stray whitespace is part of the translation key, so two strings differing only in it get translated twice, and
 * translators rarely reproduce it exactly.
 */
class SourceFormatting extends DomainCheck
{
    public function name(): string
    {
        return 'source-formatting';
    }

    public function group(): string
    {
        return 'source';
    }

    public function title(): string
    {
        return 'Source formatting';
    }

    protected function analyzeDomain(Domain $domain, Context $context): array
    {
        $issues = [];

        foreach ($domain->strings as $stringID => $strings) {
            $text = $strings[0]->translatableText();

            if ($text === '') {
                continue;
            }

            $problems = [];

            if (!mb_check_encoding($text, 'UTF-8')) {
                $problems[] = 'invalid UTF-8 sequence';
            }

            if ($text !== mb_trim($text)) {
                $problems[] = 'leading or trailing whitespace';
            }

            if (preg_match('/\S  +\S/u', $text)) {
                $problems[] = 'consecutive spaces';
            }

            if (str_contains($text, "\xc2\xa0")) {
                $problems[] = 'non-breaking space (U+00A0)';
            }

            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text)) {
                $problems[] = 'control characters';
            }

            // Straight quotes around a placeholder: translations usually turn them into typographic quotes or drop
            // them, producing differences that are hard to spot
            if (preg_match('/"[^"]*(%\w+%|\{\w+\})[^"]*"/u', $text)) {
                $problems[] = 'straight quotes wrapping a placeholder';
            }

            if (!$problems) {
                continue;
            }

            $issues[] = new Issue(
                check: $this->name(),
                severity: Severity::Warning,
                message: implode(', ', $problems) . ' in "' . Context::preview($text) . '"',
                domain: $domain->name,
                stringID: strval($stringID),
                locations: Context::locations($strings),
                priority: count($problems) * 10 + count($strings),
            );
        }

        return $issues;
    }
}
