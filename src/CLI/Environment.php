<?php

declare(strict_types=1);

namespace ideatic\l10n\CLI;

use ideatic\l10n\Config;

class Environment
{
    public Config $config;
    public string $executableName;
    public ?string $method;
    public array $input;
    public array $params;
    public string $directory;

    public function __construct()
    {
        $this->directory = getcwd();
        $this->config = new Config();
    }

    public function parseParams(array $argv = null): void
    {
        $argv = $argv ?? $_SERVER['argv'];

        $this->executableName = basename($argv[0]);
        $this->method = null;
        $this->input = [];
        $this->params = [];

        foreach (array_slice($argv, 1) as $param) {
            if (str_starts_with($param, '--')) {
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

    public function getParam(string $name, mixed $default = null): mixed
    {
        return $this->params[$name] ?? $default;
    }
}