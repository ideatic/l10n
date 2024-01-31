<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools;

use Exception;
use ideatic\l10n\Config;
use ideatic\l10n\Domain;
use ideatic\l10n\LString;
use ideatic\l10n\Project;
use ideatic\l10n\Translation\Provider;
use ideatic\l10n\Translation\Provider\Projects;
use ideatic\l10n\Utils\IO;

class ProjectTranslator
{
  /** @var string[] Nombre de los dominios que se van a traducir, si no se especifican se traducirán todos */
  public array $domains;

  public function translateDir(Config $config, string $projectName, string $locale, string $path = null): array
  {
    /** @var Project|\stdClass|null $project */
    $project = $config->projects->$projectName ?? null;

    if (!$project) {
      throw new Exception("Project '{$projectName}' not found");
    }

    if (isset($path)) {
      $project = clone $project;
      $project->path = $path;
    }

    // Configurar proveedor del proyecto
    $projectStringsProvider = Extractor::getProjectProvider($project);

    // Extraer cadenas del proyecto
    $projectDomains = array_column(Domain::generate($projectStringsProvider->getStrings()), null, 'name');

    // Configurar proveedor de traducciones
    $translator = new Projects($config);

    // Listar archivos a traducir entre todos los encontrados
    $files = [];
    foreach ($projectDomains as $domain) {
      if ($this->domains && !in_array($domain->name, $this->domains)) {
        continue;
      }

      foreach ($domain->strings as $stringLocations) {
        foreach ($stringLocations as $string) {
          $files[$string->file] = $string->file;
        }
      }
    }

    // Traducir los archivos
    $updatedFiles = [];
    foreach ($files as $file) {
      $originalContent = $content = IO::read($file);
      $content = $this->translateFile($content, $file, $locale, $config, $projectName, $translator);

      if (strcmp($originalContent, $content) != 0) {
        $updatedFiles[$file] = $content;
      }
    }

    // Escribir archivos actualizados
    foreach ($updatedFiles as $path => $content) {
      if (file_put_contents($path, $content) === false) {
        throw new Exception("Unable to write '{$path}'");
      }
    }

    return array_keys($updatedFiles);
  }

  public function translateFile(
      string $content,
      string $file,
      string $locale,
      Config $config,
      string $projectName,
      ?Provider $translatorProvider = null
  ): string {
    // Buscar proyecto
    /** @var Project|null $project */
    $project = $config->projects->$projectName ?? null;

    if (!$project) {
      throw new Exception("Project '{$projectName}' not found");
    }

    // Crear traductor si no se indica
    if (!$translatorProvider) {
      $translatorProvider = new Projects($config);
    }

    // Procesar el archivo con todos los proveedores definidos para su extensión
    $extension = IO::getExtension($file);
    foreach (Extractor::getProjectProvider($project)->extensions()[$extension] as $format) {
      $content = $format->translate(
          $content,
          function (LString $string) use ($locale, $translatorProvider): ?string {
            if ($this->domains && !in_array($string->domainName, $this->domains)) {
              return null; // No traducir esta cadena
            }

            return $translatorProvider->getTranslation($string, $locale, true);
          },
          $file
      );
    }

    return $content;
  }
}