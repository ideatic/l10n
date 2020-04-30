<?php

namespace ideatic\l10n\String\Format;

use ideatic\l10n\LString;

/**
 * Clase base que deben implementar todos los proveedores de cadenas de localización
 */
abstract class Format
{
    public $defaultDomain = 'app';

    /**
     * Obtiene las cadenas de traducción disponibles en el contenido indicado
     *
     * @return LString[]
     */
    public abstract function getStrings(string $content, $context = null): array;

    /**
     * Traduce las cadenas del dominio indicado que se encuentran en el contenido
     *
     * @param string   $content
     * @param callable $getTranslation Recibe LString como parámetro. Devuelve string o NULL
     * @param null     $context
     *
     * @return string
     */
    public abstract function translate(string $content, callable $getTranslation, $context = null): string;
}