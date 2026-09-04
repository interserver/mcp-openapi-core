<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Tests\Fixtures;

/**
 * Builds minimal OpenAPI documents for tests.
 *
 * Tests assert on one behaviour each, so they need a document that contains
 * exactly the thing under test and nothing else — a shared 400-line fixture makes
 * every failure require reading the fixture to interpret.
 */
final class SpecBuilder
{
    /** @var array<string, mixed> */
    private array $paths = [];

    /** @var array<string, mixed> */
    private array $components = [];

    public static function make(): self
    {
        return new self();
    }

    /**
     * @param array<string, mixed> $operation
     */
    public function operation(string $path, string $method, array $operation): self
    {
        $this->paths[$path][$method] = $operation;

        return $this;
    }

    /**
     * @param array<int, mixed> $parameters
     */
    public function pathParameters(string $path, array $parameters): self
    {
        $this->paths[$path]['parameters'] = $parameters;

        return $this;
    }

    /**
     * @param array<string, mixed> $components
     */
    public function components(array $components): self
    {
        $this->components = $components;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $spec = [
            'openapi' => '3.0.3',
            'info' => ['title' => 'Test API', 'version' => '1.0.0'],
            'paths' => $this->paths,
        ];
        if ([] !== $this->components) {
            $spec['components'] = $this->components;
        }

        return $spec;
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray());
    }

    public function toYaml(): string
    {
        return \Symfony\Component\Yaml\Yaml::dump($this->toArray(), 20);
    }

    /**
     * A GET with one path param, one query param and a JSON 200 — the shape most
     * operations in both real specs actually have.
     *
     * @return array<string, mixed>
     */
    public static function typicalGet(string $operationId = 'getThing', string $summary = 'Get a thing'): array
    {
        return [
            'operationId' => $operationId,
            'summary' => $summary,
            'tags' => ['Things'],
            'parameters' => [
                ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                ['name' => 'verbose', 'in' => 'query', 'schema' => ['type' => 'boolean']],
            ],
            'responses' => [
                '200' => [
                    'description' => 'ok',
                    'content' => ['application/json' => ['schema' => [
                        'type' => 'object',
                        'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']],
                    ]]],
                ],
            ],
        ];
    }
}
