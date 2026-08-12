<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis\Check;

use ideatic\l10n\Domain;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\DomainCheck;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Placeholders;
use ideatic\l10n\Tools\Analysis\Severity;

/**
 * Detects strings whose placeholder syntax departs from the one used by the rest of the domain.
 *
 * It also always reports the positional printf placeholders ('%s', '%d'), because they do not say what they hold
 * and cannot be reordered: in languages whose word order differs from the source, translation is impossible.
 */
class PlaceholderConvention extends DomainCheck
{
    /** Minimum usage ratio for a syntax to be considered the dominant one in the domain */
    private const float DOMINANT_RATIO = 0.8;

    private const array STYLE_LABEL = [
        'angular' => 'Angular interpolation ({{…}})',
        'template' => 'template literal (${…})',
        'percent' => '%name%',
        'positional' => 'positional printf (%1$s)',
        'printf' => 'printf (%s)',
        'brace' => '{name}',
        'icu' => 'ICU',
    ];

    public function name(): string
    {
        return 'placeholder-convention';
    }

    public function group(): string
    {
        return 'metadata';
    }

    public function title(): string
    {
        return 'Placeholder conventions';
    }

    protected function analyzeDomain(Domain $domain, Context $context): array
    {
        $stringStyles = [];
        $usage = [];

        foreach ($domain->strings as $stringID => $strings) {
            $styles = array_values(array_diff(Placeholders::styles($strings[0]->translatableText(), $strings[0]->isICU), ['icu']));

            if (!$styles) {
                continue;
            }

            $stringStyles[strval($stringID)] = $styles;

            foreach ($styles as $style) {
                $usage[$style] ??= 0;
                $usage[$style]++;
            }
        }

        if (!$usage) {
            return [];
        }

        arsort($usage);
        $dominant = array_key_first($usage);
        $total = array_sum($usage);
        $hasDominant = $usage[$dominant] / $total >= self::DOMINANT_RATIO;

        $issues = [];

        foreach ($stringStyles as $stringID => $styles) {
            $strings = $domain->strings[$stringID];
            $locations = Context::locations($strings);

            // PHP turns numeric-looking array keys into integers
            $stringID = strval($stringID);

            foreach ($styles as $style) {
                if ($style === 'positional' || $style === 'printf') {
                    $issues[] = new Issue(
                        check: $this->name(),
                        severity: Severity::Info,
                        message: 'Uses ' . self::STYLE_LABEL[$style] . ' placeholders, which translators cannot identify or reorder',
                        domain: $domain->name,
                        stringID: $stringID,
                        locations: $locations,
                        details: ['Use named placeholders so the translation can describe and reorder them'],
                        priority: 30,
                    );
                } elseif ($hasDominant && $style !== $dominant) {
                    $issues[] = new Issue(
                        check: $this->name(),
                        severity: Severity::Info,
                        message: 'Uses ' . self::STYLE_LABEL[$style] . ' placeholders, while the domain mostly uses ' . self::STYLE_LABEL[$dominant],
                        domain: $domain->name,
                        stringID: $stringID,
                        locations: $locations,
                        priority: 10,
                    );
                }
            }
        }

        return $issues;
    }
}
