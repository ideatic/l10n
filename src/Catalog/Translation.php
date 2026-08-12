<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog;

use ideatic\l10n\LString;

/**
 * Represents a translation held in a catalog
 */
class Translation
{
    /** Catalog this translation belongs to */
    public Catalog $catalog;

    /**
     * @param string  $translation The translated text
     * @param LString $metadata    What the catalog knows about the source string: its id, context and comments
     */
    public function __construct(
        public readonly string $translation,
        public readonly LString $metadata = new LString()
    )
    {

    }
}
