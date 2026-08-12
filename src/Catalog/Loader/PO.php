<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog\Loader;

use Exception;
use ideatic\l10n\Catalog\Translation;
use ideatic\l10n\LString;
use ideatic\l10n\Utils\Gettext\IcuConverter;
use ideatic\l10n\Utils\ICU\Pattern;
use Sepia\PoParser\Catalog\Catalog;
use Sepia\PoParser\Parser;
use Sepia\PoParser\SourceHandler\StringSource;

/**
 * Reads catalogs from gettext PO files.
 *
 * Gettext has no ICU: a plural is stored as msgid/msgid_plural with one msgstr per form, so the original pattern is
 * written as a comment when exporting and recovered from it here.
 */
class PO extends Loader
{
    /** @inheritDoc */
    public function load(string $content, string $locale): \ideatic\l10n\Catalog\Catalog
    {
        $handler = new StringSource($content);
        $poParser = new Parser($handler);
        $domain = $poParser->parse();

        $strings = [];

        foreach ($domain->getEntries() as $entry) {
            $string = new LString();
            $string->id = $entry->getMsgId();
            $string->context = $entry->getMsgCtxt();
            $string->comments = implode(" ", $entry->getTranslatorComments()) . implode(" ", $entry->getDeveloperComments());

            if ($entry->isPlural()) {
                // Get the original ICU expression
                if (!preg_match('/' . preg_quote(\ideatic\l10n\Catalog\Serializer\PO::ICU_PREFIX, '/') . '(.+)/', $string->comments, $match)) {
                    throw new Exception("Unable to recover source ICU pattern for '{$string->id}'");
                }

                $string->fullID = $match[1]; // Use the original ICU pattern as the identifier of the string
                $icuPattern = new Pattern($match[1]);

                if (!\ideatic\l10n\Catalog\Serializer\PO::isSuitableIcuPattern($icuPattern)) {
                    throw new Exception("Invalid recovered ICU pattern '{$icuPattern->render()}'");
                }

                $isEmpty = true;
                foreach ($entry->getMsgStrPlurals() as $pluralForm) {
                    if ($pluralForm) {
                        $isEmpty = false;
                    }
                }

                // Traducir formato GetText a ICU utilizando la plantilla original como referencia
                $translation = $isEmpty
                    ? null
                    : IcuConverter::replacePluralsFormsFromGetText($icuPattern, $locale, $entry->getMsgStrPlurals(), $this->_readPluralRules($domain))
                        ->render(false);
            } else {
                $translation = $entry->getMsgStr() ?: null;
            }

            if ($translation === null) {
                continue;
            }

            $strings[$string->fullyQualifiedID()] = new Translation($translation, $string);
        }

        return new \ideatic\l10n\Catalog\Catalog($locale, $strings);
    }

    private function _readPluralRules(Catalog $poCatalog): string
    {
        $pluralRulesStr = null;
        foreach ($poCatalog->getHeaders() as $header) {
            if (str_starts_with($header, 'Plural-Forms:')) {
                $pluralRulesStr = trim(substr($header, strlen('Plural-Forms:')));
            }
        }

        if (!$pluralRulesStr) {
            throw new Exception("No plural rules found in source file");
        } elseif (!preg_match('/plural=(.+)/', $pluralRulesStr, $match)) {
            throw new Exception("Invalid plural rules '{$pluralRulesStr}'");
        }

        return rtrim($match[1], ';');
    }
}
