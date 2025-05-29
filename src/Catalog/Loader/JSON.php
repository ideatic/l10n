<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog\Loader;

use ideatic\l10n\Catalog\Catalog;
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
        $preparedDictionary = [];
        foreach ($rawDictionary as $key => $value) {
            if (isset($value['id'])) {
                $key = $value['id'];
            } elseif (is_int($key) && isset($value['original'])) {
                $key = $value['original'];
            }

            if (is_array($value)) {
                $value = $value['translations'][$locale] ?? $value['translation'] ?? null;

                if (is_array($value) && (array_key_exists('translation', $value) || array_key_exists('text', $value) || array_key_exists('value', $value))) {
                    $value = $value['translation'] ?? $value['text'] ?? $value['value'];
                }
            }

            $preparedDictionary[$key] = $value;
        }

        return $this->_parse($preparedDictionary, $locale);
    }
}


