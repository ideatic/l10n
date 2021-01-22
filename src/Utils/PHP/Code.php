<?php

namespace ideatic\l10n\Utils\PHP;

/**
 * Representa un fragmento de código PHP que se puede analizar, inspeccionar y
 * modificar en tiempo de ejecución
 */
class Code
{

    protected $_source;
    protected $_tokens;
    protected $_update;

    public function __construct(string $source)
    {
        $this->parse($source);
    }

    public function parse(string $source)
    {
        $this->_source = $source;
        $this->_tokens = null;
        $this->_update = false;
    }

    /**
     * Obtiene el código PHP representado por esta instancia
     * @return string
     */
    public function getCode()
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
     * @param string[]
     *
     * @return FunctionCall[]
     */
    public function getFunctionCalls(array $fnNames = null)
    {
        // Comprobación rápida de que hay llamadas a las funciones indicadas usando regex
        if (!empty($fnNames)) {
            if (!preg_match(
                '#(' . implode(
                    '|',
                    array_map(
                        function ($name) {
                            return preg_quote($name, '#');
                        },
                        $fnNames
                    )
                ) . ')\s*\(#S',
                $this->_source
            )) {
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

                            //Ajustar offset
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
     *
     * @param array $tokens
     *
     * @return array
     */
    public function tokens($tokens = null)
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

    private function _readBackwards($tokens, $pos, $valid)
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
        for ($i = $pos + 1, $l = count($tokens); $i < $l; $i++) {
            switch ($tokens[$i][0]) {
                case ',':
                    if ($level == 0) {
                        $arguments[] = $this->_tokensToString($tokens, $argumentStart, $i - 1);
                        $argumentStart = $i + 1;
                    }
                    break;

                case ')':
                case ']':
                    if ($level <= 0) {
                        if ($argumentStart != $i) {
                            $arguments[] = $this->_tokensToString($tokens, $argumentStart, $i - 1);
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
                    if ($i + 1 < $l && $tokens[$i + 1][0] != T_ENCAPSED_AND_WHITESPACE) { //Sólo aceptar como válidas llaves para cerrar y abrir bloques
                        $level--;
                    }


                    break;
            }
        }
        $pos = $i;
        return $arguments;
    }
}
