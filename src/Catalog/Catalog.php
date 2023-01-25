<?php


namespace ideatic\l10n\Catalog;

use ideatic\l10n\LString;

class Catalog
{
    private array $_strings;

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
