<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis\Check;

use ideatic\l10n\Domain;
use ideatic\l10n\LString;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\DomainCheck;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Placeholders;
use ideatic\l10n\Tools\Analysis\Severity;
use Locale;

/**
 * Detects translations identical to the source text.
 *
 * Sometimes that is correct (proper nouns, technical terms), but usually they are strings that were assumed
 * translated without being so, and therefore show up as pending in no report at all.
 */
class IdenticalTranslation extends DomainCheck
{
    /** Minimum length to report: very short strings often match legitimately */
    private const int MIN_LENGTH = 4;

    public function name(): string
    {
        return 'identical-translation';
    }

    public function group(): string
    {
        return 'translations';
    }

    public function title(): string
    {
        return 'Translations identical to the source';
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
            $text = mb_trim($string->translatableText());

            // Placeholders are not translated, so they do not count as content: without discounting them, strings
            // like "%groom% & %bride%" or "%unit%M" are reported in every locale though there is nothing to translate
            $translatable = mb_trim(self::_withoutPlaceholders($text, $string));

            if (mb_strlen($translatable) < self::MIN_LENGTH || !preg_match('/\p{L}/u', $translatable)) {
                continue;
            }

            foreach ($context->locales as $locale) {
                if (in_array($locale, $context->options->identicalLocales, true)) {
                    continue;
                }

                $translation = $context->getTranslation($string, $locale);

                if ($translation === null || mb_trim($translation->translation) !== $text) {
                    continue;
                }

                // A language close to the source repeating a word is usually correct ("Total" in Galician or in
                // Catalan), but one that does not even share the alphabet repeating it almost always means the
                // string was assumed translated without being so
                $foreignScript = self::_usesAnotherScript($context->config->sourceLocale ?? '', $locale);

                $issues[] = new Issue(
                    check: $this->name(),
                    severity: $foreignScript ? Severity::Warning : Severity::Info,
                    message: 'Translation is identical to the source text' . ($foreignScript ? ', in a language written in another script' : '')
                        . ': "' . Context::preview($text) . '"',
                    domain: $domain->name,
                    stringID: strval($stringID),
                    locale: $locale,
                    locations: Context::locations($strings, 2),
                    priority: ($foreignScript ? 1000 : 0) + mb_strlen($text),
                );
            }
        }

        return $issues;
    }

    /**
     * Tells whether two languages are written in different alphabets, using the CLDR likely subtags
     * ('ru' -> 'ru_Cyrl_RU'). It is a very cheap measure of the distance between the two languages.
     */
    private static function _usesAnotherScript(string $source, string $target): bool
    {
        $sourceScript = self::_script($source);
        $targetScript = self::_script($target);

        return $sourceScript !== '' && $targetScript !== '' && $sourceScript !== $targetScript;
    }

    private static function _script(string $locale): string
    {
        if (mb_trim($locale) === '') {
            return '';
        }

        return strval(Locale::getScript(Locale::addLikelySubtags(str_replace('_', '-', $locale)) ?: $locale) ?? '');
    }

    /**
     * Strips the placeholders from a text, leaving only what a translator would actually have to write
     */
    private static function _withoutPlaceholders(string $text, LString $string): string
    {
        $placeholders = array_keys(Placeholders::extract($text, $string->isICU, $string->placeholders ?? []));

        // ICU placeholders are represented as {name}, which is also how they appear in the text
        return str_replace([...$placeholders, '#'], ' ', $text);
    }
}
