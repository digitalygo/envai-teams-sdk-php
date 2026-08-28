<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Image;

/**
 * How the `payload` of an InputImage must be interpreted: a public URL, raw
 * or data URI prefixed base64, or an existing unexpired upload record id.
 */
enum InputImageType: string
{
    case Url = 'url';
    case Base64 = 'base64';
    case UploadId = 'upload_id';
}
