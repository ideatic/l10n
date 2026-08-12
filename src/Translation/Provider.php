<?php

declare(strict_types=1);

namespace ideatic\l10n\Translation;

use ideatic\l10n\Catalog\Translation;
use ideatic\l10n\LString;

/**
 * Interface for the translation providers
 */
interface Provider
{
  public function getTranslation(LString $string, string $locale, bool $allowFallback = true): ?Translation;
}
