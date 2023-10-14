<?php

declare(strict_types=1);

namespace ideatic\l10n\CLI\Tools;

use ideatic\l10n\Catalog\Catalog;
use ideatic\l10n\Catalog\Loader\Loader;
use ideatic\l10n\Catalog\Serializer\Serializer;
use ideatic\l10n\CLI\Environment;
use ideatic\l10n\Config;
use ideatic\l10n\Domain;
use ideatic\l10n\DomainConfig;
use ideatic\l10n\Project;
use ideatic\l10n\Translation\Provider\Projects;
use ideatic\l10n\Utils\IO;
use ideatic\l10n\Utils\Locale;
use stdClass;

class Merger
{
  public static function run(Environment $environment): void
  {
    // Obtener dominios disponibles
    $domains = Extractor::scanDomains($environment);

    if (isset($environment->params['domain'])) {
      $domains = array_filter(
          $domains,
          function (Domain $domain) use ($environment) {
            return in_array($domain->name, explode(',', $environment->params['domain']));
          }
      );
    }

    /** @var DomainConfig[] $domainsConfig */
    $domainsConfig = get_object_vars($environment->config->tools->merge ?? new stdClass());

    foreach ($domains as $domain) {
      $domainConfig = $domainsConfig[$domain->name] ?? null;

      echo "\n\n#### {$domain->name} domain\n\n";

      $domainLocales = $domainConfig->locales ?? $environment->config->locales;
      if (isset($environment->params['lang'])) {
        $domainLocales = explode(',', $environment->params['lang']);
      }
      if (isset($environment->params['locale'])) {
        $domainLocales = explode(',', $environment->params['locale']);
      }

      foreach ($domainLocales as $locale) {
        if ($locale == $environment->config->sourceLocale) {
          continue;
        }

        echo "\n\t## " . Locale::getName($locale) . " ({$locale})\n";

        // Obtener catálogo con traducciones actualizado
        $translationsCatalog = self::_getCatalog($domain, $locale, $environment->config);

        if ($translationsCatalog) {
          $domain->translator = new \ideatic\l10n\Translation\Provider\Catalog($translationsCatalog);
        } else { // Usar traducciones ya existentes en otros proyectos
          $domain->translator = $translator = new Projects($environment->config);
          if (!$translator->loadCatalog($domain, $locale)) {
            echo "\tUnable to retrieve or merge translations\n";
            continue;
          }
        }

        // Mostrar resumen
        $notFound = [];
        if ($translationsCatalog) {
          foreach ($domain->strings as $stringID => $stringLocations) {
            if ($translationsCatalog->getTranslation($stringLocations[0]) === null) {
              $notFound[] = $stringID;
            }
          }

          if (!empty($notFound)) {
            echo "\t\t" . strtr(
                    '%count% strings not found: %strings%',
                    [
                        '%count%' => number_format(count($notFound)),
                        '%strings%' => implode(', ', array_slice($notFound, 0, 5)) . '...'
                    ]
                ) . "\n";
          }
        }

        // Guardar traducciones en los proyectos que lo requieran
        /** @var Project $project */
        foreach ($environment->config->projects as $project) {
          if (!isset($project->translations->path)) {
            continue;
          }

          // Guardar cadenas
          $destinyFormat = $project->translations->format;
          $serializer = Serializer::factory($destinyFormat);
          $serializer->locale = $locale;

          $fileName = strtr(
              $project->translations->template,
              [
                  '{domain}' => $domain->name,
                  '{locale}' => $locale,
                  '{format}' => $destinyFormat,
              ]
          );

          if (!is_dir($project->translations->path)) {
            mkdir($project->translations->path);
          }

          $catalogPath = IO::combinePaths($project->translations->path, $fileName);
          file_put_contents($catalogPath, $serializer->generate([$domain], $project));

          echo "\t\t" . strtr(
                  'Written %path%',
                  [
                      '%path%' => $catalogPath
                  ]
              ) . "\n";
        }
      }
    }
  }

  private static function _getCatalog(Domain $domain, string $locale, Config|stdClass $config): ?Catalog
  {
    /** @var DomainConfig $domainConfig */
    $domainConfig = get_object_vars($config->tools->merge ?? new stdClass())[$domain->name] ?? null;

    if (isset($domainConfig->source)) { // Descargar
      $domainOrigin = str_replace('{locale}', $locale, $domainConfig->source ?? '');

      echo "\t\tDownloading {$domainOrigin}...\n";
      $content = @file_get_contents($domainOrigin);
    } elseif (isset($domainConfig->script)) { // Ejecutar comando
      exec($domainConfig->script, $content);
    }

    if (isset($content)) {
      if (empty($content)) {
        echo "\033[31m\t\tEmpty response for domain '{$domain->name}' locale {$locale}\033[0m\n";
        // throw new \Exception("\tEmpty response for domain '{$domain->name}' locale {$locale}\n");
        return null;
      }

      // Procesar archivo recibido
      $loader = Loader::factory($domain->format ?? 'po');
      return $loader->load($content, $locale);
    } else {
      return null;
    }
  }
}