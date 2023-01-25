<?php

namespace ideatic\l10n\Utils;

abstract class Locale
{

    public static function getName(string $locale): string
    {
        if (class_exists(\Locale::class, false)) {
            return \Locale::getDisplayLanguage($locale, 'en');
        } else {
            return $locale;
        }
    }

    /**
     * Obtiene el idioma que se puede utilizar de reemplazo por otro
     */
    public static function getFallback(string $locale): array
    {
        $locale = str_replace('_', '-', $locale);

        if (in_array($locale, ['eu', 'eu-ES', 'ca', 'ca-ES', 'gl', 'gl-ES'])) {
            return ['es'];
        } elseif (in_array(
            $locale,
            [
                'es-AR',
                'es-BO',
                'es-BR',
                'es-BZ',
                'es-CL',
                'es-CO',
                'es-CR',
                'es-CU',
                'es-DO',
                'es-EC',
                'es-GT',
                'es-HN',
                'es-MX',
                'es-NI',
                'es-PA',
                'es-PE',
                'es-PR',
                'es-PY',
                'es-SV',
                'es-US',
                'es-UY',
                'es-VE'
            ]
        )) {
            return ['es-419', 'es'];
        }

        return [];
    }

    /**
     * Analiza un código RFC de una cultura, y devuelve un array con todas sus variantes válidas,
     * ordenado desde la más específica hasta la más neutra.
     * P.ej.:
     *  locale_variants('es_es') devolverá: ['es-ES', 'es')]
     *
     * locale_variants('az-Cyrl-AZ') devolverá: ['az_Cyrl_AZ', 'az_AZ', 'az']
     *
     * @return string[]
     */
    public static function getVariants(string $locale): array
    {
        /** @var string|null $region */
        $region = null;
        if (empty($locale)) {
            return ['default'];
        } else {
            if (str_contains($locale, '_')) { // Aceptar referencias con _ y - como separador
                $locale = str_replace('_', '-', $locale);
            }

            if (!str_contains($locale, '-')) { // La referencia cultura solo incluye el idioma
                return [strtolower($locale)];
            }

            $parts = explode('-', $locale);
            $language = strtolower($parts[0]);
            if (count($parts) > 2) {
                $script = ucfirst($parts[1]);
                $region = strtoupper($parts[2]);
            } else {
                $region = strtoupper($parts[1]);
            }
        }

        $codes = [];
        if (isset($script)) {
            $codes[] = "{$language}-{$script}-{$region}"; // Referencia completa (idioma, región y sistema de escritura)
        }
        $codes[] = "{$language}-{$region}"; // Referencia completa (idioma y región)
        $codes[] = $language; // Referencia neutra (sólo idioma)

        return $codes;
    }
}