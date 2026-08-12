<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis\Check;

use ideatic\l10n\Domain;
use ideatic\l10n\LString;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\DomainCheck;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Severity;

/**
 * Detects strings inserting a quantity into the text without using ICU.
 *
 * These strings are always written in the plural, so they read wrong when the amount is 1, and they also stop the
 * languages with more than two plural forms (Russian, Arabic, Croatian, Hebrew...) from translating them properly.
 */
class PluralCandidate extends DomainCheck
{
    public function name(): string
    {
        return 'plural-candidate';
    }

    public function group(): string
    {
        return 'icu';
    }

    public function title(): string
    {
        return 'Strings that should use ICU plurals';
    }

    protected function analyzeDomain(Domain $domain, Context $context): array
    {
        $countPlaceholders = array_map(mb_strtolower(...), $context->options->countPlaceholders);
        $issues = [];

        foreach ($domain->strings as $stringID => $strings) {
            $string = $strings[0];
            $text = $string->translatableText();

            if ($string->isICU || mb_trim($text) === '') {
                continue;
            }

            $found = [];
            foreach (self::_placeholderNames($string) as $name) {
                if (in_array(mb_strtolower($name), $countPlaceholders, true)) {
                    $found[] = $name;
                }
            }

            // A plural is only needed when the quantity is followed by the word it counts. A standalone placeholder
            // ("Pagar %amount%", "%user% ofrece %amount%") inserts an already formatted value and makes nothing
            // agree, so turning it into ICU would not fix any language
            $nouns = $found ? self::_countedNouns($string, $found) : [];

            if ($nouns) {
                $issues[] = new Issue(
                    check: $this->name(),
                    severity: Severity::Warning,
                    message: 'Uses the count placeholder ' . implode(', ', array_map(static fn(string $n): string => "'{$n}'", $found))
                        . ' without ICU: "' . Context::preview($text) . '"',
                    domain: $domain->name,
                    stringID: strval($stringID),
                    locations: Context::locations($strings),
                    details: ['Words that cannot agree with the number: ' . implode(', ', $nouns)],
                    priority: 100 + count($found) * 10 + count($strings),
                );
                continue;
            }

            // Numbers written directly into the text: either they are a plural in disguise, or they are a
            // configuration value that should arrive as a placeholder
            if (preg_match('/(?<![\w.,])([2-9]|\d{2,})\s+(\p{L}{3,})/u', $text, $match)) {
                $issues[] = new Issue(
                    check: $this->name(),
                    severity: Severity::Info,
                    message: "Hardcoded number '{$match[1]} {$match[2]}' in \"" . Context::preview($text) . '"',
                    domain: $domain->name,
                    stringID: strval($stringID),
                    locations: Context::locations($strings),
                    details: ['Use an ICU plural if the number varies, or a placeholder if it comes from configuration'],
                    priority: count($strings),
                );
            }
        }

        return $issues;
    }

    /**
     * Gets the names of the placeholders used by a string
     *
     * @return list<string>
     */
    private static function _placeholderNames(LString $string): array
    {
        $names = array_map(strval(...), array_keys($string->placeholders ?? []));

        if (preg_match_all('/%([a-zA-Z_][\w.]*)%|\{([a-zA-Z_][\w.]*)\}/u', $string->translatableText(), $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $names[] = $match[2] ?? '' ?: $match[1];
            }
        }

        return array_values(array_unique(array_filter($names)));
    }

    /**
     * Gets the words placed right after the given placeholders, which are the ones that would have to agree with
     * the quantity. Only those placeholders are looked at: the word following any other one bears no relation to
     * the number (in "%user% ofrece %amount%", "ofrece" goes with %user%, not with the quantity).
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function _countedNouns(LString $string, array $names): array
    {
        $declared = $string->placeholders ?? [];
        $words = [];

        // In Angular the id is the normalised version of the template, and it is where the placeholder and the
        // noun end up next to each other: "{{ players.length | i18n:'%count%' }} jugadores" -> "%count% jugadores"
        foreach ([$string->translatableText(), $string->id ?? ''] as $text) {
            if ($text === '') {
                continue;
            }

            foreach ($names as $name) {
                $quoted = preg_quote($name, '/');
                $forms = ['%' . $quoted . '%', '\{' . $quoted . '\}'];

                // A declared placeholder may appear in the text as its bare name ("count players")
                if (array_key_exists($name, $declared)) {
                    $forms[] = '(?<![A-Za-z0-9_])' . $quoted . '(?![A-Za-z0-9_])';
                }

                if (preg_match_all('/(?:' . implode('|', $forms) . ')\s+(\p{L}{3,})/u', $text, $matches)) {
                    $words = [...$words, ...$matches[1]];
                }
            }
        }

        return array_values(array_unique($words));
    }
}
