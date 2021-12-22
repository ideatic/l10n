<?php

namespace ideatic\l10n\Utils;

abstract class Str
{
    /**
     * Comprueba si una cadena comienza con la cadena test indicada
     */
    public static function startsWith(string $string, string $test, bool $caseInsensitive = false): bool
    {
        if ($test === '') {
            return true;
        }
        if (empty($string) && !empty($test)) {
            return false;
        }

        if ($caseInsensitive) {
            return stripos($string, $test) === 0;
        }
        return $string[0] == $test[0] && strpos($string, $test) === 0;
    }

    /**
     * Comprueba si una cadena termina con la cadena test indicada
     */
    public static function endsWith(string $string, string $test, bool $caseInsensitive = false): bool
    {
        if ($test === '') {
            return true;
        }

        $expectedPos = strlen($string) - strlen($test);

        if ($caseInsensitive) {
            return strrpos($string, $test) === $expectedPos;
        } else {
            return strrpos($string, $test) === $expectedPos;
        }
    }

    /**
     * Elimina los espacios en blanco al comienzo y final de una cadena.
     * Esta función soporta todos los tipos de espacios unicode como "No-break space", etc.
     */
    public static function trim(string $string, bool $left = true, bool $right = true): string
    {
        $options = [];
        if ($left) {
            $options[] = '^[\pZ\pC]+';
        }
        if ($right) {
            $options[] = '[\pZ\pC]+$';
        }

        return preg_replace('/' . implode('|', $options) . '/u', '', $string);
    }
}