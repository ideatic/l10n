<?php

declare(strict_types=1);

namespace ideatic\l10n\String\Format;

use Exception;
use HTML_Parser;
use HTML_Parser_Attribute;
use HTML_Parser_Comment;
use HTML_Parser_Element;
use HTML_Parser_Text;
use ideatic\l10n\LString;
use ideatic\l10n\Plural\ICU;
use ideatic\l10n\Utils\ICU\Pattern;

/**
 * Provider of translatable strings found in HTML markup
 */
class HTML extends Format
{
    public bool $autoDetectIcuPatterns = true;
    public bool $normalizeWhitespaces = true;
    public bool $supportAngularExpressions = true;
    private array $_foundStrings;

    /** @inheritDoc */
    public function getStrings(string $html, mixed $path = null): array
    {
        $this->_foundStrings = [];

        $this->_process($html, $path);

        $found = $this->_foundStrings;
        unset($this->_foundStrings);
        return $found;
    }

    private function _process(string $html, ?string $path = null, ?callable $getTranslation = null): string|array
    {
        $dom = HTML_Parser::parse(
            $html,
            strict: $this->supportAngularExpressions,
            preserveAttributesWhitespace: false,
            supportAngularExpressions: $this->supportAngularExpressions
        );

        foreach ($dom->children as $node) {
            if ($node instanceof HTML_Parser_Element) {
                $this->_processNode($node, $html, $path, $getTranslation);
            }
        }

        // Remove the noI18n attributes
        if ($getTranslation) {
            $dom->walk(
                function ($node) {
                    if ($node instanceof HTML_Parser_Element && $node->hasAttribute('noI18n')) {
                        $node->hasAttribute('noI18n')->remove();
                    }
                }
            );
        }

        return $getTranslation ? $dom->render() : [];
    }

    private function _processNode(HTML_Parser_Element $element, string $source, ?string $path, ?callable $getTranslation = null): void
    {
        // Attribute translation
        $attrPrefix = 'i18n-';
        foreach ($element->attributes as $i18nAttribute) {
            if (!str_starts_with(strtolower($i18nAttribute->name), $attrPrefix)) {
                continue;
            }

            $attributeName = substr($i18nAttribute->name, strlen($attrPrefix));
            $attribute = $element->hasAttribute($attributeName);

            if (!$attribute) {
                throw new Exception("i18n attribute {$attributeName} not found in {$path}: " . $element->render());
            }

            $string = $this->_registerString($source, $path, HTML_Parser::entityDecode($attribute->value), $i18nAttribute, $attribute);

            if ($getTranslation) {
                $translation = call_user_func($getTranslation, $string);
                if ($translation !== null) {
                    $i18nAttribute->remove();
                    $attribute->value = $translation;
                }
            }
        }

        // The content of the element is translatable
        $i18nAttribute = $element->hasAttribute('i18n');
        if ($i18nAttribute) {
            // Process the child nodes
            $element->walk(
                function ($child) use ($source, $path, $getTranslation) {
                    if ($child instanceof HTML_Parser_Comment) { // Eliminar comentarios
                        $child->remove();
                    } elseif ($child instanceof HTML_Parser_Element) { // Traducir hijos
                        self::_processNode($child, $source, $path, $getTranslation);
                    }
                }
            );

            // Process the i18nPlaceholder attributes of the child elements
            $placeholders = $this->_processChildPlaceholders($element);

            $content = $this->_elementContent($element, $path);

            $string = $this->_registerString($source, $path, $content, $i18nAttribute, $element);
            $string->placeholders = $placeholders;

            if ($getTranslation) {
                $translation = call_user_func($getTranslation, $string);

                if ($translation !== null) {
                    // Remove the i18n attribute
                    $i18nAttribute->remove();
                    $element->hasAttribute('i18nWhitespaces')?->remove();

                    // Replace the old content with the new one
                    try {
                        $newContent = HTML_Parser::parse($translation, true)->children;
                    } catch (Exception $err) {
                        throw new Exception('Unable to parse HTML: ' . $err->getMessage() . ' at ' . $path . ' for ' . $translation);
                    }

                    if (strtolower($element->tag) == 'ng-container' && empty($element->attributes)) { // Eliminar ng-container utilizados solo para i18n
                        // Strip the whitespace before and after
                        while ($element->nextSibling() instanceof HTML_Parser_Text && !mb_trim($element->nextSibling()->render())) {
                            $element->nextSibling()->remove();
                        }
                        while ($element->previousSibling() instanceof HTML_Parser_Text && !mb_trim($element->previousSibling()->render())) {
                            $element->previousSibling()->remove();
                        }

                        $element->replaceWith(count($newContent) == 1 ? array_first($newContent) : $newContent);
                    } else {
                        $element->children = $newContent;
                    }
                }
            }
        } elseif (!$getTranslation) { // Check whether the node only holds text and is not marked as translatable
            if ($this->_checkIfMissingI18nAttribute($element)) {
                echo "Suspicious missing i18n attribute at {$path}: {$element->render()}\n";
            }
        }

        // Check that an i18n attribute is not used where it does not belong
        if ($element->hasAttribute('i18nPlaceholder') || $element->hasAttribute('i18nContent')) {
            $parent = $element->parent;

            $parentI18nFound = false;
            while ($parent instanceof HTML_Parser_Element) {
                if ($parent->hasAttribute('i18n')) {
                    $parentI18nFound = true;
                    break;
                }

                $parent = $parent->parent;
            }

            if (!$parentI18nFound) {
                throw new Exception("i18n special attribute with no i18n parent found in element in '{$path}': {$element->render()}");
            }
        }

        // Process the children
        foreach ($element->children as $child) {
            if ($child instanceof HTML_Parser_Element) {
                self::_processNode($child, $source, $path, $getTranslation);
            }
        }
    }

    /**
     * Gets the translatable text of an element marked with i18n, applying its i18nWhitespaces rule
     */
    private function _elementContent(HTML_Parser_Element $element, ?string $path): string
    {
        /** @phpstan-ignore nullsafe.neverNull */
        switch ($element->hasAttribute('i18nWhitespaces')?->value ?? ($this->normalizeWhitespaces ? 'normalize' : 'keep')) {
            case 'keep':
                $content = $element->innerHTML();
                break;

            case 'normalize':
                $content = preg_replace('/\s+/', ' ', mb_trim($element->innerHTML()));
                break;

            case 'trim':
                $content = mb_trim($element->innerHTML());
                break;

            case 'unindent':
                // Find and strip the common indentation
                $content = $element->innerHTML();
                preg_match_all('/^[ \t]*(?=\S)/m', $content, $matches);
                $minIndent = empty(array_filter($matches[0])) ? 0 : min(array_map(mb_strlen(...), array_filter($matches[0])));
                $content = mb_trim(preg_replace('/^[ \t]{' . $minIndent . '}/m', '', $content));
                break;

            default:
                throw new Exception("Invalid i18nWhitespaces value in {$path}: " . $element->render());
        }

        return HTML_Parser::entityDecode($content);
    }

    /**
     * Prepares the markup for a native Angular i18n build, where the Angular compiler extracts and inlines the
     * translations instead of this library.
     *
     * Every translatable string keeps its i18n attribute, whose value becomes the custom id (`@@id`) returned by
     * $stamp, so the translation can be found later without comparing texts. The syntax only this library understands
     * (i18nPlaceholder, i18nContent, i18nWhitespaces, noI18n...) is removed, since Angular would render it as plain
     * attributes.
     *
     * The strings are read exactly as getStrings() reads them, so they match the keys of the catalogs.
     *
     * @param callable(LString $string, 'element'|'attribute' $kind, string $raw): string $stamp Receives each string
     *                                                                                             and returns its id
     */
    public function annotate(string $html, callable $stamp, ?string $path = null): string
    {
        $this->_foundStrings = [];

        $dom = HTML_Parser::parse(
            $html,
            strict: $this->supportAngularExpressions,
            preserveAttributesWhitespace: false,
            supportAngularExpressions: $this->supportAngularExpressions
        );

        foreach ($dom->children as $node) {
            if ($node instanceof HTML_Parser_Element) {
                $this->_annotateNode($node, $html, $path, $stamp);
            }
        }

        $dom->walk(
            function ($node) {
                if ($node instanceof HTML_Parser_Element) {
                    $node->removeAttribute('noI18n');
                }
            }
        );

        unset($this->_foundStrings);

        return $dom->render();
    }

    private function _annotateNode(HTML_Parser_Element $element, string $source, ?string $path, callable $stamp): void
    {
        // Translatable attributes
        foreach ($element->attributes as $i18nAttribute) {
            if (!str_starts_with(strtolower($i18nAttribute->name), 'i18n-')) {
                continue;
            }

            $attributeName = substr($i18nAttribute->name, strlen('i18n-'));
            $attribute = $element->hasAttribute($attributeName);
            if (!$attribute) {
                throw new Exception("i18n attribute {$attributeName} not found in {$path}: " . $element->render());
            }

            $string = $this->_registerString($source, $path, HTML_Parser::entityDecode($attribute->value), $i18nAttribute, $attribute);
            $string->placeholders ??= [];
            $this->_prepareAnnotatedString($string, $path);

            $raw = "{$attributeName}={$attribute->value}|{$i18nAttribute->value}";
            $element->setAttribute($i18nAttribute->name, '@@' . $stamp($string, 'attribute', $raw));
            $attribute->value = $this->_annotateAttributeValue($attribute->value, $attribute->enclosing);
        }

        // Translatable content
        $i18nAttribute = $element->hasAttribute('i18n');
        if ($i18nAttribute) {
            $raw = $element->render();

            // Read the string from a copy, because reading it replaces the i18nPlaceholder elements with their names
            $copy = array_find(
                HTML_Parser::parse(
                    $raw,
                    strict: $this->supportAngularExpressions,
                    preserveAttributesWhitespace: false,
                    supportAngularExpressions: $this->supportAngularExpressions
                )->children,
                fn($node) => $node instanceof HTML_Parser_Element
            );
            if (!$copy instanceof HTML_Parser_Element) {
                throw new Exception("Unable to read the i18n element at {$path}: {$raw}");
            }

            $copy->walk(
                function ($child) {
                    if ($child instanceof HTML_Parser_Comment) {
                        $child->remove();
                    }
                }
            );
            $placeholders = $this->_processChildPlaceholders($copy);

            $string = $this->_registerString($source, $path, $this->_elementContent($copy, $path), $i18nAttribute, $element);
            $string->placeholders = $placeholders;
            $this->_prepareAnnotatedString($string, $path);

            $id = $stamp($string, 'element', $raw);
            $element->setAttribute($i18nAttribute->name, "@@{$id}");
            $element->removeAttribute('i18nWhitespaces');

            // Leave the content as Angular has to see it
            $element->walk(
                function ($child) use ($string, $id) {
                    if ($child instanceof HTML_Parser_Comment) {
                        $child->remove();
                    } elseif ($child instanceof HTML_Parser_Element) {
                        foreach (['i18nPlaceholder', 'i18nContent', 'i18nContentIgnore', 'i18nIgnore', 'noI18n'] as $attr) {
                            $child->removeAttribute($attr);
                        }
                        foreach ($child->attributes as $attr) {
                            if ($attr->value !== null && !str_starts_with(strtolower($attr->name), 'i18n')) {
                                $attr->value = $this->_annotateAttributeValue($attr->value, $attr->enclosing, false);
                            }
                        }
                    } elseif ($child instanceof HTML_Parser_Text) {
                        $child->setContent($this->_annotateText($child->render(), $string, $id));
                    }
                }
            );
        }

        foreach ($element->children as $child) {
            if ($child instanceof HTML_Parser_Element) {
                $this->_annotateNode($child, $source, $path, $stamp);
            }
        }
    }

    /**
     * Completes a string found by annotate() the same way getStrings() completes it
     */
    protected function _prepareAnnotatedString(LString $string, ?string $path): void {}

    /**
     * Rewrites a text node inside an annotated i18n element
     *
     * @param string $id Custom id stamped on the element
     */
    protected function _annotateText(string $text, LString $string, string $id): string
    {
        return $text;
    }

    /**
     * Rewrites the value of an attribute that is translated, or that belongs to an element inside an annotated one
     *
     * @param bool $translatable True when the attribute itself is translated (i18n-attr)
     */
    protected function _annotateAttributeValue(string $value, string $enclosing, bool $translatable = true): string
    {
        return $value;
    }

    private function _registerString(
        string $source,
        ?string $path,
        string $text,
        HTML_Parser_Attribute $i18nAttribute,
        HTML_Parser_Attribute|HTML_Parser_Element $container
    ): LString {
        $string = new LString();
        $string->text = $string->id = str_replace('&ngsp;', ' ', $text);
        $string->file = $path;
        $string->offset = $container->offset;
        $string->line = $string->offset == 0 || !$source ? 0 : substr_count($source, "\n", 0, $string->offset) + 1;
        $string->domainName = $this->defaultDomain;
        $string->comments = $i18nAttribute->value;

        // https://angular.io/guide/i18n#help-the-translator-with-a-description-and-meaning
        if ($string->comments) {
            if (str_contains($string->comments, '|')) {
                [$string->context, $string->comments] = explode('|', $string->comments, 2);
            }
            if (str_contains($string->comments, '@@')) {
                [$string->comments, $string->id] = explode('@@', $string->comments, 2);
            }
            if (str_contains($string->comments, '##')) {
                [$string->comments, $string->domainName] = explode('##', $string->comments, 2);
            }
        }

        if ($this->autoDetectIcuPatterns) {
            $pattern = new Pattern($string->text);
            if ($pattern->hasPlaceholders()) {
                $string->isICU = true;
            }
        }

        $string->raw = $i18nAttribute;

        $this->_foundStrings[] = $string;

        return $string;
    }

    private function _processChildPlaceholders(HTML_Parser_Element $element): array
    {
        $placeholders = [];

        foreach ($element->children as $child) {
            if (!($child instanceof HTML_Parser_Element)) {
                continue;
            }

            $i18nPlaceholderAttr = $child->hasAttribute('i18nPlaceholder');
            $i18nContentAttr = $child->hasAttribute('i18nContent');
            $i18nContentIgnoreAttr = $child->hasAttribute('i18nContentIgnore');

            $placeholders += $this->_processChildPlaceholders($child);

            if ($i18nPlaceholderAttr) { // Replace the whole element with its placeholder
                $i18nPlaceholderAttr->remove();

                if ($i18nContentAttr) {
                    $i18nContentAttr->remove();
                    $i18nContentIgnoreAttr?->remove();

                    // Only include the start of the parent element
                    $copy = clone $child;
                    $copy->children = [];
                    $copy->autoClosed = true;
                    $placeholders[$i18nPlaceholderAttr->value] = substr($copy->render(), 0, -2) . '>';

                    if ($i18nPlaceholderAttr->value[0] == '<') {
                        $realTag = $child->tag;
                        $child->tag = substr($i18nPlaceholderAttr->value, 1, -1);
                        $placeholders["</{$child->tag}>"] = "</{$realTag}>";
                    }

                    // Ignore certain child elements and fold them into the placeholder
                    foreach ($child->children as $subChild) {
                        if ($subChild instanceof HTML_Parser_Element && $subChild->hasAttribute('i18nIgnore')) {
                            $subChild->removeAttribute('i18nIgnore');
                            $placeholders[$i18nPlaceholderAttr->value] .= $subChild->render();
                            $subChild->remove();
                        }
                    }

                    // Drop the remaining attributes so they are not processed
                    foreach ($child->attributes as $attr) {
                        $attr->remove();
                    }
                } else {
                    $child->replaceWith(new HTML_Parser_Text($i18nPlaceholderAttr->value));
                    $placeholders[$i18nPlaceholderAttr->value] = $child->render();
                }
            }
        }

        return $placeholders;
    }

    private function _checkIfMissingI18nAttribute(HTML_Parser_Element $element): bool
    {
        $suspicious = !in_array($element->tag, ['script', 'style', 'noscript', 'mat-icon', 'title']);

        foreach ($element->children as $child) {
            if ($child instanceof HTML_Parser_Element) {
                $suspicious = false;
                break;
            }
        }

        if ($suspicious && $element->hasAttribute('noI18n')) {
            $suspicious = false;
        }

        if ($suspicious) {
            $element->walkParents(
                function ($parent) use (&$suspicious) {
                    if ($parent instanceof HTML_Parser_Element && ($parent->hasAttribute('i18n') || $parent->hasAttribute('noI18n'))) {
                        $suspicious = false;
                    }
                }
            );
        }

        if ($suspicious) {
            $innerText = HTML_Parser::entityDecode($element->innerText());
            $innerText = str_replace('&ngsp;', ' ', $innerText);
            $innerText = mb_trim($innerText);
            $innerText = trim($innerText, '()=');
            $innerText = str_replace('≈', '', $innerText);
            $innerText = mb_trim($innerText);

            if (!$innerText) { // Sin texto
                $suspicious = false;
            } elseif (str_starts_with($innerText, '{{')
                || str_starts_with($innerText, '@if')
                || str_starts_with($innerText, '@switch')
                || str_starts_with($innerText, '@for')
                || str_starts_with($innerText, '${')) { // Angular expression
                $suspicious = false;
            } elseif (!preg_match('/[a-zA-Z]/', $innerText)) { // Sin texto
                $suspicious = false;
            } elseif (preg_match('/^&([a-z\d]+|#\d+|#x[a-f\d]+);$/', $innerText)) { // Entidad HTML
                $suspicious = false;
            }
        }


        return $suspicious;
    }

    /** @inheritDoc */
    public function translate(string $content, callable $getTranslation, $context = null): string
    {
        return $this->_process($content, $context, $getTranslation);
    }
}
