<?php

declare(strict_types=1);

namespace ideatic\l10n\Translation\Provider;

use ideatic\l10n\LString;
use ideatic\l10n\Translation\Provider;

/**
 * Implementa un proveedor de traducciones que utiliza un catálogo como fuente
 */
class Catalog implements Provider
{
    /**
     * @param \ideatic\l10n\Catalog\Catalog|list<\ideatic\l10n\Catalog\Catalog> $_catalog
     */
    public function __construct(private readonly \ideatic\l10n\Catalog\Catalog|array $_catalog) {}

    public function getTranslation(LString $string, string $locale, bool $allowFallback = true): ?string
    {
        if (is_array($this->_catalog)) {
            /** @var \ideatic\l10n\Catalog\Catalog $catalog */
            foreach ($this->_catalog as $catalog) {
                $translation = $catalog->getTranslation($string);
                if ($translation !== null) {
                    return $translation;
                }
            }

            return null;
        } else {
            return $this->_catalog->getTranslation($string);
        }
    }
}
