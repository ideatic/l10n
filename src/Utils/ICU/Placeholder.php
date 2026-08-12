<?php

declare(strict_types=1);

namespace ideatic\l10n\Utils\ICU;

/**
 * A single ICU argument inside a Pattern, such as "{name}" or "{count, plural, one {...} other {...}}".
 *
 * When the placeholder holds a nested message its content is the set of branches, keyed by category, and each branch
 * is a Pattern of its own.
 */
class Placeholder
{
  public string $name = '';

  /** Placeholder type (number, date, plural, select...). Empty when the placeholder declares none, e.g. "{name}" */
  public string $type = '';

  /** @var string|array<Pattern> Content of the placeholder: plain text, or the branches of a nested message */
  public string|array $content = '';

  public Pattern $parent;

  public function render(bool $prettyPrint = true): string
  {
    $parts = [$this->name];
    if ($this->type) {
      $parts[] = $this->type;
    }

    if ($this->content) {
      $parts[] = $this->renderContent($prettyPrint);
    }

    return '{' . implode(', ', $parts) . '}';
  }

  public function renderContent(bool $prettyPrint = true): ?string
  {
    if ($this->content) {
      $parts = [];
      if (is_string($this->content)) {
        $parts[] = $this->content;
      } else {
        $nested = [];
        foreach ($this->content as $name => $pattern) {
          $subPattern = "{$name} {{$pattern->render()}}";

          if ($prettyPrint) {
            $subPattern = str_replace("\t", "\t\t", $subPattern);
          }

          $nested[] = $subPattern;
        }

        if ($prettyPrint) {
          $parts[] = "\n\t" . implode("\n\t", $nested) . "\n";
        } else {
          $parts[] = implode(' ', $nested);
        }
      }
      return implode(' ', $parts);
    }
    return null;
  }
}