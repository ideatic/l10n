<?php

namespace ideatic\l10n\Catalog\Loader;

use ideatic\l10n\Catalog\Catalog;

abstract class Loader
{
    public static function factory(string $name): self
    {
        if ($name == 'po') {
            return new PO();
        } elseif ($name == 'json') {
            return new JSON();
        } elseif ($name == 'php') {
            return new PHP();
        } else {
            throw new \InvalidArgumentException("Invalid serializer format '{$name}'");
        }
    }

    /**
     * Obtiene un catálogo de cadenas traducibles desde un archivo
     */
    public abstract function load(string $content, string $locale): Catalog;
}

