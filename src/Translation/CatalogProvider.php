<?php

declare(strict_types=1);

namespace ideatic\l10n\Translation;

use ideatic\l10n\Catalog\Catalog;
use ideatic\l10n\Domain;

/**
 * Interface for the translation providers able to hand over a whole catalog, and not just the translation of one
 * specific string
 */
interface CatalogProvider
{
    public function loadCatalog(Domain|string $domain, string $locale): ?Catalog;
}
