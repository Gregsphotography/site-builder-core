<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Content;

use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use SiteBuilder\Core\Exceptions\InvalidDocument;
use stdClass;

final class DocumentCodec
{
    private Validator $validator;

    private stdClass $schema;

    public function __construct(private int $maxDepth = 32, private int $maxNodes = 1000, private int $maxBytes = 2097152)
    {
        $this->validator = new Validator;
        $this->schema = json_decode(file_get_contents(__DIR__.'/../../resources/schema/document-v1.schema.json'), flags: JSON_THROW_ON_ERROR);
    }

    public function decode(string $json): Document
    {
        if (strlen($json) > $this->maxBytes) {
            $this->fail('size_limit', '/', 'The document exceeds the configured size limit.');
        }
        try {
            $data = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->fail('invalid_json', '/', 'The document is not valid JSON.');
        }
        $this->checkNumberTokens($json);
        if ($data instanceof stdClass && isset($data->version) && $data->version != Document::VERSION) {
            $this->fail('unsupported_document_version', '/version', 'This document version is unsupported. Preserve the original payload for migration.');
        }
        $this->checkLimits($data);
        $result = $this->validator->validate($data, $this->schema);
        if ($result->hasError()) {
            $errors = (new ErrorFormatter)->format($result->error());
            $diagnostics = [];
            foreach ($errors as $path => $messages) {
                foreach ($messages as $message) {
                    $diagnostics[] = new Diagnostic('invalid_structure', (string) $path, $message);
                }
            }
            throw new InvalidDocument($diagnostics);
        }

        return new Document(array_map($this->node(...), $data->nodes), new JsonObject($data->extensions));
    }

    public function encode(Document $document): string
    {
        $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $this->decode($json);
        try {
            (new CanonicalJson)->encode($document);
        } catch (\InvalidArgumentException $exception) {
            $this->fail('non_interoperable_number', '/', $exception->getMessage());
        }

        return $json;
    }

    private function node(stdClass $node): Node
    {
        return new Node($node->id, $node->type, (int) $node->version, new JsonObject($node->props), new JsonObject($node->translations), $node->visibility, array_map($this->node(...), $node->children), new JsonObject($node->extensions));
    }

    private function checkLimits(mixed $data): void
    {
        $ids = [];
        $count = 0;
        $walk = function (mixed $nodes, int $depth, string $path) use (&$walk, &$ids, &$count): void {
            if (! is_array($nodes)) {
                return;
            }
            foreach ($nodes as $index => $node) {
                if (++$count > $this->maxNodes || $depth > $this->maxDepth) {
                    $this->fail('tree_limit', $path, 'The document exceeds the configured tree limits.');
                }
                if (! $node instanceof stdClass) {
                    continue;
                }
                if (isset($node->id) && is_string($node->id)) {
                    if (isset($ids[$node->id])) {
                        $this->fail('duplicate_node_id', $path.'/'.$index.'/id', 'Node IDs must be unique throughout the document.');
                    }
                    $ids[$node->id] = true;
                }
                $walk($node->children ?? null, $depth + 1, $path.'/'.$index.'/children');
            }
        };
        $walk($data instanceof stdClass ? ($data->nodes ?? null) : null, 1, '/nodes');
    }

    private function checkNumberTokens(string $json): void
    {
        $insideString = false;
        for ($offset = 0, $length = strlen($json); $offset < $length; $offset++) {
            $character = $json[$offset];
            if ($insideString) {
                if ($character === '\\') {
                    $offset++;
                } elseif ($character === '"') {
                    $insideString = false;
                }

                continue;
            }
            if ($character === '"') {
                $insideString = true;

                continue;
            }
            if ($character === '-' || ($character >= '0' && $character <= '9')) {
                $tokenLength = strspn($json, '0123456789.eE+-', $offset);
                $token = substr($json, $offset, $tokenLength);
                $integer = strpbrk($token, '.eE') === false;
                $digits = ltrim($token, '-0');
                if (! is_finite((float) $token) || ($integer && (strlen($digits) > 16 || (strlen($digits) === 16 && strcmp($digits, '9007199254740991') > 0)))) {
                    $this->fail('non_interoperable_number', '/', 'Numbers must be finite and integer literals must fit the exact interoperable range. Preserve larger identifiers as strings.');
                }
                $offset += $tokenLength - 1;
            }
        }
    }

    private function fail(string $code, string $path, string $message): never
    {
        throw new InvalidDocument([new Diagnostic($code, $path, $message)]);
    }
}
