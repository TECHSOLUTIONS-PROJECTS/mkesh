<?php

declare(strict_types=1);

namespace TechSolutions\Mkesh\Xml;

use DOMDocument;
use DOMElement;
use TechSolutions\Mkesh\Exception\ErrorResponseException;
use TechSolutions\Mkesh\Exception\TransportException;

/**
 * Parses response XML coming back from the aggregator and surfaces EWP
 * errorResponse envelopes as {@see ErrorResponseException}.
 */
final class XmlReader
{
    private function __construct()
    {
    }

    /**
     * Parse a raw response body and return the root element.
     *
     * @throws ErrorResponseException when the body is an EWP errorResponse
     * @throws TransportException     when the body is empty or not valid XML
     */
    public static function rootOf(string $xml): DOMElement
    {
        $trimmed = trim($xml);
        if ($trimmed === '') {
            throw new TransportException('Empty response body received from MKESH.');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new DOMDocument();
        $loaded = $document->loadXML($trimmed, LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || !$document->documentElement instanceof DOMElement) {
            $detail = $errors !== [] ? trim($errors[0]->message) : 'unknown parse error';

            throw new TransportException(sprintf('Failed to parse MKESH response XML: %s', $detail));
        }

        $root = $document->documentElement;

        self::assertNotError($root, $trimmed);

        return $root;
    }

    /**
     * Throw an {@see ErrorResponseException} if the root is an errorResponse.
     */
    public static function assertNotError(DOMElement $root, ?string $rawXml = null): void
    {
        if ($root->localName !== 'errorResponse') {
            return;
        }

        $errorCode = $root->getAttribute('errorcode');
        if ($errorCode === '') {
            $errorCode = 'UNKNOWN_ERROR';
        }

        $arguments = [];
        foreach ($root->getElementsByTagNameNS('*', 'arguments') as $argument) {
            /** @var DOMElement $argument */
            $name = $argument->getAttribute('name');
            if ($name !== '') {
                $arguments[$name] = $argument->getAttribute('value');
            }
        }

        throw new ErrorResponseException($errorCode, $arguments, $rawXml);
    }

    /**
     * Return the trimmed text of the first descendant with the given local name,
     * ignoring namespaces, or null when absent.
     */
    public static function text(DOMElement $element, string $localName): ?string
    {
        $nodes = $element->getElementsByTagNameNS('*', $localName);
        if ($nodes->length === 0) {
            return null;
        }

        $value = trim($nodes->item(0)?->textContent ?? '');

        return $value === '' ? null : $value;
    }

    /**
     * Same as {@see text()} but throws when the element is missing.
     *
     * @throws TransportException
     */
    public static function requireText(DOMElement $element, string $localName): string
    {
        $value = self::text($element, $localName);
        if ($value === null) {
            throw new TransportException(sprintf('Expected element <%s> not found in MKESH response.', $localName));
        }

        return $value;
    }
}
