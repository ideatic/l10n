<?php

declare(strict_types=1);

namespace ideatic\l10n\CLI\Tools;

use ideatic\l10n\CLI\Environment;

class Analyzer
{
  public static function run(Environment $environment): void
  {
    $threshold = $environment->params['t'] ?? 90;

    // Obtener grupos
    $domains = Exporter::scanDomains($environment);

    foreach ($domains as $domain) {
      $ignore = [];
      $found = 0;
      foreach ($domain->strings as $stringID => $stringLocations) {
        if (isset($ignore[$stringID])) {
          continue;
        }

        $similar = [];

        foreach ($domain->strings as $otherStringID => $otherStringLocations) {
          if ($otherStringID != $stringID) {
            similar_text($stringID, $otherStringID, $percent);

            if ($percent > $threshold) {
              $similar[] = $otherStringID;

              $ignore[$stringID] = true;
              $ignore[$otherStringID] = true;
            }
          }
        }

        if (!empty($similar)) {
          $found++;
          echo "\tSIMILAR STRINGS\n\t\t{$stringID}\n\t\t" . implode("\n\t\t", $similar) . "\n\n";
        }
      }

      echo "\n## {$found} similar strings found for domain {$domain->name}\n\n";
    }
  }
}
