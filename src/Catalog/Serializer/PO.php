<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog\Serializer;

use Exception;
use ideatic\l10n\LString;
use ideatic\l10n\Project;
use ideatic\l10n\Utils\Gettext\PluralExpression;
use ideatic\l10n\Utils\ICU\Pattern;
use ideatic\l10n\Utils\ICU\Placeholder;
use ideatic\l10n\Utils\Locale;
use stdClass;

/**
 * Writes catalogs as gettext PO files, the format most translation tools accept.
 *
 * Since gettext knows nothing about ICU, a plural pattern is converted into msgid/msgid_plural and the original
 * pattern is kept in a comment so that the PO loader can rebuild it.
 */
class PO extends Serializer
{
    public const string ICU_PREFIX = 'ICU: ';

    public bool $transformICU = true;

    /** @inheritDoc */
    public function generate(array $domains, stdClass|Project|null $config = null): string
    {
        $po = [];

        // Headers
        if (!empty($this->comments)) {
            $po[] = "# {$this->comments}";
        }
        $po[] = 'msgid ""';
        $po[] = 'msgstr ""';
        //$po[] = '"Project-Id-Version: ' . app::$name . '\n"';
        $po[] = '"PO-Revision-Date: ' . gmdate("D, d M Y H:i:s", time()) . '\n"';
        $po[] = '"Content-Type: text/plain; charset=utf-8\n"';
        $po[] = '"Content-Transfer-Encoding: 8bit\n"';
        if ($this->locale) {
            $po[] = '"Language: ' . $this->locale . '\n"';

            $pluralForms = PluralExpression::getAll();

            if (isset($pluralForms[$this->locale])) {
                $po[] = '"Plural-Forms: ' . $pluralForms[$this->locale] . '\n"';
            } else {
                foreach (Locale::getVariants($this->locale) as $locale) {
                    if (isset($pluralForms[$locale])) {
                        $po[] = '"Plural-Forms: ' . $pluralForms[$locale] . '\n"';
                        break;
                    }
                }
            }
        }
        $po[] = '"MIME-Version: 1.0\n"';
        $po[] = '';

        // Write the strings
        foreach ($domains as $domain) {
            foreach ($domain->strings as $strings) {
                $string = array_first($strings);
                $translation = $this->locale ? $domain->translator->getTranslation($string, $this->locale, false) : null;

                $isICU = false;
                foreach ($strings as $str) {
                    if ($str->isICU) {
                        $isICU = true;
                    }
                }

                // Comments, merging the ones from the code with those the catalog already carried
                $comments = array_map(fn(LString $s) => $s->comments, $strings);
                $comments[] = $translation?->metadata->comments ?? null;

                foreach (explode(PHP_EOL, self::mergeComments(array_values($comments))) as $comment) {
                    if ($comment !== '') {
                        $po[] = "#. {$comment}";
                    }
                }
                foreach ($this->referenceTranslation ?? [] as $referenceLocale) {
                    $localeName = Locale::getName($referenceLocale);
                    $referenceTranslation = $domain->translator->getTranslation($string, $referenceLocale, false);

                    if ($referenceTranslation) {
                        $po[] = "#. {$localeName}: " . str_replace(["\r\n", "\n"], "\n#. ", $referenceTranslation->translation);
                    }
                }

                // Include the original ICU expression
                if ($isICU) {
                    if ($this->transformICU) {
                        $po[] = '#. ' . self::ICU_PREFIX . str_replace(["\r\n", "\n"], "\n#. ", $string->id);
                    } else {
                        $po[] = '#. ICU expression';
                    }
                }

                // Locations
                if ($this->includeLocations) {
                    foreach ($strings as $str) {
                        $po[] = "#: {$str->file}:{$str->line}";
                    }
                }

                // Context
                if ($string->context) {
                    $po[] = 'msgctxt ' . $this->_escape($string->context);
                }

                // Translation
                if ($isICU && $this->transformICU && self::isSuitableIcuPattern(new Pattern($string->text))) { // Try to transform it into the gettext plural format
                    $originalICU = new Pattern($string->text);

                    $originalPlaceholders = array_values($originalICU->nodes[0]->content);

                    $po[] = 'msgid ' . $this->_escape($originalPlaceholders[0]->render());
                    $po[] = 'msgid_plural ' . $this->_escape($originalPlaceholders[1]->render());

                    if ($translation?->translation) {
                        $translatedPlural = new Pattern($translation->translation);

                        if (!self::isSuitableIcuPattern($translatedPlural)) {
                            throw new Exception("Invalid ICU pattern found for message '{$string->id}' translation '{$translation->translation}'");
                        }

                        foreach (array_values($translatedPlural->nodes[0]->content) as $i => $placeholder) {
                            $po[] = "msgstr[{$i}] " . $this->_escape($placeholder->render());
                        }
                    } else {
                        $po[] = "msgstr[0] " . $this->_escape('');
                    }
                } else {
                    $po[] = 'msgid ' . $this->_escape($string->id);
                    $po[] = 'msgstr ' . $this->_escape($translation->translation ?? '');
                }

                $po[] = '';
            }
        }

        return implode("\n", $po);
    }

    private function _escape(string $str): string
    {
        if ($str === '') {
            return '""';
        }

        $str = str_replace("\r\n", "\n", $str);

        $str = str_replace('"', '\"', $str);
        $str = str_replace("\n", "\\n", $str);
        $str = str_replace("\t", "\\t", $str);

        return '"' . $str . '"';
    }

    public static function isSuitableIcuPattern(Pattern $pattern): bool
    {
        // Only ICU expressions doing a direct plural selection are accepted: {stats.goals, plural, =1 {Gol} other {Goles}}
        if (count($pattern->nodes) == 1 && $pattern->nodes[0] instanceof Placeholder) {
            $placeholder = $pattern->nodes[0];

            if ($placeholder->type == 'plural' && isset(array_values($placeholder->content)[1])) {
                return true;
            }
        }

        return false;
    }
}
