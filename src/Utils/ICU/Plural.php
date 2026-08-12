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
 * Utilidades para trabajar con las categorías plurales CLDR de un idioma.
 *
 * PHP no expone directamente las reglas plurales de CLDR, por lo que se utiliza MessageFormatter como oráculo:
 * se le pregunta por la categoría de una serie de números y se recopilan las categorías realmente utilizadas.
 */
abstract class Plural
{
    /** Orden canónico de las categorías plurales definido por CLDR */
    public const array CATEGORIES = ['zero', 'one', 'two', 'few', 'many', 'other'];

    /**
     * Números adicionales utilizados para descubrir las categorías plurales de un idioma.
     *
     * Se incluyen valores grandes y decimales porque categorías como 'many' en francés, italiano o portugués
     * solo aparecen a partir del millón, y nunca se observarían probando únicamente enteros del 0 al 100.
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
     * Obtiene las categorías plurales CLDR aplicables al idioma indicado, en orden canónico
     *
     * @param bool $ordinal Obtener las categorías ordinales (selectordinal) en lugar de las cardinales (plural)
     *
     * @return list<string>
     */
    public static function getCategories(string $locale, bool $ordinal = false): array
    {
        return array_keys(self::getCategoryValues($locale, $ordinal));
    }

    /**
     * Obtiene, para cada categoría plural aplicable al idioma, los valores de muestra que la seleccionan.
     *
     * Permite distinguir las categorías que una aplicación puede llegar a mostrar de las que solo se alcanzan con
     * cantidades enormes o con decimales, y saber si un valor concreto está ya cubierto por una clave explícita =N.
     *
     * @return array<string, list<int|float>> Categorías en orden canónico
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
        foreach (self::CATEGORIES as $category) { // Orden canónico
            if (isset($found[$category])) {
                $values[$category] = $found[$category];
            }
        }

        return self::$_categoriesCache[$cacheKey] = $values;
    }

    /**
     * Obtiene la menor cantidad entera que selecciona la categoría indicada, o null si el idioma no la utiliza o
     * solo la selecciona con decimales.
     *
     * Se ignoran los decimales a propósito: en hebreo, por ejemplo, 'one' se selecciona con 0,5 pero también con 1,
     * y lo que interesa saber es si una aplicación que solo cuenta unidades puede llegar a mostrarla.
     */
    public static function getSmallestCount(string $locale, string $category, bool $ordinal = false): ?int
    {
        $integers = array_filter(self::getCategoryValues($locale, $ordinal)[$category] ?? [], is_int(...));

        return $integers ? min($integers) : null;
    }

    /**
     * Comprueba que ICU reconoce realmente el idioma indicado.
     *
     * MessageFormatter acepta cualquier código de idioma y recurre silenciosamente a la configuración raíz,
     * devolviendo categorías plurales similares a las del inglés para idiomas inexistentes.
     */
    public static function isKnownLocale(string $locale): bool
    {
        if (isset(self::$_knownLocalesCache[$locale])) {
            return self::$_knownLocalesCache[$locale];
        }

        if (mb_trim($locale) === '') { // Locale::getPrimaryLanguage() devolvería el idioma predeterminado del sistema
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
