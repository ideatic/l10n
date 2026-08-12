<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog\Loader;

use ideatic\l10n\Catalog\Catalog;
use InvalidArgumentException;

/**
 * Reads a translation catalog from the contents of a file.
 *
 * Each subclass understands one on-disk format, and every one of them produces the same Catalog, so the rest of the
 * library never needs to know which format a project happens to use.
 */
abstract class Loader
{
  public static function factory(string $name): self
  {
    if ($name == 'po') {
      return new PO();
    } elseif ($name == 'json' || $name == 'json-extended') {
      return new JSON();
    } elseif ($name == 'php') {
      return new PHP();
    } else {
      throw new InvalidArgumentException("Invalid serializer format '{$name}'");
    }
  }

  /**
   * Reads a catalog of translatable strings from a file
   */
  public abstract function load(string $content, string $locale): Catalog;
}

