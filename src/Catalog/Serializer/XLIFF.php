<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog\Serializer;

use Exception;
use HTML_Parser;
use HTML_Parser_Document;
use HTML_Parser_Element;
use HTML_Parser_Text;
use ideatic\l10n\Project;
use ideatic\l10n\String\Format\Angular_HTML;
use ideatic\l10n\Utils\ICU\Pattern;
use ideatic\l10n\Utils\ICU\Placeholder;
use ideatic\l10n\Utils\IO;
use ideatic\l10n\Utils\Str;
use stdClass;

class XLIFF extends Serializer
{
  /** @inheritDoc */
  public function generate(array $domains, stdClass|Project|null $config = null): string
  {
    if (!isset($config->translations->source)) {
      throw new Exception("Original XLIFF must be indicated in the 'translations > source' field for project {$config->name}");
    }

    [$source, $sourceStrings] = $this->_parseSource(IO::read(IO::combinePaths($config->path, $config->translations->source)));

    foreach ($domains as $domain) {
      foreach ($domain->strings as $strings) {
        $string = reset($strings);
        $stringID = $string->id;

        // Convertir traducciones ICU al formato esperado por angular
        $pattern = new Pattern($stringID);
        $replacePlural = false;
        if (count($pattern->nodes) == 1 && $pattern->nodes[0] instanceof Placeholder) {
          $placeholder = $pattern->nodes[0];

          if ($placeholder->type == 'plural') {
            $placeholder->name = $replacePlural = 'VAR_PLURAL';
            $stringID = $pattern->render(false);
          }
        }

        $transUnit = $sourceStrings[$stringID] ?? throw new Exception("Unable to find XLIFF trans-unit element for string '{$string->id}'");

        // Incluir traducción
        $translation = $this->locale ? $domain->translator->getTranslation($string, $this->locale, false)?->translation : '';
        if ($translation) {
          if ($replacePlural) {
            $pattern = new Pattern($translation);
            if (count($pattern->nodes) == 1 && $pattern->nodes[0] instanceof Placeholder) {
              $placeholder = $pattern->nodes[0];

              if ($placeholder->type == 'plural') {
                $placeholder->name = $replacePlural;
                $translation = $pattern->render(false);
              }
            }
          }

          // Convertir placeholder, por ejemplo: %code% -> <x id="INTERPOLATION" equiv-text="{{ 404 | number }}"/>
          $translation = $this->_restoreTranslationPlaceholders($transUnit, $translation);
          $this->_setTranslation($transUnit, $translation);
        }
      }
    }

    // Corregir final del documento
    $output = $source->render();
    if (str_ends_with($output, '</?xml>')) {
      $output = substr($output, 0, -7);
    }
    return $output;
  }

  /**
   * @param string $xml
   *
   * @return array{HTML_Parser_Document, array<string, HTML_Parser_Element>}
   */
  private function _parseSource(string $xml): array
  {
    $source = HTML_Parser::parse($xml, true, []);
    $foundStrings = [];
    $source->walk(
        function ($transUnit) use (&$foundStrings) {
          if (!($transUnit instanceof HTML_Parser_Element) || strtolower($transUnit->tag) != 'trans-unit') {
            return;
          }

          $sourceElement = $transUnit->findAll('source')[0]
                           ?? throw new Exception('Missing source element for XLIFF trans-unit ' . $transUnit->getAttributeValue('id'));

          $foundStrings[$this->_getStringID($sourceElement)] = $transUnit;
        }
    );

    return [$source, $foundStrings];
  }

  private function _getStringID(HTML_Parser_Element $sourceTag): string
  {
    // Convertir los <x equiv-text>
    $innerHTML = [];
    $hasPlaceholders = false;
    foreach ($sourceTag->children as $child) {
      if ($child instanceof HTML_Parser_Text) {
        $innerHTML[] = $child->render();
      } elseif ($child instanceof HTML_Parser_Element && $child->tag == 'x') {
        $hasPlaceholders = true;
        $innerHTML[] = $this->_decodeHtmlEntities(
            $child->getAttributeValue('equiv-text')
            ?? throw new Exception('Missing equiv-text attribute in XLIFF source string: ' . $sourceTag->render())
        );
      } else {
        throw new Exception('Unexpected element in XLIFF source string: ' . $child->render());
      }
    }
    $innerHTML = implode('', $innerHTML);

    // Procesar como una cadena HTML estándar
    if ($hasPlaceholders) {
      $htmlFormat = new Angular_HTML();
      $foundStrings = $htmlFormat->getStrings("<div i18n>{$innerHTML}</div>");

      if (empty($foundStrings)) {
        throw new Exception('Invalid string found in XLIFF source string: ' . $sourceTag->render());
      } elseif (count($foundStrings) > 1) {
        throw new Exception('Multiple strings found in XLIFF source string: ' . $sourceTag->render());
      } else {
        return reset($foundStrings)->id;
      }
    } else {
      return Str::trim($innerHTML);
    }
  }

  private function _decodeHtmlEntities(string $str): string
  {
    return str_replace('&apos;', "'", HTML_Parser::entityDecode($str));
  }

  private function _restoreTranslationPlaceholders(HTML_Parser_Element $transUnit, string $translation): string
  {
    $replacements = [];
    $sourceTag = $transUnit->findAll('source')[0];
    $placeholders = $sourceTag->findAll('x');
    for ($i = 0; $i < count($placeholders); $i++) {
      $placeholder = $placeholders[$i];

      $placeholderName = $this->_decodeHtmlEntities($placeholder->getAttributeValue('equiv-text'));
      $replacement = $placeholder->render();

      if (str_starts_with($placeholderName, '{{')) {
        $htmlFormat = new Angular_HTML();
        $placeholderName = $htmlFormat->getStrings("<div i18n>{$placeholderName}</div>")[0]->id;
      } elseif (str_contains($placeholderName, 'i18nPlaceholder')) {
        $wrapper = HTML_Parser::parse($placeholderName, true, [])->children[0];
        $placeholderName = $wrapper->getAttributeValue('i18nPlaceholder')
                           ?? throw new Exception("Missing i18nPlaceholder attribute in XLIFF source string: '{$placeholderName}'");

        if (!$wrapper->hasAttribute('i18nContent')) {
          $endFound = false;
          $replacement = '';
          for (; $i < count($placeholders); $i++) {
            $replacement .= $placeholders[$i]->render();
            $equivText = $placeholders[$i]->getAttributeValue('equiv-text');
            if ($equivText && $this->_decodeHtmlEntities($equivText) == '</' . $wrapper->tag . '>') {
              $endFound = true;
              break;
            }
          }

          if (!$endFound) {
            throw new Exception("Missing closing tag for XLIFF placeholder '{$placeholderName}' @ '{$sourceTag->render()}");
          }
        }
      } else { // Probar si es ICU
        $pattern = new Pattern($placeholderName);
        if ($pattern->hasPlaceholders()) {
          $replacement = $placeholderName;
        }
      }

      $replacements[$placeholderName] = $replacement;
    }

    return strtr($translation, $replacements);
  }

  private function _setTranslation(HTML_Parser_Element $transUnit, string $translation): void
  {
    foreach ($transUnit->findAll('target') as $target) {
      $target->remove();
    }

    $target = [
        new HTML_Parser_Text($transUnit->children[0] instanceof HTML_Parser_Text ? $transUnit->children[0]->render() : "\n"),
        HTML_Parser_Element::create('target', [], new HTML_Parser_Text($translation))
    ];

    /** @phpstan-ignore-next-line */
    if (!$transUnit->findAll('source')[0]?->appendSibling($target)) {
      foreach ($target as $t) {
        $transUnit->children[] = $t;
      }
    }
  }
}
