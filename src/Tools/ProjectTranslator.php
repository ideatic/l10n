<?php

namespace ideatic\l10n\Tools;

use ideatic\l10n\Config;
use ideatic\l10n\Domain;
use ideatic\l10n\LString;
use ideatic\l10n\Project;
use ideatic\l10n\Translation\Provider\Projects;
use ideatic\l10n\Utils\IO;

class ProjectTranslator
{
    /** @var string[] Nombre de los dominios que se van a traducir, si no se especifican se traducirán todos */
    public $domains;

    public function translate(Config $config, string $projectName, string $locale, string $path = null): array
    {
        /** @var Project $project */
        $project = $config->projects->$projectName ?? null;

        if (!$project) {
            throw new \Exception("Project '{$projectName}' not found");
        }

        if (isset($path)) {
            $project = clone $project;
            $project->path = $path;
        }

        // Configurar proveedor del proyecto
        $extractor = new Extractor();
        $projectStringsProvider = $extractor->getProjectProvider($project);

        // Extraer cadenas del proyecto
        $projectDomains = array_column(Domain::generate($projectStringsProvider->getStrings()), null, 'name');

        // Configurar proveedor de traducciones
        $translator = new Projects($config);

        // Filtrar qué archivos hay que procesar
        $files = [];
        foreach ($projectDomains as $domain) {
            if ($this->domains && !in_array($domain->name, $this->domains)) {
                continue;
            }

            foreach ($domain->strings as $stringID => $stringLocations) {
                foreach ($stringLocations as $string) {
                    $files[$string->file] = $string->file;
                }
            }
        }

        // Traducir los archivos
        $updatedFiles = [];
        foreach ($files as $file) {
            $extension = IO::getExtension($file);

            $originalContent = $content = file_get_contents($file);

            if ($content === false || $content === null) {
                throw new \Exception("Unable to read '{$file}'");
            }

            // Procesar el archivo con todos los proveedores definidos
            foreach ($projectStringsProvider->extensions()[$extension] as $format) {
                $content = $format->translate(
                    $content,
                    function (LString $string) use ($locale, $translator, $projectDomains): ?string {
                        if ($this->domains && !in_array($string->domainName, $this->domains)) {
                            return null; // No traducir esta cadena
                        }

                        return $translator->getTranslation($string, $locale, true);
                    },
                    $file
                );
            }

            if (strcmp($originalContent, $content) != 0) {
                $updatedFiles[$file] = $content;
            }
        }

        // Escribir archivos actualizados
        foreach ($updatedFiles as $path => $content) {
            if (file_put_contents($path, $content) === false) {
                throw new \Exception("Unable to write '{$path}'");
            }
        }

        return array_keys($updatedFiles);
    }
}