<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use RuntimeException;
use Stringable;

/**
 * laravel/ai Agent for PR diff review using OpenAI's Responses API.
 *
 * Implements HasStructuredOutput so the SDK builds the Responses-API
 * `text.format` schema directly. The JSON-Schema array supplied via
 * withSchema() is translated into the laravel/ai type-factory tree at
 * request build time, so the same schema file can be shared with the
 * Anthropic tool-use agent.
 */
#[Provider(Lab::OpenAI)]
#[Model('gpt-4o')]
#[MaxTokens(16384)]
#[Timeout(300)]
class OpenAiReviewAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    private string $systemInstructions = '';

    /** @var array<string, mixed> */
    private array $jsonSchema = [];

    /**
     * Runtime model override — read from config so operators can swap models
     * via CDV_RABBIT_OPENAI_REVIEW_MODEL without touching the #[Model] attribute.
     * The SDK's Promptable::getProvidersAndModels() checks method_exists($this, 'model')
     * before reading the attribute, so this method takes priority at dispatch time.
     */
    public function model(): string
    {
        return (string) config('cdv-rabbit.llm_models.openai_review', 'gpt-4o');
    }

    public function withInstructions(string $instructions): self
    {
        $this->systemInstructions = $instructions;

        return $this;
    }

    /**
     * Accepts either a raw JSON-Schema object (`{type: object, properties: {...}}`)
     * or the project's Anthropic-flavoured wrapper (`{name, input_schema: {...}}`).
     *
     * @param  array<string, mixed>  $schema
     */
    public function withSchema(array $schema): self
    {
        $this->jsonSchema = $schema;

        return $this;
    }

    public function instructions(): Stringable|string
    {
        return $this->systemInstructions;
    }

    /**
     * Build the type tree the SDK feeds into `text.format` on the Responses API.
     * The gateway wraps whatever we return in an ObjectType, so this returns
     * the root object's properties only.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        if ($this->jsonSchema === []) {
            throw new RuntimeException('OpenAiReviewAgent: schema must be set before generating.');
        }

        $root = $this->unwrapRoot($this->jsonSchema);

        if (($root['type'] ?? null) !== 'object' || ! is_array($root['properties'] ?? null)) {
            throw new RuntimeException(
                'OpenAiReviewAgent: top-level JSON Schema must be an object with a "properties" map.'
            );
        }

        return $this->translateProperties(
            $schema,
            $root['properties'],
            is_array($root['required'] ?? null) ? $root['required'] : [],
        );
    }

    /**
     * Strip the project-specific wrapper if present, returning the inner JSON
     * Schema unchanged. The wrapper is the shape Anthropic's tool-use API wants.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function unwrapRoot(array $schema): array
    {
        if (isset($schema['input_schema']) && is_array($schema['input_schema'])) {
            return $schema['input_schema'];
        }

        return $schema;
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  array<int, string>  $required
     * @return array<string, Type>
     */
    private function translateProperties(JsonSchema $schema, array $properties, array $required): array
    {
        $tree = [];

        foreach ($properties as $name => $node) {
            $type = $this->translateNode($schema, $node);

            if (in_array($name, $required, true)) {
                $type = $type->required();
            }

            $tree[$name] = $type;
        }

        return $tree;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function translateNode(JsonSchema $schema, array $node): Type
    {
        // `enum` without an explicit `type` is common in JSON Schema; treat it as a string union.
        if (isset($node['enum']) && is_array($node['enum'])) {
            return $schema->string()->enum($node['enum']);
        }

        $jsonType = $node['type'] ?? null;
        $nullable = false;

        // OpenAI-strict intersection (per ADR 0005 + W7-T2 schemas) encodes nullable
        // required fields as `{ type: ['X', 'null'] }`. Collapse to the non-null type
        // and propagate the nullable flag via Type::nullable().
        if (is_array($jsonType)) {
            $filtered = array_values(array_diff($jsonType, ['null']));
            $nullable = count($filtered) < count($jsonType);

            if (count($filtered) !== 1) {
                throw new InvalidArgumentException(
                    'OpenAiReviewAgent: nullable JSON Schema union must collapse to exactly one non-null type; got '.var_export($jsonType, true)
                );
            }

            $jsonType = $filtered[0];
        }

        $type = match ($jsonType) {
            'string' => $this->applyStringConstraints($schema->string(), $node),
            'integer' => $this->applyIntegerConstraints($schema->integer(), $node),
            'number' => $this->applyNumberConstraints($schema->number(), $node),
            'boolean' => $schema->boolean(),
            'object' => $schema->object($this->translateProperties(
                $schema,
                is_array($node['properties'] ?? null) ? $node['properties'] : [],
                is_array($node['required'] ?? null) ? $node['required'] : [],
            )),
            'array' => $this->applyArrayConstraints($schema->array(), $schema, $node),
            default => throw new InvalidArgumentException(
                'OpenAiReviewAgent: unsupported JSON Schema type '.var_export($jsonType, true)
            ),
        };

        return $nullable ? $type->nullable() : $type;
    }

    /** @param  array<string, mixed>  $node */
    private function applyStringConstraints(Type $type, array $node): Type
    {
        if (isset($node['minLength']) && is_int($node['minLength'])) {
            $type = $type->min($node['minLength']);
        }

        if (isset($node['maxLength']) && is_int($node['maxLength'])) {
            $type = $type->max($node['maxLength']);
        }

        if (isset($node['pattern']) && is_string($node['pattern'])) {
            $type = $type->pattern($node['pattern']);
        }

        return $type;
    }

    /** @param  array<string, mixed>  $node */
    private function applyIntegerConstraints(Type $type, array $node): Type
    {
        if (isset($node['minimum']) && is_int($node['minimum'])) {
            $type = $type->min($node['minimum']);
        }

        if (isset($node['maximum']) && is_int($node['maximum'])) {
            $type = $type->max($node['maximum']);
        }

        return $type;
    }

    /** @param  array<string, mixed>  $node */
    private function applyNumberConstraints(Type $type, array $node): Type
    {
        if (isset($node['minimum']) && (is_int($node['minimum']) || is_float($node['minimum']))) {
            $type = $type->min($node['minimum']);
        }

        if (isset($node['maximum']) && (is_int($node['maximum']) || is_float($node['maximum']))) {
            $type = $type->max($node['maximum']);
        }

        return $type;
    }

    /** @param  array<string, mixed>  $node */
    private function applyArrayConstraints(Type $type, JsonSchema $schema, array $node): Type
    {
        if (isset($node['items']) && is_array($node['items'])) {
            $type = $type->items($this->translateNode($schema, $node['items']));
        }

        if (isset($node['minItems']) && is_int($node['minItems'])) {
            $type = $type->min($node['minItems']);
        }

        if (isset($node['maxItems']) && is_int($node['maxItems'])) {
            $type = $type->max($node['maxItems']);
        }

        return $type;
    }
}
