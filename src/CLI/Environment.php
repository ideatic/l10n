<?php

namespace ideatic\l10n\CLI;

use ideatic\l10n\Config;

class Environment
{
    /** @var Config */
    public $config;

    /** @var string */
    public $executableName;

    /** @var string */
    public $method;

    /** @var array */
    public $input;

    /** @var array */
    public $params;

    /** @var string */
    public $directory;

    public function __construct()
    {
        $this->directory = getcwd();

        $this->config = new Config();
    }

    public function parseParams(array $argv = null)
    {
        $argv = $argv ?? $_SERVER['argv'];

        $this->executableName = basename($argv[0]);
        $this->method = null;
        $this->input = [];
        $this->params = [];

        foreach ($argv as $param) {
            if (substr($param, 0, 2) == '--') {
                $param = substr($param, 2);
                if (strpos($param, '=')) {
                    [$key, $value] = explode('=', $param, 2);
                } else {
                    $key = $param;
                    $value = null;
                }

                $this->params[$key] = $value;
            } elseif (!isset($this->method)) {
                $this->method = $param;
            } else {
                $this->input[] = $param;
            }
        }
    }

    public function getParam(string $name, $default = null)
    {
        return $this->params[$name] ?? $default;
    }
}