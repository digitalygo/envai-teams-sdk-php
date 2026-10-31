<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Http\Client;

use Digitalygo\Envai\Contracts\EventStreamConnection;
use Digitalygo\Envai\Contracts\EventStreamTransport;
use Digitalygo\Envai\Contracts\HttpTransport;
use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Http\HttpRequest;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Http\MultipartBody;
use Digitalygo\Envai\Http\MultipartFile;
use Digitalygo\Envai\Http\StreamRequest;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;

/** Optional canonical HTTP validation. Never reads upload file contents or retries a mutation. */
final class StrictValidationTransport implements EventStreamTransport, HttpTransport
{
    private \stdClass $document;

    private Validator $validator;

    /** @var list<array{id:string, method:string, pattern:string, names:list<string>, pointer:string, operation:\stdClass}> */
    private array $routes = [];

    private const SCHEMA_ID = 'https://envai.invalid/strict/openapi.json';

    public function __construct(private readonly HttpTransport&EventStreamTransport $inner, UriResolver $uris, private readonly int $version = 3)
    {
        $document = Yaml::parseFile(dirname(__DIR__, 3).'/resources/http/openapi.yaml', Yaml::PARSE_OBJECT_FOR_MAP);
        if (! $document instanceof \stdClass || ! ($document->paths ?? null) instanceof \stdClass) {
            throw new \LogicException('Canonical OpenAPI document unavailable.');
        }
        // Discovery is additive. Preserve the existing boolean feature-map dialect
        // and accept future feature/family names without changing canonical bytes.
        $components = $document->components ?? null;
        $definitions = $components instanceof \stdClass ? ($components->schemas ?? null) : null;
        $capabilities = $definitions instanceof \stdClass ? ($definitions->Capabilities ?? null) : null;
        $properties = $capabilities instanceof \stdClass ? ($capabilities->properties ?? null) : null;
        if ($properties instanceof \stdClass) {
            foreach (['features', 'families'] as $name) {
                $field = $properties->{$name} ?? null;
                if ($field instanceof \stdClass && ($field->items ?? null) instanceof \stdClass) {
                    unset($field->items->enum);
                }
            }
            if (($properties->features ?? null) instanceof \stdClass) {
                $properties->features->type = ['array', 'object'];
                $properties->features->additionalProperties = (object) ['type' => 'boolean'];
            }
        }
        $document->{'$schema'} = 'https://json-schema.org/draft/2020-12/schema';
        $this->document = $document;
        $document->{'$id'} = self::SCHEMA_ID;
        $this->validator = new Validator;
        if ($this->validator->resolver()?->registerRaw($document, self::SCHEMA_ID) !== true) {
            throw new \LogicException('Canonical schema registration failed.');
        }
        foreach (get_object_vars($document->paths) as $path => $item) {
            if (! $item instanceof \stdClass) {
                continue;
            }
            foreach (get_object_vars($item) as $method => $operation) {
                if (! $operation instanceof \stdClass || ! is_string($operation->operationId ?? null)) {
                    continue;
                }
                $uri = match ($operation->operationId) {
                    'getJwks' => $uris->jwksUrl(),
                    'getWikiWebhookJwks' => $uris->wikiWebhookJwksUrl(),
                    default => $uris->api($path),
                };
                $route = parse_url($uri, PHP_URL_PATH);
                if (! is_string($route)) {
                    throw new \LogicException('Invalid canonical route.');
                }
                preg_match_all('/\{([^}]+)\}/', $route, $names);
                $pattern = preg_replace('/\\\\\{[^}]+\\\\\}/', '([^/]+)', preg_quote($route, '~'));
                $this->routes[] = ['id' => $operation->operationId, 'method' => strtoupper($method), 'pattern' => '~^'.$pattern.'$~D', 'names' => $names[1], 'pointer' => '#/paths/'.self::escape($path).'/'.$method, 'operation' => $operation];
            }
        }
    }

    /** @return list<string> */
    public function operationIds(): array
    {
        return array_map(static fn (array $route): string => $route['id'], $this->routes);
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $route = $this->validateRequest($request);
        $response = $this->inner->send($request);
        if ($route === null || ! $response->isSuccess()) {
            return $response;
        }
        $responses = $route['operation']->responses ?? null;
        $status = (string) $response->status;
        $entry = $responses instanceof \stdClass ? ($responses->{$status} ?? null) : null;
        if (! $entry instanceof \stdClass) {
            throw new ProtocolViolationException('Unexpected successful HTTP status for '.$route['id'].'.');
        }
        if ($this->contentSchema($entry, 'application/json') !== null) {
            try {
                $value = json_decode($response->body ?? '', false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new ProtocolViolationException('Malformed JSON response.', previous: $exception);
            }
            $this->check($value, $route['pointer'].'/responses/'.$status.'/content/application~1json/schema', false);
        }

        return $response;
    }

    public function open(StreamRequest $request): EventStreamConnection
    {
        $this->validateRequest(new HttpRequest('GET', $request->uri, $request->headers));

        return $this->inner->open($request);
    }

    /** @return array{id:string, method:string, pattern:string, names:list<string>, pointer:string, operation:\stdClass}|null */
    private function validateRequest(HttpRequest $request): ?array
    {
        $path = parse_url($request->uri, PHP_URL_PATH);
        foreach ($this->routes as $route) {
            if ($route['method'] !== strtoupper($request->method) || ! is_string($path) || preg_match($route['pattern'], $path, $matches) !== 1) {
                continue;
            }
            // Existing finite v2 replay/terminal validators retain their historical schema.
            if ($this->version === 2 && (str_contains($path, '/atp/') || str_contains($path, '/completions') || str_contains($path, '/capabilities'))) {
                return null;
            }
            $values = [];
            foreach ($route['names'] as $index => $name) {
                $values['path'][$name] = rawurldecode($matches[$index + 1]);
            }
            $query = parse_url($request->uri, PHP_URL_QUERY);
            foreach (is_string($query) && $query !== '' ? explode('&', $query) : [] as $pair) {
                [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
                $name = urldecode($name);
                if (isset($values['query'][$name])) {
                    throw new \InvalidArgumentException('Duplicate query parameter.');
                }
                $values['query'][$name] = urldecode($value);
            }
            foreach ($request->headers as $name => $entries) {
                $values['header'][$name] = implode(',', $entries);
            }
            $values['header']['x-envai-contract-version'] ??= (string) $this->version;
            $parameters = $route['operation']->parameters ?? [];
            if (! is_array($parameters)) {
                throw new \LogicException('Invalid canonical parameters.');
            }
            foreach (array_values($parameters) as $index => $parameter) {
                [$parameter, $parameterPointer] = $this->resolve($parameter, $route['pointer'].'/parameters/'.$index);
                if (! is_string($parameter->in ?? null) || ! is_string($parameter->name ?? null) || ! ($parameter->schema ?? null) instanceof \stdClass) {
                    throw new \LogicException('Invalid canonical parameter schema.');
                }
                $name = $parameter->in === 'header' ? strtolower($parameter->name) : $parameter->name;
                if (! isset($values[$parameter->in][$name])) {
                    if ($parameter->required ?? false) {
                        throw new \InvalidArgumentException('Required HTTP parameter missing.');
                    }

                    continue;
                }
                $this->check(self::scalar($values[$parameter->in][$name], $parameter->schema), $parameterPointer.'/schema', true);
            }
            $body = $route['operation']->requestBody ?? null;
            if ($body instanceof \stdClass) {
                if ($request->body === null) {
                    if ($body->required ?? false) {
                        throw new \InvalidArgumentException('Required HTTP request body missing.');
                    }
                } elseif ($request->body instanceof MultipartBody) {
                    $schema = $this->contentSchema($body, 'multipart/form-data');
                    if (! $schema instanceof \stdClass) {
                        throw new \InvalidArgumentException('Unexpected multipart request.');
                    }
                    [$schema] = $this->resolve($schema, $route['pointer'].'/requestBody/content/multipart~1form-data/schema');
                    $data = [];
                    $properties = ($schema->properties ?? null) instanceof \stdClass ? $schema->properties : new \stdClass;
                    foreach ($request->body->parts as $part) {
                        $array = preg_match('/\[(?:[0-9]*)\]$/D', $part->name) === 1;
                        $name = $array ? (string) preg_replace('/\[(?:[0-9]*)\]$/D', '', $part->name) : $part->name;
                        $property = ($properties->{$name} ?? null) instanceof \stdClass ? $properties->{$name} : new \stdClass;
                        $value = $part instanceof MultipartFile ? 'binary' : self::scalar($part->value, $property);
                        if ($array) {
                            if (isset($data[$name]) && ! is_array($data[$name])) {
                                throw new \InvalidArgumentException('Mixed scalar and array multipart field.');
                            }
                            $data[$name] ??= [];
                            $data[$name][] = $value;
                        } else {
                            $data[$name] = $value;
                        }
                    }
                    $this->check((object) $data, $route['pointer'].'/requestBody/content/multipart~1form-data/schema', true);
                } else {
                    try {
                        $data = json_decode($request->body, false, 512, JSON_THROW_ON_ERROR);
                    } catch (\JsonException $exception) {
                        throw new \InvalidArgumentException('Malformed JSON request.', previous: $exception);
                    }
                    $this->check($data, $route['pointer'].'/requestBody/content/application~1json/schema', true);
                }
            }

            return $route;
        }

        return null; // Signed download URLs and custom transports remain outside the canonical inventory.
    }

    private function contentSchema(\stdClass $entry, string $mime): ?\stdClass
    {
        $content = $entry->content ?? null;
        $media = $content instanceof \stdClass ? ($content->{$mime} ?? null) : null;
        $schema = $media instanceof \stdClass ? ($media->schema ?? null) : null;

        return $schema instanceof \stdClass ? $schema : null;
    }

    /** @return array{\stdClass, string} */
    private function resolve(mixed $value, string $pointer): array
    {
        $seen = [];
        while ($value instanceof \stdClass && isset($value->{'$ref'})) {
            $ref = $value->{'$ref'};
            if (! is_string($ref) || ! str_starts_with($ref, '#/') || isset($seen[$ref])) {
                throw new \LogicException('Invalid canonical parameter reference.');
            }
            $seen[$ref] = true;
            $pointer = $ref;
            $value = $this->document;
            foreach (explode('/', substr($ref, 2)) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                $value = $value instanceof \stdClass ? ($value->{$segment} ?? null) : null;
            }
        }
        if (! $value instanceof \stdClass) {
            throw new \LogicException('Invalid canonical parameter.');
        }

        return [$value, $pointer];
    }

    private static function escape(string $value): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $value);
    }

    private static function scalar(string $value, \stdClass $schema): mixed
    {
        if (($schema->type ?? null) === 'integer' && preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) === 1 && filter_var($value, FILTER_VALIDATE_INT) !== false) {
            return (int) $value;
        }
        if (($schema->type ?? null) === 'boolean' && in_array($value, ['true', 'false', '0', '1'], true)) {
            return in_array($value, ['true', '1'], true);
        }

        return $value;
    }

    private function check(mixed $value, string $pointer, bool $request): void
    {
        if (! $this->validator->validate($value, self::SCHEMA_ID.'#'.implode('/', array_map('rawurlencode', explode('/', substr($pointer, 1)))))->isValid()) {
            if ($request) {
                throw new \InvalidArgumentException('HTTP request violates canonical schema.');
            }
            throw new ProtocolViolationException('HTTP response violates canonical schema.');
        }
    }
}
