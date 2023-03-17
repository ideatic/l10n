<?php

declare(strict_types=1);

namespace ideatic\l10n\String\Format;

use ideatic\l10n\LString;

/**
 * Clase base que deben implementar todos los proveedores de cadenas de localización
 */
abstract class Format
{
    public string $defaultDomain = 'app';

    /**
     * Obtiene las cadenas de traducción disponibles en el contenido indicado
     *
     * @return array<LString>
     */
    public abstract function getStrings(string $content, mixed $context = null): array;

    /**
     * Traduce las cadenas del dominio indicado que se encuentran en el contenido
     *
     * @param callable(LString $string): ?string $getTranslation Recibe LString como parámetro. Devuelve string o NULL
     * @param mixed|string                       $context
     */
    public abstract function translate(string $content, callable $getTranslation, mixed $context = null): string;
}