<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog\Serializer;

use ideatic\l10n\LString;
use ideatic\l10n\Project;
use stdClass;

class PHP extends ArraySerializer
{
    /** @inheritDoc */
    public function generate(array $domains, stdClass|Project $config = null): string
    {
        $phpArray = ['['];
        foreach ($domains as $domain) {
            $lastKey = array_key_last($domain->strings);
            foreach ($domain->strings as $key => $strings) {
                $string = reset($strings);

                $translation = null;
                if ($this->locale) {
                    $translation = $domain->translator->getTranslation($string, $this->locale, false);

                    if (!isset($translation)) {
                        continue;
                    }
                }


                $comments = array_unique(array_map(fn(LString $s) => $s->comments, array_filter($strings, fn(LString $s) => trim($s->comments ?? ''))));

                if (!empty($comments)) {
                    $comments = implode(PHP_EOL, $comments);
                    $phpArray[] = '  ' . var_export($string->fullyQualifiedID(), true) . ' /* ' . $comments . ' */ => ' . var_export($translation->translation, true)
                        . ($key == $lastKey ? '' : ',');
                } else {
                    $phpArray[] = '  ' . var_export($string->fullyQualifiedID(), true) . ' => ' . var_export($translation->translation, true)
                        . ($key == $lastKey ? '' : ',');
                }
            }
        }
        $phpArray [] = ']';
        $phpArray = implode(PHP_EOL, $phpArray);

        $php = ['<?php'];
        $php[] = 'declare(strict_types=1);';
        $php[] = '';
        $comments = trim($this->comments) ?: 'Created ' . date('r');
        $php[] = "/* {$comments} */";
        $php[] = '';
        $php[] = "return {$phpArray};";

        return implode(PHP_EOL, $php);
    }
}

