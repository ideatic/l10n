<?php

declare(strict_types=1);

namespace ideatic\l10n\Utils\ICU;

use Exception;
use ideatic\l10n\Utils\Str;

class Parser
{
  private int $_position;
  private int $_length;

  private bool $_throwExpressionEnd = true;

  public function parse(string $icuPattern, Pattern $destiny, int $beginAt = 0): void
  {
    $this->_position = $beginAt;
    $this->_length = strlen($icuPattern);

    $currentTextNode = '';
    while ($this->_position < $this->_length) {
      $char = $icuPattern[$this->_position];

      if ($char == '{') { // Comienzo de expresión
        if (($icuPattern[$this->_position + 1] ?? '') == '{') { // Comienzo de expresión Angular {{
          $currentTextNode .= $this->_readUntil($icuPattern, '}}') . '}}';
          $this->_position += 2;
        } else {
          if ($currentTextNode) {
            $destiny->nodes[] = $currentTextNode;
            $currentTextNode = '';
          }

          $destiny->nodes[] = $this->_parseExpression($icuPattern, $destiny);
        }
      } elseif ($char == '}') {
        if ($this->_throwExpressionEnd) {
          throw new Exception("Unexpected pattern end at position {$this->_position} in '{$icuPattern}'");
        } else {
          break;
        }
      } else {
        $currentTextNode .= $char;
        $this->_position++;
      }
    }

    if ($currentTextNode) {
      $destiny->nodes[] = $currentTextNode;
    }
  }

  private function _readUntil(string $string, string|callable $stopFn): string
  {
    $isString = is_string($stopFn);

    $start = $this->_position;
    while ($this->_position < $this->_length) {
      if ($isString
          ? $string[$this->_position] == $stopFn[0] && strcmp(substr($string, $this->_position, strlen($stopFn)), $stopFn) == 0
          : call_user_func($stopFn, $string[$this->_position], $this->_position)) {
        break;
      }
      $this->_position++;
    }

    return substr($string, $start, $this->_position - $start);
  }

  private function _parseExpression(string $patternStr, Pattern $pattern): Placeholder
  {
    // Saltar el { actual
    $this->_position++;

    $placeholder = new Placeholder();
    $placeholder->parent = $pattern;
    $rawName = $this->_readUntil(
        $patternStr,
        function ($char) {
          return $char == ',' || $char == '}';
        }
    );

    $placeholder->name = trim(str_replace("\xc2\xa0", ' ', $rawName));

    if ($this->_currentChar($patternStr) != '}') { // Leer tipo de placeholder
      $this->_position++; // Saltar coma actual
      $placeholder->type = trim(
          $this->_readUntil(
              $patternStr,
              function ($char) {
                return $char == ',' || $char == '}';
              }
          )
      );

      $valid_types = ['number', 'date', 'time', 'ordinal', 'duration', 'spellout', 'plural', 'selectordinal', 'select'];
      if (!in_array($placeholder->type, $valid_types)) {
        throw new Exception("Invalid pattern type '{$placeholder->type}' at position {$this->_position}  in '{$patternStr}'");
      }

      if ($this->_currentChar($patternStr) != '}') {
        $this->_position++; // Saltar coma actual
        $allowNestedMessagesIn = ['select', 'plural', 'selectordinal'];
        if (in_array($placeholder->type, $allowNestedMessagesIn)) {
          $placeholder->content = $this->_parseNestedExpressions($patternStr);
        } else {
          $placeholder->content = $this->_readUntil($patternStr, '}');
        }
      }
    }

    // Saltar el } actual
    $this->_position++;

    return $placeholder;
  }

  private function _currentChar(string $string): string|null
  {
    if ($this->_position < $this->_length) {
      return $string[$this->_position];
    } else {
      return null;
    }
  }

  private function _parseNestedExpressions(string $pattern): array
  {
    $result = [];

    do {
      $name = Str::trim($this->_readUntil($pattern, '{'));

      if (!$name) {
        throw new Exception("No name found for nested pattern at position {$this->_position} in '{$pattern}'");
      }

      $this->_position++;

      $subPattern = new Pattern('');

      $subPatternParser = new Parser();
      $subPatternParser->_throwExpressionEnd = false;
      $subPatternParser->parse($pattern, $subPattern, $this->_position);
      $this->_position = $subPatternParser->_position + 1;

      $result[$name] = $subPattern;

      $this->_readWhitespaces($pattern);
    } while ($this->_currentChar($pattern) != '}');


    return $result;
  }

  private function _readWhitespaces(string $string): string
  {
    return $this->_readUntil(
        $string,
        function ($char) {
          return strlen(Str::trim($char)) != 0;
        }
    );
  }
}