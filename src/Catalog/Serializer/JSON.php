<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog\Serializer;

use ideatic\l10n\Domain;
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
                    $string = reset($strings);

                    $row = [
                        'original' => $string->text ?: $string->id,
                    ];

                    if ($string->context) {
                        $row['context'] = $string->context;
                    }

                    $comments = array_filter($strings, fn(LString $s) => !!$s->comments);
                    if (!empty($comments)) {
                        $row['comments'] = implode(PHP_EOL, array_unique(array_map(fn(LString $string) => $string->comments, $comments)));
                    }

                    foreach ($this->referenceTranslation ?? [] as $referenceLocale) {
                        if ($referenceLocale != $this->locale) {
                            $referenceTranslation = $domain->translator->getTranslation($string, $referenceLocale, false);

                            if ($this->locale) {
                                $row[$referenceLocale] = $referenceTranslation;
                            } else {
                                $row['translations'] ??= [];
                                $row['translations'][$referenceLocale] = $referenceTranslation;
                            }
                        }
                    }

                    if ($this->locale) {
                        $translation = $domain->translator->getTranslation($string, $this->locale, false);

                        if (isset($translation)) {
                            $row['translation'] = $translation;
                        }
                    }

                    $hasNullValue = static fn(array $array) => array_reduce($array, fn($carry, $item) => $carry || $item === null, false);
                    if ($this->onlyPending && ($this->locale ? !empty($row['translation']) : !$hasNullValue($row['translations'] ?? []))) {
                        continue;
                    }

                    if (count($row) == 2 && isset($row['translation']) && $row['original'] == $string->fullyQualifiedID()) {
                        $row = $row['translation'];
                    }

                    $translations[$string->fullyQualifiedID()] = $row;
                }
            }

            return json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } else {
            return json_encode($this->_generate($domains), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
    }

    /**
     * @param string $previous
     * @param list<Domain> $domains
     */
    public function generateAndMerge(string $previous, array $domains, stdClass|Project $config = null): string
    {
        $previous = json_decode($previous, true, JSON_THROW_ON_ERROR);
        $new = json_decode($this->generate($domains), true, JSON_THROW_ON_ERROR);

        $combined = $new;

        // Completar la información que existía en el archivo anterior
        foreach ($previous as $stringID => $translation) {
            if (isset($combined[$stringID])) {
                if (is_array($translation)) {
                    foreach ($translation['translations'] as $k => $v) {
                        $combined[$stringID]['translations'][$k] ??= $v;
                    }

                    if (isset($translation['translation'])) {
                        $combined[$stringID]['translation'] ??= $translation['translation'];
                    }
                }
            } else {
                $combined[$stringID] = $translation;
            }
        }

        return json_encode($combined, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}


