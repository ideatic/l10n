<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog\Serializer;

use ideatic\l10n\Domain;

abstract class ArraySerializer extends Serializer
{
  /**
   * @param Domain[] $domains
   */
  protected function _generate(array $domains): array
  {
    $translations = [];

    foreach ($domains as $domain) {
      foreach ($domain->strings as $strings) {
        $string = reset($strings);

        $stringID = $string->fullyQualifiedID();
        if ($this->locale) {
          $translation = $domain->translator->getTranslation($string, $this->locale, false);

            if (isset($translation) && !$this->onlyPending) {
            $translations[$stringID] = $translation;
            } elseif ($this->onlyPending) {
                $translations[$stringID] = null;
            }
        } else {
          $translations[$stringID] = null;
        }
      }
    }

    return $translations;
  }
}


