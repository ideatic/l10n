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

    public static function read(string $path): string
    {
        $result = file_get_contents($path);

        if ($result === false) {
            throw new Exception("Unable to read file '{$path}'");
        }

        return $result;
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

            $isIgnored = in_array($fullPath, $excludePaths)
                || array_find($excludePaths, static fn(string $excludedPattern) => self::isGlobMatch($fullPath, $excludedPattern));

            if (is_dir($fullPath)) {  // Escanear directorio
                if ($depth != 0 && !$isIgnored) { // Recorrer los directorios hijos
                    foreach (self::getFiles($fullPath, $depth > 0 ? $depth - 1 : -1, $excludePaths) as $child) {
                        yield $child;
                    }
                }
            } elseif (!$isIgnored) {
                yield $fullPath;
            }
        }

        closedir($dirh);
    }

    public static function normalizePath(string $path): string
    {
        return str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
    }

    public static function isGlobMatch(string $string, string $pattern): bool
    {
        // Check if pattern contains **
        if (strpos($pattern, '**') === false) {
            // No **, use standard fnmatch
            return fnmatch($pattern, $string);
        }

        // The order of replacements is crucial to prevent `*` from interfering with `**`.
        $regex = preg_quote($pattern, '#');

        // To prevent the single-star replacement from affecting the double-star,
        // we use a temporary placeholder.
        $regex = str_replace('\*\*', '##DOUBLE_STAR##', $regex);

        // Convert single-star wildcard to match any character except a slash.
        $regex = str_replace('\*', '[^/]*', $regex);

        // Convert question mark wildcard to match a single character except a slash.
        $regex = str_replace('\?', '[^/]', $regex);

        // Restore the double-star to its regex equivalent, which matches anything.
        $regex = str_replace('##DOUBLE_STAR##', '.*', $regex);

        // Anchor the regex to match the entire string.
        $regex = "#^{$regex}\$#";

        return preg_match($regex, $string) === 1;
    }
}
