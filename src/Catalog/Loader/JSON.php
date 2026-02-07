<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog\Loader;

use ideatic\l10n\Catalog\Catalog;
use ideatic\l10n\Catalog\Translation;
use ideatic\l10n\LString;
use InvalidArgumentException;

class JSON extends ArrayLoader
{
    /** @inheritDoc */
    public function load(string $content, string $locale): Catalog
    {
        $rawDictionary = json_decode($content, true);

        if (json_last_error() != JSON_ERROR_NONE) {
            throw new InvalidArgumentException("Unable to parse JSON " . json_last_error_msg());
        } elseif (!is_array($rawDictionary)) {
            throw new InvalidArgumentException("Invalid JSON, array expected");
        }

        // Check if is the extended format
        $strings = [];
        $isList = array_is_list($rawDictionary);
        foreach ($rawDictionary as $key => $value) {
            $stringID = $key;
            $metadata = new LString();

            if (is_array($value)) {
                if (isset($value['id'])) {
                    $stringID = $value['id'];
                } elseif ($isList && isset($value['original'])) {
                    $stringID = $value['original'];
                }

                $translation = $value['translations'][$locale] ?? $value['translation'] ?? null;

                if (is_array($translation)) {
                    foreach (['translation', 'text', 'value', 'string'] as $field) {
                        if (array_key_exists($field, $translation)) {
                            $translation = $translation[$field];
                            break;
                        }
                    }
                }

                if (is_array($translation)) {
                    throw new InvalidArgumentException("Only string values allowed for translation of '{$stringID}'");
                }

                $metadata->comments = $value['comments'] ?? null;
                $metadata->context = $value['context'] ?? null;
                if (($value['format'] ?? null) === 'icu') {
                    $metadata->isICU = true;
                }
            } else {
                $translation = $value;
            }


            if (isset($translation) && $translation !== '') {
                $metadata->id = $stringID;
                $strings[$stringID] = new Translation($translation, $metadata);
            }
        }

        return new Catalog($locale, $strings);
    }
}


