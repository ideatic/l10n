<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog\Serializer;

use ideatic\l10n\LString;
use ideatic\l10n\Project;
use stdClass;

class JSON extends Serializer
{
    public function __construct()
    {
        $this->fileExtension = 'json';
    }

    public bool $extended = false;

    /** @inheritDoc */
    public function generate(array $domains, stdClass|Project|null $config = null): string
    {
        if ($this->extended) {
            $translations = [];

            foreach ($domains as $domain) {
                foreach ($domain->strings as $strings) {
                    $row = [];
                    $string = array_first($strings);
                    $translation = $this->locale
                        ? $domain->translator->getTranslation($string, $this->locale, false)
                        : null;


                    if ($string->fullyQualifiedID() != $string->id) {
                        $row['id'] = $string->fullyQualifiedID();
                    }

                    $idIsNormalizedText = $string->text == $string->id;
                    if ($string->isICU && !$idIsNormalizedText) {
                        $pattern = new \ideatic\l10n\Utils\ICU\Pattern($string->text);
                        if (count($pattern->nodes) == 1 && $pattern->nodes[0] instanceof \ideatic\l10n\Utils\ICU\Placeholder && $pattern->nodes[0]->type == 'plural') {
                            $pattern->nodes[0]->name = 'count';
                            $idIsNormalizedText = $pattern->render(false) == $string->id;
                        }
                    }
                    if ($idIsNormalizedText) {
                        $row['original'] = $string->id;
                    } else {
                        $row['id'] = $string->id;
                        $row['original'] = $string->text;
                    }

                    if ($string->context) {
                        $row['context'] = $string->context;
                    }

                    if ($string->isICU) {
                        $row['format'] = 'icu';
                    }

                    $comments = array_filter($strings, fn(LString $s) => !!$s->comments);
                    if (!empty($translation?->metadata->comments)) {
                        $comments[] = $translation->metadata->comments;
                    }

                    $comments = mb_trim(implode(PHP_EOL, array_unique(array_map(fn(LString|string $string) => is_string($string) ? $string : $string->comments, $comments))));
                    if (!empty($comments)) {
                        $row['comments'] = $comments;
                    }

                    if ($this->includeLocations) {
                        $row['locations'] = array_map(fn(LString $str) => "{$str->file}:{$str->line}", $strings);
                    }

                    $referenceLocales = $this->referenceTranslation ?? [];
                    foreach ($referenceLocales as $k => $referenceLocale) {
                        if (str_starts_with($referenceLocale, 'pending:')) {
                            unset($referenceLocales[$k]);
                            foreach (explode(',', substr($referenceLocale, strlen('pending:'))) as $localeToCheck) {
                                if ($domain->translator->getTranslation($string, $localeToCheck, false) === null) {
                                    $referenceLocales[] = $localeToCheck;
                                }
                            }
                        }
                    }

                    foreach ($referenceLocales as $referenceLocale) {
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

                    if (isset($translation?->translation)) {
                        $row['translation'] = $translation->translation;
                    } elseif ($this->locale) {
                        $row['translation'] = null;
                    }

                    /*if (count($row) == 2 && isset($row['translation']) && $row['original'] == $string->fullyQualifiedID()) {
                        $row = $row['translation'];
                    }*/

                    $translations[] = $row;
                }
            }

            return json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } else {
            return json_encode($this->_generate($domains), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
    }
}


