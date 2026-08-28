<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Definitions;

use PHPUnit\Framework\TestCase;

/**
 * Base test case providing an isolated temporary `envai/` layout for the
 * definitions compilers, cleaned up after every test.
 */
abstract class DefinitionsTestCase extends TestCase
{
    /**
     * @var list<string>
     */
    private array $tempDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirectories as $directory) {
            $this->removeDirectory($directory);
        }

        $this->tempDirectories = [];

        parent::tearDown();
    }

    /**
     * Creates a temporary envai/ layout with the given agents, teams and
     * optional tools.php content.
     *
     * @param  array<string, string>  $agents  File name without .md to content.
     * @param  array<string, string>  $teams  File name without .php to content.
     */
    protected function makeEnvaiLayout(array $agents = [], array $teams = [], ?string $tools = null): string
    {
        $directory = sys_get_temp_dir().'/atp-definitions-'.bin2hex(random_bytes(8));

        mkdir($directory.DIRECTORY_SEPARATOR.'agents', 0777, true);
        mkdir($directory.DIRECTORY_SEPARATOR.'teams', 0777, true);

        foreach ($agents as $name => $content) {
            file_put_contents($directory.DIRECTORY_SEPARATOR.'agents'.DIRECTORY_SEPARATOR.$name.'.md', $content);
        }

        foreach ($teams as $name => $content) {
            file_put_contents($directory.DIRECTORY_SEPARATOR.'teams'.DIRECTORY_SEPARATOR.$name.'.php', $content);
        }

        if ($tools !== null) {
            file_put_contents($directory.DIRECTORY_SEPARATOR.'tools.php', $tools);
        }

        $this->tempDirectories[] = $directory;

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }

    protected function agentMarkdown(string $body = "You are an agent.\n", string $frontmatter = ''): string
    {
        return "---\n".($frontmatter === '' ? "format: 1\nname: agent\nmode: subagent\n" : $frontmatter)."---\n".$body;
    }

    protected function teamPhp(string $localKey = 'team', string $body = ''): string
    {
        return <<<PHP
        <?php

        use Digitalygo\Envai\Definitions\Team;

        return Team::make('{$localKey}'){$body};

        PHP;
    }
}
