<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog;

use ideatic\l10n\LString;

/**
 * A catalog is a collection of translations for a single locale. It usually corresponds to one translation file.
 */
class Catalog
{
    /**
     * @param array<string, Translation> $_translations Dictionary of translations, indexed by the full id of the string (id + context).
     */
    public function __construct(
        public readonly string $locale,
        private readonly array $_translations,
    ) {
        foreach ($_translations as $translation) {
            $translation->catalog = $this;
        }
    }

    /**
     * Gets the translation of a string, or null when this catalog does not hold it.
     *
     * The lookup is made on the fully qualified id, so two strings sharing the same text but declaring a different
     * context are kept apart.
     */
    public function getTranslation(LString $string): ?Translation
    {
        return $this->_translations[$string->fullyQualifiedID()] ?? null;
    }

    /**
     * Gets every translation in the catalog, indexed by the full id of the string
     *
     * @return array<string, Translation>
     */
    public function getEntries(): array
    {
        return $this->_translations;
    }

    /** Number of entries held by the catalog. Zero means the locale is declared but nothing has been translated */
    public function entryCount(): int
    {
        return count($this->_translations);
    }

    /** Drops the comments of every entry, so they are not carried over when the catalog is serialised again */
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
