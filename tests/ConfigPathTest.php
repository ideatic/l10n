<?php

declare(strict_types=1);

use ideatic\l10n\Config;
use PHPUnit\Framework\TestCase;

/**
 * Build scripts often load l10n.json from another directory (e.g. `cd deploy && php build.php`), so the relative paths
 * of a project must start at the config file, never at the working directory.
 */
class ConfigPathTest extends TestCase
{
    private string $_directory;
    private string $_workingDirectory;

    protected function setUp(): void
    {
        $this->_directory = realpath(sys_get_temp_dir()) . '/l10n-config-' . uniqid();
        $this->_workingDirectory = getcwd() ?: '.';

        mkdir("{$this->_directory}/deploy/src", recursive: true);
        mkdir("{$this->_directory}/src");
        file_put_contents(
            "{$this->_directory}/l10n.json",
            json_encode(
                [
                    'name' => 'demo',
                    'sourceLocale' => 'en',
                    'targetLocales' => ['es'],
                    'projects' => [
                        'root' => ['type' => 'php', 'path' => './', 'translations' => ['path' => 'translations']],
                        'src' => ['type' => 'php', 'path' => 'src'],
                        'absolute' => ['type' => 'php', 'path' => "{$this->_directory}/src"],
                    ],
                ],
                JSON_THROW_ON_ERROR
            )
        );
    }

    protected function tearDown(): void
    {
        chdir($this->_workingDirectory);
        exec('rm -rf ' . escapeshellarg($this->_directory));
    }

    public function testRelativePathsStartAtTheConfigFile(): void
    {
        chdir("{$this->_directory}/deploy"); // Has its own src folder, which must not be picked

        $config = new Config();
        $config->load("{$this->_directory}/l10n.json");

        $this->assertSame($this->_directory, $config->projects->root->path);
        $this->assertSame("{$this->_directory}/translations", $config->projects->root->translations[0]->path);
        $this->assertSame("{$this->_directory}/src", $config->projects->src->path);
        $this->assertSame("{$this->_directory}/src", $config->projects->absolute->path);
    }
}
