<?php

namespace ideatic\l10n\Utils;

abstract class Str
{

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