<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

final class RichTextSanitizer
{
    /** @var array<string, true> */
    private const ALLOWED_ELEMENTS = [
        'p' => true,
        'br' => true,
        'strong' => true,
        'b' => true,
        'em' => true,
        'i' => true,
        'u' => true,
        's' => true,
        'strike' => true,
        'mark' => true,
        'ul' => true,
        'ol' => true,
        'li' => true,
        'blockquote' => true,
        'h2' => true,
        'h3' => true,
    ];

    /** @var array<string, true> */
    private const DROP_WITH_CONTENT = [
        'script' => true,
        'style' => true,
        'iframe' => true,
        'object' => true,
        'embed' => true,
        'svg' => true,
        'math' => true,
        'template' => true,
        'noscript' => true,
        'meta' => true,
        'link' => true,
        'base' => true,
        'form' => true,
        'input' => true,
        'button' => true,
        'textarea' => true,
        'select' => true,
        'option' => true,
        'video' => true,
        'audio' => true,
        'source' => true,
    ];

    public function sanitize(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrors = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div data-rich-text-root="true">'.$html.'</div></body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );

            if (! $loaded) {
                return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            }

            $root = (new DOMXPath($document))->query('//*[@data-rich-text-root="true"]')?->item(0);

            if (! $root instanceof DOMElement) {
                return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            }

            $this->sanitizeChildren($root);

            $sanitized = '';

            foreach ($root->childNodes as $child) {
                $sanitized .= $document->saveHTML($child);
            }

            return trim($sanitized);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    private function sanitizeChildren(DOMNode $parent): void
    {
        $children = [];

        foreach ($parent->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $parent->removeChild($child);

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (isset(self::DROP_WITH_CONTENT[$tag])) {
                $parent->removeChild($child);

                continue;
            }

            if ($tag === 'span' && $this->isLegacyHighlight($child)) {
                $child = $this->replaceWithMark($child);
                $tag = 'mark';
            }

            $this->sanitizeChildren($child);

            if (! isset(self::ALLOWED_ELEMENTS[$tag])) {
                $this->unwrap($child);

                continue;
            }

            while ($child->attributes->length > 0) {
                $child->removeAttributeNode($child->attributes->item(0));
            }
        }
    }

    private function isLegacyHighlight(DOMElement $element): bool
    {
        $style = strtolower(preg_replace('/\s+/', '', $element->getAttribute('style')) ?? '');

        return in_array($style, [
            'background-color:#fff59d',
            'background-color:#fff59d;',
            'background-color:rgb(255,245,157)',
            'background-color:rgb(255,245,157);',
        ], true);
    }

    private function replaceWithMark(DOMElement $element): DOMElement
    {
        $replacement = $element->ownerDocument->createElement('mark');

        while ($element->firstChild !== null) {
            $replacement->appendChild($element->firstChild);
        }

        $element->parentNode?->replaceChild($replacement, $element);

        return $replacement;
    }

    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }
}
