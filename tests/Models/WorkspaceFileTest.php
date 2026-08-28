<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Models;

use Digitalygo\Envai\Models\WorkspaceFile\ConvertToPdfRequest;
use Digitalygo\Envai\Models\WorkspaceFile\WorkspaceFile;
use Digitalygo\Envai\Models\WorkspaceFile\WorkspaceFileEntry;
use Digitalygo\Envai\Models\WorkspaceFile\WorkspaceFileRequest;
use Digitalygo\Envai\Models\WorkspaceFile\WorkspaceFileType;
use PHPUnit\Framework\TestCase;

final class WorkspaceFileTest extends TestCase
{
    public function test_workspace_file_hydrates_file_with_content_and_preserves_extra(): void
    {
        $file = WorkspaceFile::fromArray([
            'path' => 'wiki/guide.md',
            'name' => 'guide.md',
            'type' => 'file',
            'size' => 2048,
            'modified_at' => '2026-08-17T14:01:00+00:00',
            'mime_type' => 'text/markdown',
            'content' => '# Guide',
            'checksum' => 'sha256:abc',
        ]);

        $this->assertSame('wiki/guide.md', $file->path);
        $this->assertSame(WorkspaceFileType::File, $file->type);
        $this->assertSame('file', $file->type->value);
        $this->assertSame(2048, $file->size);
        $this->assertSame('# Guide', $file->content);
        $this->assertNull($file->entries);
        $this->assertSame(['checksum' => 'sha256:abc'], $file->extra);
    }

    public function test_workspace_file_hydrates_directory_with_entries(): void
    {
        $directory = WorkspaceFile::fromArray([
            'path' => 'wiki',
            'name' => 'wiki',
            'type' => 'directory',
            'size' => 0,
            'modified_at' => null,
            'mime_type' => null,
            'entries' => [
                [
                    'path' => 'wiki/alpha.md',
                    'name' => 'alpha.md',
                    'type' => 'file',
                    'size' => 128,
                    'modified_at' => '2026-08-17T14:01:00+00:00',
                    'mime_type' => 'text/markdown',
                ],
                [
                    'path' => 'wiki/sub',
                    'name' => 'sub',
                    'type' => 'directory',
                    'size' => 0,
                    'modified_at' => null,
                    'mime_type' => null,
                ],
            ],
        ]);

        $this->assertSame(WorkspaceFileType::Directory, $directory->type);
        $this->assertNull($directory->content);
        $this->assertCount(2, $directory->entries);
        $this->assertInstanceOf(WorkspaceFileEntry::class, $directory->entries[0]);
        $this->assertSame('alpha.md', $directory->entries[0]->name);
        $this->assertSame(WorkspaceFileType::Directory, $directory->entries[1]->type);
    }

    public function test_workspace_file_throws_on_unknown_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WorkspaceFile payload field "type" has unknown value "symlink"');

        WorkspaceFile::fromArray([
            'path' => 'wiki',
            'name' => 'wiki',
            'type' => 'symlink',
            'size' => 0,
            'modified_at' => null,
            'mime_type' => null,
        ]);
    }

    public function test_workspace_file_round_trips_with_nested_entries(): void
    {
        $payload = [
            'path' => 'wiki',
            'name' => 'wiki',
            'type' => 'directory',
            'size' => 0,
            'modified_at' => null,
            'mime_type' => null,
            'entries' => [
                [
                    'path' => 'wiki/alpha.md',
                    'name' => 'alpha.md',
                    'type' => 'file',
                    'size' => 128,
                    'modified_at' => null,
                    'mime_type' => 'text/markdown',
                ],
            ],
        ];

        $this->assertSame($payload, WorkspaceFile::fromArray($payload)->toArray());
    }

    public function test_workspace_file_request_serializes_path_and_content(): void
    {
        $request = new WorkspaceFileRequest('/wiki/guide.md', '# Guide');

        $this->assertSame([
            'path' => '/wiki/guide.md',
            'content' => '# Guide',
        ], $request->toArray());
    }

    public function test_convert_to_pdf_request_serializes_path(): void
    {
        $request = new ConvertToPdfRequest('/wiki/guide.md');

        $this->assertSame(['path' => '/wiki/guide.md'], $request->toArray());
    }
}
