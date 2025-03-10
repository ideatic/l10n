<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog\Serializer;

use ideatic\l10n\LString;
use ideatic\l10n\Project;
use stdClass;

class JSON extends ArraySerializer
{
    public function __construct()
    {
        $this->fileExtension = 'json';
    }

    public bool $extended = false;

    /** @inheritDoc */
    public function generate(array $domains, stdClass|Project $config = null): string
    {
        if ($this->extended) {
            $translations = [];

            foreach ($domains as $domain) {
                foreach ($domain->strings as $strings) {
                    $row = [];
                    $string = reset($strings);
                    $translation = $this->locale
                        ? $domain->translator->getTranslation($string, $this->locale, false)
                        : null;


                    if ($string->fullyQualifiedID() != $string->id) {
                        $row['id'] = $string->fullyQualifiedID();
                    }

                    $row['original'] = $string->id;

                    if ($string->context) {
                        $row['context'] = $string->context;
                    }

                    $comments = array_filter($strings, fn(LString $s) => !!$s->comments);
                    if (!empty($translation?->metadata->comments)) {
                        $comments[] = $translation->metadata->comments;
                    }

                    $comments = implode(PHP_EOL, array_unique(array_map(fn(LString $string) => $string->comments, $comments)));
                    if (!empty($comments)) {
                        $row['comments'] = $comments;
                    }

                    if ($this->includeLocations) {
                        $row['locations'] = array_map(fn(LString $str) => "{$str->file}:{$str->line}", $strings);
                    }

                    foreach ($this->referenceTranslation ?? [] as $referenceLocale) {
                        if ($referenceLocale != $this->locale) {
                            $referenceTranslation = $domain->translator->getTranslation($string, $referenceLocale, false);

                            if ($this->locale) {
                                $row[$referenceLocale] = $referenceTranslation?->translation;
                            } else {
                                $row['translations'] ??= [];
                                $row['translations'][$referenceLocale] = $referenceTranslation?->translation;
                            }
                        }
                    }

                    if (isset($translation)) {
                        $row['translation'] = $translation->translation;
                    }

                    if (count($row) == 2 && isset($row['translation']) && $row['original'] == $string->fullyQualifiedID()) {
                        $row = $row['translation'];
                    }

                    $translations[] = $row;
                }
            }

            return json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } else {
            return json_encode($this->_generate($domains), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
    }
}


