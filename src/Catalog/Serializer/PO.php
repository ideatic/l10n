<?php

namespace ideatic\l10n\Catalog\Serializer;

use ideatic\l10n\Utils\Gettext\PluralExpression;
use ideatic\l10n\Utils\ICU\Pattern;
use ideatic\l10n\Utils\ICU\Placeholder;
use ideatic\l10n\Utils\Locale;

class PO extends Serializer
{
    public const ICU_PREFIX = 'ICU: ';

    /** @inheritDoc */
    public function generate(array $domains): string
    {
        $po = [];

        // Cabeceras
        if (!empty($this->comments)) {
            $po[] = "# " . $this->comments;
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

        // Escribir cadenas
        foreach ($domains as $domain) {
            foreach ($domain->strings as $strings) {
                $string = reset($strings);

                $isICU = false;
                foreach ($strings as $str) {
                    if ($str->isICU) {
                        $isICU = true;
                    }
                }

                // Comentarios
                if ($this->referenceTranslation) {
                    $localeName = Locale::getName($this->referenceTranslation);
                    $referenceTranslation = $domain->translator->getTranslation($string, $this->referenceTranslation ?? '', false);

                    if ($referenceTranslation) {
                        $po[] = "#. {$localeName}: " . str_replace(["\r\n", "\n"], "\n#. ", $referenceTranslation);
                    }
                }

                foreach ($strings as $str) {
                    if ($str->comments) {
                        $po[] = '#. ' . str_replace(["\r\n", "\n"], "\n#. ", $str->comments);
                    }
                }

                // Incluir expresión ICU original
                if ($isICU) {
                    $po[] = '#. ' . self::ICU_PREFIX . str_replace(["\r\n", "\n"], "\n#. ", $string->id);
                }

                // Ubicaciones
                if ($this->includeLocations) {
                    foreach ($strings as $str) {
                        $po[] = "#: {$str->file}:{$str->line}";
                    }
                }

                // Contexto
                if ($string->context) {
                    $po[] = 'msgctxt ' . $this->_escape($string->context);
                }

                // Traducción
                $translation = $this->locale ? $domain->translator->getTranslation($string, $this->locale, false) : '';

                if ($isICU && self::isSuitableIcuPattern(new Pattern($string->text))) { // Intentar transformar al formato de plurales PO
                    $originalICU = new Pattern($string->text);

                    $originalPlaceholders = array_values($originalICU->nodes[0]->content);

                    $po[] = 'msgid ' . $this->_escape($originalPlaceholders[0]->render());
                    $po[] = 'msgid_plural ' . $this->_escape($originalPlaceholders[1]->render());

                    if ($translation) {
                        $translatedPlural = new Pattern($translation);

                        if (!self::isSuitableIcuPattern($translatedPlural)) {
                            throw new \Exception("Invalid ICU pattern found for message '{$string->id}' translation '{$translation}'");
                        }

                        foreach (array_values($translatedPlural->nodes[0]->content) as $i => $placeholder) {
                            $po[] = "msgstr[{$i}] " . $this->_escape($placeholder->render());
                        }
                    } else {
                        $po[] = "msgstr[0] " . $this->_escape('');
                    }
                } else {
                    $po[] = 'msgid ' . $this->_escape($string->id);
                    $po[] = 'msgstr ' . $this->_escape($translation ?? '');
                }

                $po[] = '';
            }
        }

        return implode("\n", $po);
    }

    private function _escape(string $str): string
    {
        $str = str_replace("\r\n", "\n", $str);

        $str = str_replace('"', '\"', $str);
        $str = str_replace("\n", "\\n", $str);
        $str = str_replace("\t", "\\t", $str);

        return '"' . $str . '"';
    }

    public static function isSuitableIcuPattern(Pattern $pattern): bool
    {
        // Solo se aceptan expresiones ICU que sean de selección directa de plurales: {stats.goals, plural, =1 {Gol} other {Goles}}
        if (count($pattern->nodes) == 1 && $pattern->nodes[0] instanceof Placeholder) {
            $placeholder = $pattern->nodes[0];

            if ($placeholder->type == 'plural') {
                return true;
            }
        }

        return false;
    }
}