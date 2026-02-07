<?php

declare(strict_types=1);

namespace ideatic\l10n\Translation\Provider;

use ideatic\l10n\Catalog\Translation;
use ideatic\l10n\LString;
use ideatic\l10n\Translation\Provider;

/**
 * Implementa un proveedor de traducciones que utiliza otros proveedores como fuente, devolviendo la primera traducción encontrada
 */
class Fallback implements Provider
{
    /**
     * @var list<Provider> $_translators
     */
    private array $_translators = [];

    public function __construct(Provider|null $provider = null)
    {
        if ($provider !== null) {
            $this->addTranslator($provider);
        }
    }

    public function addTranslator(Provider $translator): void
    {
        $this->_translators[] = $translator;
    }

    public function prependTranslator(Provider $translator): void
    {
        array_unshift($this->_translators, $translator);
    }

    public function removeTranslator(Provider $translator): void
    {
        $index = array_search($translator, $this->_translators, true);
        if ($index !== false) {
            array_splice($this->_translators, $index, 1);
        }
    }

    public function getTranslation(LString $string, string $locale, bool $allowFallback = true): ?Translation
    {
        foreach ($this->_translators as $translator) {
            $translation = $translator->getTranslation($string, $locale, false);
            if ($translation !== null) {
                return $translation;
            }
        }

        return null;
    }
}
