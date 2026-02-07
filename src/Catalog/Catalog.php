<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog;

use ideatic\l10n\LString;

/**
 * Un catálogo representa una colección de traducciones para un mismo idioma. Normalmente se corresponde con un archivo de traducción.
 */
class Catalog
{
    /**
     * @param array<string, Translation> $_translations Diccionario de traducciones, indexado por el ID completo de la cadena (ID + contexto) y el valor de la traducción.
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

    public function removeComments(): void
    {
        foreach ($this->_translations as $translation) {
            $translation->metadata->comments = '';
        }
    }

    public function __debugInfo()
    {
        $translations = [];
        foreach ($this->_translations as $id => $translation) {
            $translations[] = [
                'id' => $id,
                'translation' => $translation->translation,
                'metadata' => $translation->metadata,
            ];
        }

        return [
            'locale' => $this->locale,
            'translations' => $translations,
        ];
    }
}
