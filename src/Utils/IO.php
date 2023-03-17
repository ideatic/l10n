<?php

declare(strict_types=1);

namespace ideatic\l10n\Utils;

use Exception;
use Generator;

abstract class IO
{

    /**
     * Obtiene la extensión del archivo o directorio representado por la ruta indicada
     */
    public static function getExtension(string $path): string
    {
        return pathinfo($path, PATHINFO_EXTENSION);
    }

    /**
     * Combina dos o más rutas en una sola:
     *
     * a, b    -> a/b
     * a/, /b  -> a/b
     * a/, b   -> a/b
     * a, b/   -> a/b/
     * ,b/     -> b/
     * @return string
     */
    public static function combinePaths()
    {
        $separator = DIRECTORY_SEPARATOR;
        $p = '';
        foreach (func_get_args() as $arg) {
            $end = str_ends_with($p, $separator);
            $start = empty($arg) ? false : $arg[0] == $separator;
            if ($end && $start) {
                $p .= substr($arg, 1);
            } elseif (empty($p) || $end || $start) {
                $p .= $arg;
            } else {
                $p .= $separator . $arg;
            }
        }
        return $p;
    }

    /**
     * Obtiene archivos y directorios del sistema de archivos
     */
    public static function getFiles(string $path, int $depth, array $excludePaths): Generator
    {
        // Recorrer directorio
        $dirh = opendir($path);
        if ($dirh === false) {
            throw new Exception("Unable to opendir '{$path}'");
        }

        $separator = DIRECTORY_SEPARATOR;
        $path = rtrim($path, $separator);

        while (($file = readdir($dirh)) !== false) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            $fullPath = $path . $separator . $file;

            if (is_dir($fullPath)) {  // Escanear directorio
                $scanDir = $depth != 0;

                if ($scanDir && !empty($excludePaths) && in_array($fullPath, $excludePaths)) {
                    $scanDir = false;
                }

                if ($scanDir) { // Recorrer los directorios hijos
                    foreach (self::getFiles($fullPath, $depth > 0 ? $depth - 1 : -1, $excludePaths) as $child) {
                        yield $child;
                    }
                }
            } elseif (!in_array($fullPath, $excludePaths)) {
                yield $fullPath;
            }
        }

        closedir($dirh);
    }

    public static function normalizePath(string $path): string
    {
        return str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
    }
}