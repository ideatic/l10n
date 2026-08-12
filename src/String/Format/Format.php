<?php

declare(strict_types=1);

namespace ideatic\l10n\String\Format;

use ideatic\l10n\LString;

/**
 * Base class every localization string provider must implement
 */
abstract class Format
{
  public string $defaultDomain = 'app';

  /**
   * Gets the translation strings available in the given content
   *
   * @return array<LString>
   */
  public abstract function getStrings(string $content, mixed $context = null): array;

  /**
   * Translates the strings of the given domain found in the content
   *
   * @param callable(LString $string): ?string $getTranslation Receives an LString. Returns a string or NULL
   * @param mixed|string                       $context
   */
  public abstract function translate(string $content, callable $getTranslation, mixed $context = null): string;
}