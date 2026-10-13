<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Execution;

use Digitalygo\Envai\Models\Json\CanonicalJsonSerializer;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Tools\Schema\ToolSchemaDocument;
use Opis\JsonSchema\Validator;

/** Complete local JSON Schema validation without remote reference fetching. */
final class ToolArgumentsValidator
{
    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public static function validate(JsonObject $arguments, array $schema): array
    {
        try {
            // Preserve empty objects and arrays from the verified wire tree.
            $data = json_decode(CanonicalJsonSerializer::serialize($arguments), false, 512, JSON_THROW_ON_ERROR);
            $document = ToolSchemaDocument::prepare($schema);
            $validator = new Validator($document->loader);
            $result = $validator->validate($data, $document->schema);

            return $result->isValid() ? [] : ['Arguments do not satisfy the complete tool JSON Schema.'];
        } catch (\Throwable) {
            // Malformed/unresolvable schemas are fail-closed too. Do not leak
            // local paths, argument values or validator exception messages.
            return ['Arguments could not be validated against the local tool JSON Schema.'];
        }
    }
}
