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
     * @param \ideatic\l10n\Catalog\Catalog|list<\ideatic\l10n\Catalog\Catalog> $_catalog
     */
    public function __construct(private \ideatic\l10n\Catalog\Catalog|array $_catalog) {}

    public function addCatalog(\ideatic\l10n\Catalog\Catalog $catalog): void
    {
        if (is_array($this->_catalog)) {
            $this->_catalog[] = $catalog;
        } else {
            $this->_catalog = [$this->_catalog, $catalog];
        }
    }

    public function prependCatalog(\ideatic\l10n\Catalog\Catalog $catalog): void
    {
        if (is_array($this->_catalog)) {
            array_unshift($this->_catalog, $catalog);
        } else {
            $this->_catalog = [$catalog, $this->_catalog];
        }
    }

    public function getTranslation(LString $string, string $locale, bool $allowFallback = true): ?Translation
    {
        if (is_array($this->_catalog)) {
            /** @var \ideatic\l10n\Catalog\Catalog $catalog */
            foreach ($this->_catalog as $catalog) {
                if ($catalog->locale == $locale) {
                    $translation = $catalog->getTranslation($string);
                    if ($translation !== null) {
                        return $translation;
                    }
                }
            }

            return null;
        } elseif ($this->_catalog->locale == $locale) {
            return $this->_catalog->getTranslation($string);
        } else {
            return null;
        }
    }
}
