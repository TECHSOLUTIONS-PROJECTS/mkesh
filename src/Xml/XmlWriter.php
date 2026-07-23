<?php

declare(strict_types=1);

namespace BrilliantMind\Mkesh\Xml;

use DOMDocument;
use DOMElement;

/**
 * Small fluent helper for building the request documents. Wraps a DOMDocument
 * with a namespaced root element and convenience methods for child elements.
 */
final class XmlWriter
{
    private DOMDocument $document;
    private DOMElement $root;

    public function __construct(string $namespace, string $rootName, string $prefix = 'ns0')
    {
        $this->document = new DOMDocument('1.0', 'UTF-8');
        $this->document->formatOutput = false;

        $this->root = $this->document->createElementNS($namespace, sprintf('%s:%s', $prefix, $rootName));
        $this->document->appendChild($this->root);
    }

    /**
     * Append a simple text child to the root (skipped when value is null).
     */
    public function child(string $name, ?string $value): self
    {
        if ($value === null) {
            return $this;
        }

        $this->root->appendChild($this->document->createElement($name, self::escape($value)));

        return $this;
    }

    /**
     * Append a child element built by the given callback, receiving the new
     * element so nested children can be added.
     *
     * @param callable(DOMElement, DOMDocument): void $build
     */
    public function element(string $name, callable $build): self
    {
        $element = $this->document->createElement($name);
        $build($element, $this->document);
        $this->root->appendChild($element);

        return $this;
    }

    public function toXml(): string
    {
        return (string) $this->document->saveXML();
    }

    private static function escape(string $value): string
    {
        // DOMDocument::createElement does not escape entities in its $value
        // argument, so we encode the XML special characters ourselves.
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
