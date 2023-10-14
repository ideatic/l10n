<?php

declare(strict_types=1);

namespace ideatic\l10n\Utils\ICU;

use InvalidArgumentException;
use MessageFormatter;

use const U_ZERO_ERROR;

class Pattern
{
  /** @var array<string|Placeholder> */
  public array $nodes = [];

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

  /**
   * @return array<string>
   */
  public function textNodes(): array
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

  /**
   * @param array<string, mixed> $args
   */
  public function format(string $locale, array $args = []): string
  {
    if (!class_exists(MessageFormatter::class, false)) {
      throw new InvalidArgumentException('MessageFormatter class not found, please check PHP Intl extension is available');
    }

    $fmt = new MessageFormatter($locale, $this->render());
    if ($fmt->getErrorCode() != U_ZERO_ERROR) {
      throw new InvalidArgumentException('Invalid ICU message: ' . $fmt->getErrorMessage());
    }
    return $fmt->format($args);
  }

  public function render(bool $prettyPrint = true): string
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