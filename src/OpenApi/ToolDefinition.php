<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\OpenApi;

/**
 * One OpenAPI operation, translated into everything the MCP layer needs to
 * both advertise the tool and proxy a call to it.
 *
 * Deliberately a plain value object with array round-tripping: instances are
 * `var_export`ed into an opcache-backed cache file, and reconstructed from
 * that array on every warm request. Adding a property means adding it to both
 * {@see toArray()} and {@see fromArray()} or the cache silently drops it.
 */
final class ToolDefinition
{
    /**
     * @param array<string, mixed>  $inputSchema
     * @param list<string>          $pathParams
     * @param list<string>          $queryParams
     * @param array<string, mixed>  $annotations
     * @param array<string, mixed>|null $outputSchema
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $httpMethod,
        public readonly string $path,
        public readonly array $inputSchema,
        public readonly array $pathParams,
        public readonly array $queryParams,
        public readonly bool $hasBody,
        public readonly array $annotations = [],
        public readonly ?array $outputSchema = null,
        public readonly string $tag = '',
    ) {
    }

    public function title(): string
    {
        $title = $this->annotations['title'] ?? '';

        return \is_string($title) && '' !== $title ? $title : $this->name;
    }

    public function isReadOnly(): bool
    {
        return true === ($this->annotations['readOnlyHint'] ?? null);
    }

    public function isDestructive(): bool
    {
        return true === ($this->annotations['destructiveHint'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'httpMethod' => $this->httpMethod,
            'path' => $this->path,
            'inputSchema' => $this->inputSchema,
            'pathParams' => $this->pathParams,
            'queryParams' => $this->queryParams,
            'hasBody' => $this->hasBody,
            'annotations' => $this->annotations,
            'outputSchema' => $this->outputSchema,
            'tag' => $this->tag,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            description: (string) ($data['description'] ?? ''),
            httpMethod: strtoupper((string) $data['httpMethod']),
            path: (string) $data['path'],
            inputSchema: $data['inputSchema'] ?? ['type' => 'object'],
            pathParams: array_values($data['pathParams'] ?? []),
            queryParams: array_values($data['queryParams'] ?? []),
            hasBody: (bool) ($data['hasBody'] ?? false),
            annotations: $data['annotations'] ?? [],
            outputSchema: $data['outputSchema'] ?? null,
            tag: (string) ($data['tag'] ?? ''),
        );
    }
}
