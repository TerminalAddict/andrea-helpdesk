<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Core;

/**
 * Server-side HTML sanitiser for rich-text body content.
 *
 * Provides defence-in-depth alongside the client-side DOMPurify pass.
 * Uses PHP's built-in DOMDocument — no additional library required.
 *
 * Only tags and attributes produced by the Quill editor are permitted.
 * Everything else (including event-handler attributes and javascript: hrefs)
 * is stripped before content is stored in the database.
 */
class Sanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's',
        'ul', 'ol', 'li',
        'a', 'blockquote', 'pre', 'code',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'hr', 'span',
    ];

    private const ALLOWED_ATTRS = ['href', 'title', 'target', 'rel', 'class'];

    /**
     * Sanitise an HTML string, returning only safe markup.
     * Returns an empty string when given null/empty input.
     */
    public static function html(?string $html): string
    {
        if (!$html || !trim($html)) {
            return '';
        }

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        // The meta charset tag ensures DOMDocument handles UTF-8 correctly.
        $doc->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) {
            return '';
        }

        self::sanitizeNode($body, $doc);

        $result = '';
        foreach ($body->childNodes as $child) {
            $result .= $doc->saveHTML($child);
        }

        return $result;
    }

    private static function sanitizeNode(\DOMNode $node, \DOMDocument $doc): void
    {
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return;
        }

        /** @var \DOMElement $node */
        $tag = strtolower($node->nodeName);

        if (!in_array($tag, self::ALLOWED_TAGS, true)) {
            // Replace disallowed element with its plain-text content so copy
            // is not silently lost, then stop recursing into it.
            $text = $doc->createTextNode($node->textContent);
            $node->parentNode->replaceChild($text, $node);
            return;
        }

        // Strip disallowed attributes
        $attrsToRemove = [];
        foreach ($node->attributes as $attr) {
            $name = strtolower($attr->name);
            if (!in_array($name, self::ALLOWED_ATTRS, true)) {
                $attrsToRemove[] = $attr->name;
            } elseif ($name === 'href' && preg_match('/^\s*javascript:/i', $attr->value)) {
                // Block javascript: pseudo-protocol
                $attrsToRemove[] = $attr->name;
            }
        }
        foreach ($attrsToRemove as $name) {
            $node->removeAttribute($name);
        }

        // Ensure external links cannot trigger opener attacks
        if ($tag === 'a' && $node->getAttribute('target') === '_blank') {
            $node->setAttribute('rel', 'noopener noreferrer');
        }

        // Recurse into child nodes (copy to array first to avoid live-NodeList mutation)
        foreach (iterator_to_array($node->childNodes) as $child) {
            self::sanitizeNode($child, $doc);
        }
    }
}
