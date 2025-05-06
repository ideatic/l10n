<?php

declare(strict_types=1);

namespace ideatic\l10n\Utils;

abstract class Utils
{
  public static function set(object $object, array $array): object
  {
    foreach ($array as $key => $value) {
      if (str_contains($key, '(') && preg_match('/\(\)$/', $key)) {
        $callback = [$object, str_replace('()', '', $key)];
        if (is_array($value)) {
          call_user_func($callback, $value);
        } else {
          /** @phpstan-ignore-next-line */
          call_user_func_array($callback, $value);
        }
      } else {
        $object->$key = $value;
      }
    }

    return $object;
  }
    /**
     * Wrap an item in an array if it is not already an array
     */
    public static function wrapArray(mixed $item): array
    {
        return is_array($item) ? $item : [$item];
    }

  /**
   * Renders an abbreviated version of the backtrace
   */
  public static function backtraceSmall(?array $trace = null, bool $rtl = false): string
  {
    if (!$trace) {
      $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
      array_shift($trace);//Eliminar llamada a backtrace_small
    }

    $output = [];
    foreach ($trace as $step) {
      //Get data from the current step
      foreach (['class', 'type', 'function', 'file', 'line', 'args'] as $param) {
        $$param = $step[$param] ?? '';
      }

      /** @phpstan-ignore-next-line */
      $output[] = "{$class}{$type}{$function}({$line})";
    }

    if ($rtl) {
      return implode(' → ', array_reverse($output));
    } else {
      return implode(' ← ', $output);
    }
  }

}
