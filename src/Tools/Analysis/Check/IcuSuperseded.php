<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis\Check;

use ideatic\l10n\Domain;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\DomainCheck;
use ideatic\l10n\Tools\Analysis\Icu;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Severity;

/**
 * Detects non-ICU strings reproducing one of the branches of an ICU pattern already present in the same domain.
 *
 * This is the usual residue of a half-finished ICU migration: the old string is still in the code and still being
 * translated, but a correct version already exists and should be reused instead.
 */
class IcuSuperseded extends DomainCheck
{
    public function name(): string
    {
        return 'icu-superseded';
    }

    public function group(): string
    {
        return 'icu';
    }

    public function title(): string
    {
        return 'Strings superseded by an ICU pattern';
    }

    protected function analyzeDomain(Domain $domain, Context $context): array
    {
        // Index the branches of every ICU pattern in the domain
        $branches = [];

        foreach ($domain->strings as $stringID => $strings) {
            $string = $strings[0];

            if (!$string->isICU) {
                continue;
            }

            $pattern = Icu::tryParse($string->translatableText());
            if ($pattern === null) {
                continue;
            }

            foreach (Icu::plurals($pattern) as $placeholder) {
                foreach (Icu::branches($placeholder) as $category => $branch) {
                    $signature = self::_signature($branch->render(false));

                    if ($signature !== null) {
                        $branches[$signature] ??= ['id' => strval($stringID), 'category' => strval($category)];
                    }
                }
            }
        }

        if (!$branches) {
            return [];
        }

        $issues = [];

        foreach ($domain->strings as $stringID => $strings) {
            $string = $strings[0];

            if ($string->isICU) {
                continue;
            }

            $text = $string->translatableText();

            // A plural branch always displays a quantity, so it can only supersede a string that displays one too.
            // Without this condition any standalone label ("Puntos") matches the 'other' branch of some plural in
            // the domain ("# puntos"), and merging them would be exactly the opposite of what should be done
            if (!self::_showsQuantity($text)) {
                continue;
            }

            $signature = self::_signature($text);

            if ($signature === null || !isset($branches[$signature])) {
                continue;
            }

            $match = $branches[$signature];

            $issues[] = new Issue(
                check: $this->name(),
                severity: Severity::Warning,
                message: '"' . Context::preview($text) . "\" duplicates the '{$match['category']}' branch of an existing ICU string",
                domain: $domain->name,
                stringID: strval($stringID),
                locations: Context::locations($strings),
                details: ['Reuse: ' . Context::preview($match['id'], 120)],
                priority: 50 + count($strings),
            );
        }

        return $issues;
    }

    /**
     * Tells whether a text displays a quantity, either written directly or through a placeholder.
     *
     * It is checked against the source text, before computing the signature, because the signature erases precisely
     * those numbers and placeholders so that the strings can be compared with each other.
     */
    private static function _showsQuantity(string $text): bool
    {
        return preg_match('/\d|#/u', strip_tags($text)) === 1
            || preg_match('/%[a-zA-Z_][\w.]*%|\{[a-zA-Z_][\w.]*\}|\{\{.+?\}\}|\$\{.+?\}/su', $text) === 1;
    }

    /**
     * Computes a comparable signature of a text, ignoring placeholders, numbers and HTML tags.
     * Returns null when the text does not hold enough content for the comparison to be reliable.
     */
    private static function _signature(string $text): ?string
    {
        $text = preg_replace(
            [
                '/\{\{.+?\}\}/su',
                '/\$\{.+?\}/su',
                '/%[a-zA-Z_][\w.]*%/u',
                '/\{[a-zA-Z_][\w.]*\}/u',
                '/%\d*\$?[+-]?[0-9.]*[bcdeEfFgGosuxX]/',
                '/[#\d]+/u',
            ],
            "\x00",
            $text
        ) ?? $text;

        $text = mb_strtolower(strip_tags($text));
        $text = preg_replace('/\s+/u', ' ', str_replace("\x00", ' ', $text)) ?? $text;
        $text = mb_rtrim(mb_trim($text), " .,:;!?…¡¿");

        return preg_match_all('/\p{L}/u', $text) >= 3 ? $text : null;
    }
}
