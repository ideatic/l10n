<?php

declare(strict_types=1);

namespace ideatic\l10n\Translation\Provider;

use ideatic\l10n\Catalog\Translation;
use ideatic\l10n\LString;
use ideatic\l10n\Translation\Provider;

/**
 * Implementa un proveedor de traducciones que utiliza un catálogo (o varios) como fuente
 */
class Catalog implements Provider
{
    /**
     * @param \ideatic\l10n\Catalog\Catalog $_catalog
     */
    public function __construct(private readonly \ideatic\l10n\Catalog\Catalog $_catalog) {}


    public function getTranslation(LString $string, string $locale, bool $allowFallback = true): ?Translation
    {
        if ($this->_catalog->locale == $locale) {
            return $this->_catalog->getTranslation($string);
        } else {
            return null;
        }
    }
}
