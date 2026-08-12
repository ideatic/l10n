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
 * Detects inconsistent conventions among the ICU patterns of a single domain.
 *
 * The most important case is using a named placeholder instead of '#' inside a plural branch: besides being
 * inconsistent, it forces the translator to reproduce that placeholder in every plural form their language has,
 * and it is the usual cause of placeholder parity failures in Russian, Arabic, Hebrew or Croatian.
 */
class IcuConsistency extends DomainCheck
{
    /** Minimum usage ratio for a convention to be considered the dominant one in the domain */
    private const float DOMINANT_RATIO = 0.6;

    public function name(): string
    {
        return 'icu-consistency';
    }

    public function group(): string
    {
        return 'icu';
    }

    public function title(): string
    {
        return 'ICU conventions';
    }

    protected function analyzeDomain(Domain $domain, Context $context): array
    {
        $issues = [];

        /** @var array<string, list<string>> $variableNames variable name => ids using it */
        $variableNames = [];

        /** @var array<string, list<string>> $oneConventions convention => ids using it */
        $oneConventions = [];

        foreach ($domain->strings as $stringID => $strings) {
            $string = $strings[0];

            if (!$string->isICU) {
                continue;
            }

            $pattern = Icu::tryParse($string->translatableText());
            if ($pattern === null) {
                continue;
            }

            $stringID = strval($stringID);
            $locations = Context::locations($strings);

            foreach (Icu::plurals($pattern) as $placeholder) {
                $variable = $placeholder->name;
                $variableNames[$variable][] = $stringID;

                foreach (Icu::branches($placeholder) as $category => $branch) {
                    $category = strval($category);
                    $content = $branch->render(false);

                    // A named placeholder inside a plural branch, instead of '#'
                    if ($variable !== '' && preg_match('/%' . preg_quote($variable, '/') . '%|\{' . preg_quote($variable, '/') . '\}/u', $content)) {
                        $issues[] = new Issue(
                            check: $this->name(),
                            severity: Severity::Info,
                            message: "The '{$category}' branch uses the '{$variable}' placeholder instead of '#'",
                            domain: $domain->name,
                            stringID: $stringID,
                            locations: $locations,
                            details: ['Translators must then repeat it in every plural form their language has, which is a frequent source of placeholder mismatches'],
                            priority: 20,
                        );
                    }

                    if (preg_match('/^=\d+$/', $category)) {
                        $issues[] = new Issue(
                            check: $this->name(),
                            severity: Severity::Info,
                            message: "Explicit '{$category}' plural key, which most translation tools do not expose",
                            domain: $domain->name,
                            stringID: $stringID,
                            locations: $locations,
                            priority: 10,
                        );
                    }

                    if ($category === 'one') {
                        $convention = str_contains($content, '#') ? '#' : (preg_match('/\d/', $content) ? 'literal number' : 'no number');
                        $oneConventions[$convention][] = $stringID;
                    }
                }
            }
        }

        return [
            ...$issues,
            ...self::_reportMinority($domain, $variableNames, "plural variable name '%s'", 'Align the plural variable name across the domain'),
            ...self::_reportMinority($domain, $oneConventions, "'%s' in the 'one' branch", "Pick one convention for the 'one' branch across the domain"),
        ];
    }

    /**
     * Reports the strings using a minority convention within the domain
     *
     * @param array<string, list<string>> $usages
     *
     * @return list<Issue>
     */
    private static function _reportMinority(Domain $domain, array $usages, string $format, string $advice): array
    {
        if (count($usages) < 2) {
            return [];
        }

        // Stable order: by usage descending and, on a tie, alphabetically, so that the report does not depend on
        // the order in which the strings happened to be found
        uksort(
            $usages,
            static fn(string $a, string $b): int => [count($usages[$b]), $a] <=> [count($usages[$a]), $b]
        );

        $dominant = array_key_first($usages);
        $total = array_sum(array_map(count(...), $usages));

        // Without a clear majority there is no convention to enforce: reporting "the minority" would be arbitrary
        if (count($usages[$dominant]) / $total < self::DOMINANT_RATIO) {
            return [];
        }

        $issues = [];

        foreach ($usages as $convention => $stringIDs) {
            if ($convention === $dominant) {
                continue;
            }

            foreach (array_unique($stringIDs) as $stringID) {
                $issues[] = new Issue(
                    check: 'icu-consistency',
                    severity: Severity::Info,
                    message: 'Uses ' . sprintf($format, $convention) . ', while the domain mostly uses ' . sprintf($format, $dominant),
                    domain: $domain->name,
                    stringID: $stringID,
                    details: [$advice],
                    priority: 5,
                );
            }
        }

        return $issues;
    }
}
