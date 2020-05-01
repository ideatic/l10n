<?php

namespace ideatic\l10n\Translation\Provider;

use ideatic\l10n\LString;
use ideatic\l10n\Translation\Provider;

/**
 * Implementa un proveedor de traducciones que utiliza un catálogo como fuente
 */
class Catalog implements Provider
{
    private $_catalog;

    public function __construct(\ideatic\l10n\Catalog\Catalog $catalog)
    {
        $this->_catalog = $catalog;
    }

    public function getTranslation(LString $string, string $locale, bool $allowFallback = true): ?string
    {
        return $this->_catalog->getTranslation($string);
    }
}