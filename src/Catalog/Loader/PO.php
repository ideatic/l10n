<?php

namespace ideatic\l10n\Catalog\Loader;

use ideatic\l10n\LString;
use ideatic\l10n\Utils\Gettext\IcuConverter;
use ideatic\l10n\Utils\ICU\Pattern;
use ideatic\l10n\Utils\Str;
use Sepia\PoParser\Catalog\Catalog;

class PO extends Loader
{
    /**
     * @inheritDoc
     */
    public function load(string $content, string $locale): \ideatic\l10n\Catalog\Catalog
    {
        $handler = new  \Sepia\PoParser\SourceHandler\StringSource($content);
        $poParser = new \Sepia\PoParser\Parser($handler);
        $domain = $poParser->parse();

        $strings = [];

        foreach ($domain->getEntries() as $entry) {
            $string = new LString();
            $string->id = $entry->getMsgId();
            $string->context = $entry->getMsgCtxt();
            $string->comments = implode(" ", $entry->getTranslatorComments()) . implode(" ", $entry->getDeveloperComments());

            if ($entry->isPlural()) {
                // Obtener expresión ICU original
                if (!preg_match('/' . preg_quote(\ideatic\l10n\Catalog\Serializer\PO::ICU_PREFIX, '/') . '(.+)/', $string->comments, $match)) {
                    throw new \Exception("Unable to recover source ICU pattern for '{$string->id}'");
                }

                $string->fullID = $match[1]; // Usar patrón ICU original como identificador de la cadena
                $icuPattern = new Pattern($match[1]);

                if (!\ideatic\l10n\Catalog\Serializer\PO::isSuitableIcuPattern($icuPattern)) {
                    throw new \Exception("Invalid recovered ICU pattern '{$icuPattern->render()}'");
                }

                // Traducir formato GetText a ICU utilizando la plantilla original como referencia
                $translation = IcuConverter::getTextPluralToICU($icuPattern, $entry->getMsgStrPlurals(), $this->_readPluralRules($domain))
                                           ->render(false);
            } else {
                $translation = $entry->getMsgStr() ?: null;
            }

            if ($translation === null) {
                continue;
            }

            $strings[$string->fullyQualifiedID()] = $translation;
        }

        return new \ideatic\l10n\Catalog\Catalog($locale, $strings);
    }

    private function _readPluralRules(Catalog $poCatalog): string
    {
        $pluralRulesStr = null;
        foreach ($poCatalog->getHeaders() as $header) {
            if (Str::startsWith($header, 'Plural-Forms:')) {
                $pluralRulesStr = trim(substr($header, strlen('Plural-Forms:')));
            }
        }

        if (!$pluralRulesStr) {
            throw new \Exception("No plural rules found in source file");
        } elseif (!preg_match('/plural=(.+)/', $pluralRulesStr, $match)) {
            throw new \Exception("Invalid plural rules '{$pluralRulesStr}'");
        }

        return rtrim($match[1], ';');
    }
}