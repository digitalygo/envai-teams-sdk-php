<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\State;

use Digitalygo\Envai\Exceptions\FileException;
use Digitalygo\Envai\Tools\State\FileClientToolCallStore;
use Digitalygo\Envai\Tools\State\ToolCallIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileStoreCorruptionSecurityTest extends TestCase
{
    #[DataProvider('corruptContents')]
    public function test_corrupt_existing_state_never_becomes_a_fresh_store(string $contents): void
    {
        $path = sys_get_temp_dir().'/envai-store-security-'.bin2hex(random_bytes(8));
        file_put_contents($path, $contents);

        try {
            $this->expectException(FileException::class);
            (new FileClientToolCallStore($path))->begin(new ToolCallIdentity('cht_scope', 'tc_scope', 'echo'));
        } finally {
            $this->assertSame($contents, file_get_contents($path));
            @unlink($path);
            @unlink($path.'.lock');
        }
    }

    /** @return array<string, array{string}> */
    public static function corruptContents(): array
    {
        return [
            'empty' => [''],
            'whitespace' => [" \n\t"],
            'truncated' => ['{"format":1,"calls":'],
        ];
    }
}
