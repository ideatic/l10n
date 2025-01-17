<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog;

use ideatic\l10n\LString;

/**
 * Representa una traducción de un catálogo
 */
class Translation
{
    /** Catálogo de traducciones al que pertenece esta traducción */
    public Catalog $catalog;

    public function __construct(
        public readonly string $translation,
        public readonly LString|null $metadata = null
    )
    {

    }
}
