<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis\Check;

use Exception;
use ideatic\l10n\Domain;
use ideatic\l10n\Tools\Analysis\Check;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\Icu;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Severity;
use ideatic\l10n\Utils\ICU\Plural;

/**
 * Checks that the ICU translations include every plural form the target language requires.
 *
 * Each language uses a different subset of the CLDR categories: Spanish needs 'one' and 'other', but Russian also
 * needs 'few' and 'many', and Arabic needs all six. When a category is missing ICU falls back to 'other' and the
 * text is grammatically wrong for that range of numbers.
 */
class IcuPluralForms extends Check
{
    public function name(): string
    {
        return 'icu-plural-forms';
    }

    public function group(): string
    {
        return 'translations';
    }

    public function title(): string
    {
        return 'ICU plural forms';
    }

    public function needsTranslations(): bool
    {
        return true;
    }

    public function analyze(Context $context): array
    {
        $issues = [];

        foreach ($context->locales as $locale) {
            if (!Plural::isKnownLocale($locale)) {
                $issues[] = new Issue(
                    check: $this->name(),
                    severity: Severity::Warning,
                    message: "ICU does not know the locale '{$locale}', so its plural forms cannot be verified",
                    locale: $locale,
                    priority: 900,
                );
                continue;
            }

            try {
                $cardinal = Plural::getCategories($locale);
                $ordinal = Plural::getCategories($locale, true);
            } catch (Exception $e) {
                $issues[] = new Issue(
                    check: $this->name(),
                    severity: Severity::Warning,
                    message: $e->getMessage(),
                    locale: $locale,
                    priority: 900,
                );
                continue;
            }

            foreach ($context->domains as $domain) {
                $issues = [...$issues, ...$this->_analyzeLocale($domain, $context, $locale, $cardinal, $ordinal)];
            }
        }

        return $issues;
    }

    /**
     * @param list<string> $cardinal
     * @param list<string> $ordinal
     *
     * @return list<Issue>
     */
    private function _analyzeLocale(Domain $domain, Context $context, string $locale, array $cardinal, array $ordinal): array
    {
        $issues = [];

        foreach ($domain->strings as $stringID => $strings) {
            $string = $strings[0];

            if (!$string->isICU) {
                continue;
            }

            $translation = $context->getTranslation($string, $locale);

            if ($translation === null || mb_trim($translation->translation) === '') {
                continue;
            }

            $pattern = Icu::tryParse($translation->translation);

            if ($pattern === null) { // translation-syntax already reports it
                continue;
            }

            foreach (Icu::plurals($pattern) as $placeholder) {
                $type = $placeholder->type;
                $required = $type === 'selectordinal' ? $ordinal : $cardinal;

                $categories = array_map(strval(...), array_keys(Icu::branches($placeholder)));
                $used = array_values(array_filter($categories, static fn(string $c): bool => !preg_match('/^=\d+$/', $c)));

                // Values covered by an explicit key: ICU resolves those before the category, so the category is no
                // longer needed when every one of its values is enumerated (e.g. 'zero' in Arabic, which only
                // selects n=0, is covered by "=0 {...}")
                $explicit = [];
                foreach ($categories as $category) {
                    if (preg_match('/^=(\d+)$/', $category, $match)) {
                        $explicit[] = intval($match[1]);
                    }
                }

                $missing = array_values(
                    array_filter(
                        array_diff($required, $used),
                        static fn(string $category): bool => !self::_isCoveredByExplicitKeys($category, $locale, $type === 'selectordinal', $explicit)
                    )
                );
                $unused = array_values(array_diff($used, $required));

                $name = $placeholder->name;
                $locations = Context::locations($strings, 2);

                foreach ($this->_groupBySeverity($missing, $locale, $type === 'selectordinal', $context) as $severity => $group) {
                    $severity = Severity::from(strval($severity));

                    $issues[] = new Issue(
                        check: $this->name(),
                        severity: $severity,
                        message: "Placeholder '{$name}' ({$type}) is missing the plural form(s) " . implode(', ', array_keys($group)) . " required by '{$locale}'",
                        domain: $domain->name,
                        stringID: strval($stringID),
                        locale: $locale,
                        locations: $locations,
                        details: [
                            "Required by {$locale}: " . implode(', ', $required),
                            'Present in the translation: ' . (implode(', ', $categories) ?: '(none)'),
                            ...($severity === Severity::Error
                                ? []
                                : ['Only reached by values this application is unlikely to show: ' . implode(', ', $group)]),
                        ],
                        priority: ($severity === Severity::Error ? 800 : 200) + count($group) * 10,
                    );
                }

                if ($unused) {
                    $issues[] = new Issue(
                        check: $this->name(),
                        severity: Severity::Warning,
                        message: "Placeholder '{$name}' ({$type}) defines the plural form(s) " . implode(', ', $unused) . " that '{$locale}' never uses",
                        domain: $domain->name,
                        stringID: strval($stringID),
                        locale: $locale,
                        locations: $locations,
                        details: ["Required by {$locale}: " . implode(', ', $required)],
                        priority: 300,
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * Checks whether every value selecting a category is already enumerated as an explicit key
     *
     * @param list<int> $explicit
     */
    private static function _isCoveredByExplicitKeys(string $category, string $locale, bool $ordinal, array $explicit): bool
    {
        if (!$explicit) {
            return false;
        }

        $values = Plural::getCategoryValues($locale, $ordinal)[$category] ?? [];

        return $values !== [] && array_all($values, static fn(int|float $value): bool => in_array($value, $explicit, true));
    }

    /**
     * Splits the missing categories by the severity each one deserves.
     *
     * A missing category breaks nothing: ICU falls back to 'other'. It is a real error when the application can
     * display an amount that selects it, but only a warning when reaching it takes a million or a fraction, as
     * 'many' does in French or in Czech.
     *
     * @param list<string> $missing
     *
     * @return array<string, array<string, string>> severity => category => smallest value selecting it
     */
    private function _groupBySeverity(array $missing, string $locale, bool $ordinal, Context $context): array
    {
        $grouped = [];

        foreach ($missing as $category) {
            $smallest = Plural::getSmallestCount($locale, $category, $ordinal);

            $severity = $smallest !== null && $smallest <= $context->options->pluralRange ? Severity::Error : Severity::Warning;

            $grouped[$severity->value][$category] = $smallest === null
                ? "{$category} is only selected by fractional amounts"
                : "{$category} is first selected by {$smallest}";
        }

        return $grouped;
    }
}
