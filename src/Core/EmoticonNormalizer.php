<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Core;

class EmoticonNormalizer
{
    private const MAP = [
        ':-)' => '🙂', ':)' => '🙂', '=)' => '🙂',
        ';-)' => '😉', ';)' => '😉',
        ':-d' => '😃', ':d' => '😃', '=d' => '😃',
        'xd' => '😆',
        ':-p' => '😛', ':p' => '😛', '=p' => '😛',
        ':-o' => '😮', ':o' => '😮',
        ':-(' => '🙁', ':(' => '🙁', '=(' => '🙁',
        ":'-)" => '🥲', ":')" => '🥲',
        ":'-(" => '😢', ":'(" => '😢',
        ':-/' => '😕', ':/' => '😕', ':-\\' => '😕', ':\\' => '😕',
        ':-|' => '😐', ':|' => '😐',
        ':-*' => '😘', ':*' => '😘',
        ':-@' => '😡', ':@' => '😡',
        ':-$' => '😳', ':$' => '😳',
        '<3' => '❤️', '</3' => '💔',
        ':+1:' => '👍', ':-1:' => '👎',
    ];

    public static function text(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $tokens = array_keys(self::MAP);
        usort($tokens, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $pattern = '~(^|[\s([{])(' . implode('|', array_map(
            static fn(string $token): string => preg_quote($token, '~'),
            $tokens
        )) . ')(?=$|[\s)\]},.!?;])~iu';

        return (string)preg_replace_callback(
            $pattern,
            static function (array $matches): string {
                $token = strtolower($matches[2]);
                return $matches[1] . (self::MAP[$token] ?? $matches[2]);
            },
            $text
        );
    }

    public static function html(string $html): string
    {
        if ($html === '' || trim($html) === '') {
            return '';
        }

        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return self::text($html);
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) {
            return self::text($html);
        }

        self::normaliseTextNodes($body);

        $result = '';
        foreach ($body->childNodes as $child) {
            $result .= $doc->saveHTML($child);
        }

        return $result;
    }

    private static function normaliseTextNodes(\DOMNode $node): void
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            $node->nodeValue = self::text((string)$node->nodeValue);
            return;
        }

        foreach (iterator_to_array($node->childNodes) as $child) {
            self::normaliseTextNodes($child);
        }
    }
}
