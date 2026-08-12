<?php

declare(strict_types=1);

namespace ideatic\l10n\String;

use ideatic\l10n\LString;

/**
 * Provider of translatable strings
 */
abstract class Provider
{
  /**
   * Gets the translatable strings found by this provider
   * @return LString[]
   */
  public abstract function getStrings(): array;
}