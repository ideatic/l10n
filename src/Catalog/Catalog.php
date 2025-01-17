<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog;

use ideatic\l10n\LString;

class Catalog
{
    /**
     * @param array<string, Translation> $_translations
     */
    public function __construct(
        public readonly string $locale,
        private readonly array $_translations,
    ) {
        foreach ($_translations as $translation) {
            $translation->catalog = $this;
        }
    }

    public function getTranslation(LString $string): ?Translation
    {
        return $this->_translations[$string->fullyQualifiedID()] ?? null;
    }

    public function entryCount(): int
    {
        return count($this->_translations);
    }
}
