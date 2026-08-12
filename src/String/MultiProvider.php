<?php

declare(strict_types=1);

namespace ideatic\l10n\String;

/**
 * Provider that looks for translatable strings by combining other providers
 */
class MultiProvider extends Provider
{
  /** @var Provider[] */
  private array $_providers = [];

  public function add(Provider $provider): void
  {
    $this->_providers[] = $provider;
  }

  /** @inheritDoc */
  public function getStrings(): array
  {
    $found = [];

    foreach ($this->_providers as $provider) {
      foreach ($provider->getStrings() as $string) {
        $found[] = $string;
      }
    }

    return $found;
  }
}