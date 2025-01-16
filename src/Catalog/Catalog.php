<?php

declare(strict_types=1);


namespace ideatic\l10n\Catalog;

use ideatic\l10n\LString;

class Catalog
{
    private array $_strings;

    /** Contenido original de este catálogo */
    public string $rawContent;

    public function __construct(public readonly string $locale, array $translations)
    {
        $this->_strings = $translations;
    }

    public function getTranslation(LString $string): ?string
    {
        return $this->_strings[$string->fullyQualifiedID()] ?? null;
    }

    public function entryCount(): int
    {
        return count($this->_strings);
    }
}
