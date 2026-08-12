<?php

declare(strict_types=1);

namespace ideatic\l10n\Translation;

use ideatic\l10n\Catalog\Catalog;
use ideatic\l10n\Domain;

/**
 * Interfaz para los proveedores de traducciones capaces de entregar un catálogo completo, y no solo la traducción
 * de una cadena concreta
 */
interface CatalogProvider
{
    public function loadCatalog(Domain|string $domain, string $locale): ?Catalog;
}
