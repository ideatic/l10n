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
    new Parser()->parse($pattern, $this);
  }

  public function hasPlaceholders(): bool
  {
      return array_any($this->nodes, static fn($node) => $node instanceof Placeholder);
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

  /**
   * Representa el mensaje omitiendo el nombre de sus argumentos.
   *
   * Sirve para comparar dos patrones que solo se diferencian en ese nombre: en las plantillas de Angular el nombre
   * es la expresión que produce el valor ({user.points}, {league.user.points}...) y el extractor lo normaliza, pero
   * el mensaje que recibe el traductor es exactamente el mismo.
   */
  public function signature(): string
  {
    $parts = [];

    foreach ($this->nodes as $node) {
      if (is_string($node)) {
        $parts[] = $node;
        continue;
      }

      $branches = [];
      foreach (is_array($node->content) ? $node->content : [] as $category => $branch) {
        $branches[] = "{$category}{" . $branch->signature() . '}';
      }

      $content = $branches ? implode(' ', $branches) : (is_string($node->content) ? $node->content : '');

      $parts[] = '{@' . ($node->type !== '' ? ",{$node->type}" : '') . ($content !== '' ? ",{$content}" : '') . '}';
    }

    return implode('', $parts);
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
