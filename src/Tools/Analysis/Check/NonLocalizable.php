<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis\Check;

use ideatic\l10n\Domain;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\DomainCheck;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Severity;

/**
 * Detects strings marked as translatable that probably should not be translated: numbers, URLs, lone symbols...
 * Each of them takes up a catalog entry and wastes the translators' time.
 */
class NonLocalizable extends DomainCheck
{
    public function name(): string
    {
        return 'non-localizable';
    }

    public function group(): string
    {
        return 'source';
    }

    public function title(): string
    {
        return 'Probably not localizable';
    }

    protected function analyzeDomain(Domain $domain, Context $context): array
    {
        $issues = [];

        foreach ($domain->strings as $stringID => $strings) {
            $string = $strings[0];
            $text = mb_trim($string->translatableText());

            if ($text === '') { // Covered by empty-strings
                continue;
            }

            // This check exists to make someone stop and think about whether the string should be translated. A
            // context or a comment prove that already happened: single-letter abbreviations ('C@acronym' for
            // captain) are the usual case, and they are indeed translated ('K' in Russian, 'A' in Greek)
            if (mb_trim($string->context ?? '') !== '' || mb_trim($string->comments ?? '') !== '') {
                continue;
            }

            $reason = match (true) {
                !preg_match('/\p{L}/u', $text) => 'contains no letters',
                preg_match('#^(https?://|www\.)\S+$#ui', $text) === 1 => 'is a bare URL',
                preg_match('/^[^@\s]+@[^@\s]+\.\w+$/u', $text) === 1 => 'is a bare email address',
                mb_strlen($text) === 1 => 'is a single character',
                default => null,
            };

            if ($reason === null) {
                continue;
            }

            $issues[] = new Issue(
                check: $this->name(),
                severity: Severity::Info,
                message: '"' . Context::preview($text) . "\" {$reason}",
                domain: $domain->name,
                stringID: strval($stringID),
                locations: Context::locations($strings),
                priority: count($strings),
            );
        }

        return $issues;
    }
}
