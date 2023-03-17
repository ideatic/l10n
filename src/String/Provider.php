<?php

declare(strict_types=1);

namespace ideatic\l10n\String;

use ideatic\l10n\LString;

/**
 * Proveedor de cadenas traducibles
 */
abstract class Provider
{
    /**
     * Obtiene las cadenas traducibles encontradas por este proveedor
     * @return LString[]
     */
    public abstract function getStrings(): array;
}