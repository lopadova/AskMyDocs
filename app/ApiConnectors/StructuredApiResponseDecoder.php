<?php

declare(strict_types=1);

namespace App\ApiConnectors;

use DOMDocument;
use DOMElement;

/** Opt-in XML normalization for read APIs; JSON retains its original types. */
final class StructuredApiResponseDecoder
{
    public function decode(string $body, ?array $options = null): ?array
    {
        $json = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }
        if (($options['response_format'] ?? null) !== 'xml' || strlen($body) > 2 * 1024 * 1024
            || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $body)) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($body, LIBXML_NONET | LIBXML_NOCDATA) || ! $document->documentElement) {
                return null;
            }
            $root = $document->documentElement;
            if (! is_string($options['xml_root'] ?? null) || $options['xml_root'] !== $root->nodeName) {
                return null;
            }
            $listPaths = array_values(array_filter($options['xml_list_paths'] ?? [], 'is_string'));
            $result = [$root->nodeName => $this->element($root, $root->nodeName, $listPaths, 0)];
            foreach ($listPaths as $path) {
                // An empty root still represents a successful empty collection.
                $segments = explode('.', $path);
                if (count($segments) === 2 && $segments[0] === $root->nodeName && $root->childElementCount === 0) {
                    $result[$root->nodeName] = [$segments[1] => []];
                }
            }
            return $result;
        } catch (\Throwable) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function element(DOMElement $element, string $path, array $listPaths, int $depth): array|string
    {
        if ($depth > 48) {
            throw new \UnexpectedValueException('XML nesting exceeds the supported limit.');
        }
        $groups = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $groups[$child->nodeName][] = $child;
            } elseif ($child->nodeType === XML_ENTITY_REF_NODE) {
                throw new \UnexpectedValueException('XML entity references are unsupported.');
            }
        }
        if ($groups === []) {
            // Keep XML scalars as strings: identifiers, decimals and leading
            // zeroes must not be guessed or silently converted.
            return trim($element->textContent);
        }
        $result = [];
        foreach ($groups as $name => $children) {
            $childPath = $path.'.'.$name;
            $values = array_map(fn (DOMElement $child) => $this->element($child, $childPath, $listPaths, $depth + 1), $children);
            $result[$name] = count($values) > 1 || in_array($childPath, $listPaths, true) ? $values : $values[0];
        }
        return $result;
    }
}
