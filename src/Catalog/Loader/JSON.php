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
        foreach ($rawDictionary as $key => $value) {
            if (is_array($value)) {
                $rawDictionary[$key] = $value['translations'][$locale] ?? $value['translation'] ?? null;
                
                if (is_array($rawDictionary[$key]) && array_key_exists('translation', $rawDictionary[$key])) {
                    $rawDictionary[$key] = $rawDictionary[$key]['translation'];
                }
            }
        }

        return $this->_parse($rawDictionary, $locale);
    }
}


