<?php

declare(strict_types=1);

namespace ideatic\l10n\Utils\PHP;

use InvalidArgumentException;

/**
 * Representa un fragmento de código PHP que se puede analizar, inspeccionar y
 * modificar en tiempo de ejecución
 */
class Code
{
  protected string $_source;
  protected ?array $_tokens;
  protected bool $_update;

  public function __construct(string $source)
  {
    $this->parse($source);
  }

  public function parse(string $source): void
  {
    $this->_source = $source;
    $this->_tokens = null;
    $this->_update = false;
  }

  /**
   * Obtiene el código PHP representado por esta instancia
   */
  public function getCode(): string
  {
    if ($this->_update) {
      $this->_source = $this->_tokensToString($this->_tokens, 0);
    }
    return $this->_source;
  }

  private function _tokensToString(array $tokens, int $start = 0, int $end = null): string
  {
    if (!isset($end)) {
      $end = count($tokens) - 1;
    }
    $end = min($end, count($tokens) - 1);

    $result = [];
    for ($i = $start; $i <= $end; $i++) {
      if (is_array($tokens[$i])) {
        $result[] = $tokens[$i][1];
      } else {
        $result[] = $tokens[$i];
      }
    }

    return implode('', $result);
  }

  /**
   * Analiza todas las llamadas a funciones que se producen en el fragmento de código PHP analizado
   *
   * @param array<string> $fnNames
   *
   * @return FunctionCall[]
   */
  public function getFunctionCalls(array $fnNames = null): array
  {
    // Comprobación rápida de que hay llamadas a las funciones indicadas usando regex
    if (!empty($fnNames)) {
      $pattern = '#(' . implode('|', array_map(fn($name) => preg_quote($name, '#'), $fnNames)) . ')\s*\(#S';
      if (!preg_match($pattern, $this->_source)) {
        return [];
      }
    }

    // Buscar llamadas a funciones mediante el análisis de los tokens PHP
    $calls = [];

    $tokens = $this->tokens();

    $lastNoWhitespaceToken = 0;
    $lastNoWhitespaceOffset = 0;
    $offset = 0;
    for ($i = 0, $l = count($tokens); $i < $l; $i++) {
      $token = $tokens[$i];
      switch ($token[0]) {
        case T_WHITESPACE:
          break;

        case '(':
          if ($tokens[$lastNoWhitespaceToken][0] == T_STRING) {
            if (empty($fnNames) || in_array($tokens[$lastNoWhitespaceToken][1], $fnNames)) {
              $startToken = $tokens[$lastNoWhitespaceToken];
              $call = new FunctionCall();
              $startPos = $i; // Comenzamos desde el primer ( que inicia la lista de argumentos
              // Obtener nombre del método y llamada completa
              $call->method = $startToken[1];
              $call->offset = $lastNoWhitespaceOffset;
              $call->line = $startToken[2] ??
                            substr_count($this->_source, "\n", 0, $call->offset) + 1;

              $fullCallStart = $this->_readBackwards(
                  $tokens,
                  $i -
                  1,
                  [
                      T_STRING,
                      T_VARIABLE,
                      T_OBJECT_OPERATOR,
                      T_WHITESPACE,
                      T_DOUBLE_COLON
                      /* , '(', ')' */
                  ]
              );
              $call->fullMethod = $this->_tokensToString($tokens, $fullCallStart, $startPos - 1);
              $call->fullMethodOffset =
                  $offset - strlen($call->fullMethod) + (strlen($call->fullMethod) - strlen(
                          ltrim($call->fullMethod)
                      )); // No contar espacios en blanco
              $call->fullMethod = ltrim($call->fullMethod);

              // Leer argumentos y generar código de la llamada completa
              $call->arguments = $this->_readFunctionArgs($tokens, $i);
              $call->code = ltrim(trim($this->_tokensToString($tokens, $lastNoWhitespaceToken, $i)), '()');

              $calls[] = $call;

              // Ajustar offset
              $offset += strlen($this->_tokensToString($tokens, $startPos, $i)) - strlen($token);
            }
          }

        default:
          $lastNoWhitespaceToken = $i;
          $lastNoWhitespaceOffset = $offset;
          break;
      }

      $offset += is_array($token) ? strlen($token[1]) : strlen($token);
    }

    return $calls;
  }

  /**
   * Obtiene o establece los tokens PHP representados por esta instancia
   */
  public function tokens(array $tokens = null): array
  {
    if (isset($tokens)) { // Setter
      if ($tokens != $this->_tokens) { //Comprobar si han cambiado
        $this->_tokens = $tokens;
        $this->_update = true;
      }
      return $tokens;
    } else { // Getter
      if (!isset($this->_tokens)) {
        $this->_tokens = token_get_all($this->_source);
      }
      return $this->_tokens;
    }
  }

  private function _readBackwards(array $tokens, int $pos, array $valid): int
  {
    for ($i = $pos; $i >= 0; $i--) {
      if (!in_array($tokens[$i][0], $valid)) {
        return $i + 1;
      }
    }
    return 0;
  }

  private function _readFunctionArgs(array $tokens, int &$pos): array
  {
    if ($tokens[$pos] != '(') {
      throw new InvalidArgumentException('The cursor must be situated at the beginning of a list');
    }
    $level = 0;
    $arguments = [];
    $argumentStart = $pos + 1;
    $argumentName = null;
    $inString = false;
    for ($i = $pos + 1, $l = count($tokens); $i < $l; $i++) {
      if ($inString && $tokens[$i][0] != '"') {
        continue;
      }

      switch ($tokens[$i][0]) {
        case ',': // Separador de argumentos
          if ($level == 0) {
            if (isset($argumentName)) {
              $arguments[$argumentName] = $this->_tokensToString($tokens, $argumentStart, $i - 1);
              $argumentName = null;
            } else {
              $arguments[] = $this->_tokensToString($tokens, $argumentStart, $i - 1);
            }
            $argumentStart = $i + 1;
          }
          break;

        case '"':
          $inString = !$inString;
          break;

        case ')':
        case ']':
          if ($level <= 0) {
            if ($argumentStart != $i) {
              if (isset($argumentName)) {
                $arguments[$argumentName] = $this->_tokensToString($tokens, $argumentStart, $i - 1);
              } else {
                $arguments[] = $this->_tokensToString($tokens, $argumentStart, $i - 1);
              }
            }
            break 2;
          } else {
            $level--;
          }
          break;

        case '(':
        case '{':
        case '[':
          $level++;
          break;

        case '}':
          if ($i + 1 < $l && $tokens[$i + 1][0] != T_ENCAPSED_AND_WHITESPACE) { // Solo aceptar como válidas llaves para cerrar y abrir bloques
            $level--;
          }
          break;

        case T_STRING: // Named argument
          if (($tokens[$i + 1][0] ?? '') == ':') {
            $argumentName = $tokens[$i][1];
            $argumentStart = $i + 2;
          }
          break;
      }
    }

    $pos = $i;
    return $arguments;
  }
}
