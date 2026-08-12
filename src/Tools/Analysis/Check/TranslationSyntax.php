<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis\Check;

use ideatic\l10n\Domain;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\DomainCheck;
use ideatic\l10n\Tools\Analysis\Icu;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Severity;
use ideatic\l10n\Utils\ICU\Pattern;

/**
 * Validates the ICU syntax of the translations and checks that their structure still matches the source text.
 *
 * An invalid ICU pattern in a translation makes the message impossible to render in that locale, and a change of
 * structure (turning a 'plural' into a 'select', or altering the options of a 'select') breaks the message as
 * soon as the incoming value stops matching any branch.
 */
class TranslationSyntax extends DomainCheck
{
    public function name(): string
    {
        return 'translation-syntax';
    }

    public function group(): string
    {
        return 'translations';
    }

    public function title(): string
    {
        return 'Translation syntax';
    }

    public function needsTranslations(): bool
    {
        return true;
    }

    protected function analyzeDomain(Domain $domain, Context $context): array
    {
        $issues = [];

        foreach ($domain->strings as $stringID => $strings) {
            $string = $strings[0];
            $sourcePattern = $string->isICU ? Icu::tryParse($string->translatableText()) : null;

            foreach ($context->locales as $locale) {
                $translation = $context->getTranslation($string, $locale);

                if ($translation === null || mb_trim($translation->translation) === '') {
                    continue;
                }

                $translated = $translation->translation;
                $locations = Context::locations($strings, 2);

                // The catalog declares whether the entry is ICU: when that does not match reality the translation is
                // serialised wrongly and ends up lost or displayed unformatted
                if (!$string->isICU && Icu::looksLikeIcu($translated)) {
                    $issues[] = new Issue(
                        check: $this->name(),
                        severity: Severity::Warning,
                        message: 'Translation looks like an ICU pattern, but the source string is not marked as ICU',
                        domain: $domain->name,
                        stringID: strval($stringID),
                        locale: $locale,
                        locations: $locations,
                        details: ['Translation: "' . Context::preview($translated, 100) . '"'],
                        priority: 400,
                    );
                }

                if (!$string->isICU) {
                    continue;
                }

                $error = Icu::parseError($translated);
                if ($error !== null) {
                    $issues[] = new Issue(
                        check: $this->name(),
                        severity: Severity::Error,
                        message: "Invalid ICU pattern in translation: {$error}",
                        domain: $domain->name,
                        stringID: strval($stringID),
                        locale: $locale,
                        locations: $locations,
                        details: ['Translation: "' . Context::preview($translated, 100) . '"'],
                        priority: 900,
                    );
                    continue;
                }

                $pattern = Icu::tryParse($translated);
                if ($pattern === null) {
                    continue;
                }

                $formatError = Icu::formatError($pattern->render(false), $locale);
                if ($formatError !== null) {
                    $issues[] = new Issue(
                        check: $this->name(),
                        severity: Severity::Error,
                        message: "Translation rejected by the ICU formatter: {$formatError}",
                        domain: $domain->name,
                        stringID: strval($stringID),
                        locale: $locale,
                        locations: $locations,
                        priority: 900,
                    );
                }

                if ($sourcePattern !== null) {
                    foreach (self::_structureProblems($sourcePattern, $pattern) as $problem) {
                        $issues[] = new Issue(
                            check: $this->name(),
                            severity: Severity::Error,
                            message: $problem,
                            domain: $domain->name,
                            stringID: strval($stringID),
                            locale: $locale,
                            locations: $locations,
                            details: ['Translation: "' . Context::preview($translated, 100) . '"'],
                            priority: 700,
                        );
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * Compara la estructura de dos patrones ICU equivalentes
     *
     * @return list<string>
     */
    private static function _structureProblems(Pattern $source, Pattern $translated): array
    {
        $problems = [];

        $sourceTypes = self::_types($source);
        $translatedTypes = self::_types($translated);

        foreach ($sourceTypes as $name => $type) {
            if (!isset($translatedTypes[$name])) { // Lo informa placeholder-mismatch
                continue;
            }

            if ($translatedTypes[$name] !== $type) {
                $problems[] = "Placeholder '{$name}' is '{$type}' in the source but '{$translatedTypes[$name]}' in the translation";
            }
        }

        foreach (self::_selectOptions($source) as $name => $options) {
            $translatedOptions = self::_selectOptions($translated)[$name] ?? null;

            if ($translatedOptions === null) {
                continue;
            }

            $missing = array_diff($options, $translatedOptions);

            if ($missing) {
                $problems[] = "Select placeholder '{$name}' is missing the option(s) " . implode(', ', $missing) . ' present in the source';
            }
        }

        return $problems;
    }

    /**
     * @return array<string, string>
     */
    private static function _types(Pattern $pattern): array
    {
        $types = [];

        foreach (Icu::placeholders($pattern) as $placeholder) {
            $types[$placeholder->name] = $placeholder->type;
        }

        return $types;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function _selectOptions(Pattern $pattern): array
    {
        $options = [];

        foreach (Icu::placeholders($pattern) as $placeholder) {
            if ($placeholder->type === 'select') {
                $options[$placeholder->name] = array_map(strval(...), array_keys(Icu::branches($placeholder)));
            }
        }

        return $options;
    }
}
