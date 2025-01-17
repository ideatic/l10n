<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog\Loader;

use Exception;
use ideatic\l10n\Catalog\Catalog;
use ideatic\l10n\Catalog\Translation;

abstract class ArrayLoader extends Loader
{
    protected function _parse(array $rawDictionary, string $locale): Catalog
    {
        $strings = [];
        foreach ($rawDictionary as $stringID => $translation) {
            if (isset($translation) && $translation !== '') {
                if (!is_string($translation)) {
                    throw new Exception("Only strings values allowed");
                }

                $strings[$stringID] = new Translation($translation);
            }
        }

        return new Catalog($locale, $strings);
    }
}


