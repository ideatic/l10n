<?php

namespace ideatic\l10n\String;

/**
 * Proveedor encargado de buscar cadenas traducibles como combinación de otros proveedores
 */
class MultiProvider extends Provider
{
    /** @var Provider[] */
    private $_providers = [];

    public function add(Provider $provider)
    {
        $this->_providers[] = $provider;
    }

    /** @inheritDoc */
    public function getStrings(): array
    {
        $found = [];

        foreach ($this->_providers as $provider) {
            foreach ($provider->getStrings() as $string) {
                $found[] = $string;
            }
        }

        return $found;
    }
}