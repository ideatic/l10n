<?php

declare(strict_types=1);

namespace ideatic\l10n\Utils\ICU;

use Exception;
use IntlException;
use Locale as IntlLocale;
use MessageFormatter;
use ResourceBundle;

use const U_ZERO_ERROR;

/**
 * Utilities to work with the CLDR plural categories of a language.
 *
 * PHP does not expose the CLDR plural rules directly, so MessageFormatter is used as an oracle: it is asked for
 * the category of a series of numbers and the categories actually used are collected.
 */
abstract class Plural
{
    /** Canonical order of the plural categories defined by CLDR */
    public const array CATEGORIES = ['zero', 'one', 'two', 'few', 'many', 'other'];

    /**
     * Extra numbers used to discover the plural categories of a language.
     *
     * Large and fractional values are included because categories such as 'many' in French, Italian or Portuguese
     * only show up from a million onwards, and would never be observed by probing integers from 0 to 100 alone.
     *
     * @var list<int|float>
     */
    private const array EXTRA_PROBES = [
        200,
        1_000,
        10_000,
        100_000,
        1_000_000,
        2_000_000,
        0.5,
        1.0,
        1.5,
        2.0,
        2.5,
        3.5,
        10.5,
        100.5,
        1_000_000.0,
        1_500_000.0,
    ];

    /** @var array<string, array<string, list<int|float>>> */
    private static array $_categoriesCache = [];

    /** @var array<string, bool> */
    private static array $_knownLocalesCache = [];

    /**
     * Gets the CLDR plural categories that apply to the given locale, in canonical order
     *
     * @param bool $ordinal Get the ordinal categories (selectordinal) instead of the cardinal ones (plural)
     *
     * @return list<string>
     */
    public static function getCategories(string $locale, bool $ordinal = false): array
    {
        return array_keys(self::getCategoryValues($locale, $ordinal));
    }

    /**
     * Gets, for each plural category applying to the locale, the sample values that select it.
     *
     * It tells apart the categories an application can actually display from those only reachable with huge amounts
     * or fractions, and whether a given value is already covered by an explicit =N key.
     *
     * @return array<string, list<int|float>> Categories in canonical order
     */
    public static function getCategoryValues(string $locale, bool $ordinal = false): array
    {
        $cacheKey = $ordinal ? "{$locale}|ordinal" : $locale;

        if (isset(self::$_categoriesCache[$cacheKey])) {
            return self::$_categoriesCache[$cacheKey];
        }

        $type = $ordinal ? 'selectordinal' : 'plural';
        $branches = implode(' ', array_map(static fn(string $category): string => "{$category} {{$category}}", self::CATEGORIES));

        try {
            $formatter = new MessageFormatter($locale, "{n, {$type}, {$branches}}");
        } catch (IntlException $e) {
            throw new Exception("Unable to detect plural categories for locale '{$locale}': {$e->getMessage()}", previous: $e);
        }

        if ($formatter->getErrorCode() != U_ZERO_ERROR) {
            throw new Exception("Unable to detect plural categories for locale '{$locale}': {$formatter->getErrorMessage()}");
        }

        $found = [];
        foreach ([...range(0, 120), ...self::EXTRA_PROBES] as $number) {
            $category = $formatter->format(['n' => $number]);

            if (is_string($category)) {
                $found[$category][] = $number;
            }
        }

        $values = [];
        foreach (self::CATEGORIES as $category) { // Canonical order
            if (isset($found[$category])) {
                $values[$category] = $found[$category];
            }
        }

        return self::$_categoriesCache[$cacheKey] = $values;
    }

    /**
     * Gets the smallest integer amount selecting the given category, or null when the locale does not use it or
     * only selects it with fractions.
     *
     * Fractions are ignored on purpose: in Hebrew, for instance, 'one' is selected by 0.5 but also by 1, and what
     * matters is whether an application that only counts whole units can ever display it.
     */
    public static function getSmallestCount(string $locale, string $category, bool $ordinal = false): ?int
    {
        $integers = array_filter(self::getCategoryValues($locale, $ordinal)[$category] ?? [], is_int(...));

        return $integers ? min($integers) : null;
    }

    /**
     * Checks that ICU really recognises the given locale.
     *
     * MessageFormatter accepts any language code and silently falls back to the root configuration, returning
     * English-like plural categories for locales that do not exist.
     */
    public static function isKnownLocale(string $locale): bool
    {
        if (isset(self::$_knownLocalesCache[$locale])) {
            return self::$_knownLocalesCache[$locale];
        }

        if (mb_trim($locale) === '') { // Locale::getPrimaryLanguage() would return the default locale of the system
            return self::$_knownLocalesCache[$locale] = false;
        }

        $language = IntlLocale::getPrimaryLanguage(str_replace('-', '_', $locale));

        $known = false;
        if ($language) {
            foreach (ResourceBundle::getLocales('') as $available) {
                if (IntlLocale::getPrimaryLanguage($available) === $language) {
                    $known = true;
                    break;
                }
            }
        }

        return self::$_knownLocalesCache[$locale] = $known;
    }
}
