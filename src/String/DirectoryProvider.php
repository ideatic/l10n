<?php

namespace ideatic\l10n\String;

use ideatic\l10n\String\Format\Format;
use ideatic\l10n\Utils\IO;

/**
 * Proveedor encargado de buscar cadenas traducibles en un directorio
 */
class DirectoryProvider extends Provider
{
    private string $_path;

    /** @var Format[][] */
    private array $_formats = [];

    private array $_excludedPaths = [];

    public function __construct(string $path)
    {
        $this->_path = $path;
    }

    public function addFormat(string $extension, Format $format): void
    {
        foreach (explode(',', $extension) as $ext) {
            if (!isset($this->_formats[$ext])) {
                $this->_formats[$ext] = [];
            }

            $this->_formats[$ext][] = $format;
        }
    }

    /**
     * @return Format[][]
     */
    public function extensions(): array
    {
        return $this->_formats;
    }

    public function excludePath(string|array $path): void
    {
        if (is_array($path)) {
            $this->_excludedPaths = array_merge($this->_excludedPaths, $path);
        } else {
            $this->_excludedPaths[] = $path;
        }
    }

    /** @inheritDoc */
    public function getStrings(): array
    {
        $found = [];

        foreach (IO::getFiles($this->_path, -1, $this->_excludedPaths) as $file) {
            $extension = strtolower(IO::getExtension($file) ?: '');

            if (isset($this->_formats[$extension])) {
                foreach ($this->_formats[$extension] as $format) {
                    foreach ($format->getStrings(file_get_contents($file), $file) as $string) {
                        $found[] = $string;
                    }
                }
            }
        }

        return $found;
    }
}