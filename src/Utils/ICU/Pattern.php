<?php

namespace ideatic\l10n\Utils\ICU;

use MessageFormatter;

class Pattern
{

    /**
     * @var string[]|Placeholder[]
     */
    public $nodes = [];

    public function __construct(string $pattern)
    {
        (new Parser())->parse($pattern, $this);
    }

    public function hasPlaceholders(): bool
    {
        foreach ($this->nodes as $node) {
            if ($node instanceof Placeholder) {
                return true;
            }
        }

        return false;
    }

    public function textNodes()
    {
        $textNodes = [];
        if ($this->nodes) {
            foreach ($this->nodes as &$node) {
                if ($node instanceof Placeholder) {
                    if (is_array($node->content)) {
                        foreach ($node->content as $subIcuMessage) {
                            foreach ($subIcuMessage->textNodes() as &$textNode) {
                                $textNodes[] = &$textNode;
                            }
                        }
                    }
                } elseif (is_string($node)) {
                    $textNodes[] = &$node;
                }
            }
        }
        return $textNodes;
    }

    public function format($locale, $args = [])
    {
        if (!class_exists(MessageFormatter::class, false)) {
            throw new \InvalidArgumentException('MessageFormatter class not found, please check PHP Intl extension is available');
        }

        $fmt = new MessageFormatter($locale, $this->render());
        if (!$fmt) {
            throw new \InvalidArgumentException('Unable to parse ICU message: ' . $this->render());
        } elseif ($fmt->getErrorCode() != \U_ZERO_ERROR) {
            throw new \InvalidArgumentException('Invalid ICU message: ' . $fmt->getErrorMessage());
        }
        return $fmt->format($args);
    }

    public function render(bool $prettyPrint = true)
    {
        $strings = [];
        foreach ($this->nodes as $node) {
            if (is_string($node)) {
                $strings[] = $node;
            } else {
                $strings[] = $node->render($prettyPrint);
            }
        }

        return implode('', $strings);
    }
}