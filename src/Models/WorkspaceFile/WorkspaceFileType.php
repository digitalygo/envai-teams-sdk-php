<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\WorkspaceFile;

/**
 * Kind of a workspace file entry.
 */
enum WorkspaceFileType: string
{
    case File = 'file';
    case Directory = 'directory';
}
