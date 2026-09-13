<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/IMAP/MessageParser.php';

$parser = new Andrea\Helpdesk\IMAP\MessageParser();
$decode = new ReflectionMethod($parser, 'decodeBodyCharset');

$cases = [
    ['Baltic Windows charset', "\xC0\xC2\xC7", 'windows-1257', "\u{0104}\u{0100}\u{0112}"],
    ['Quoted charset', "\xC0", ' "WINDOWS-1257" ', "\u{0104}"],
    ['Supported charset', "caf\xE9", 'ISO-8859-1', "caf\u{00E9}"],
    ['UTF-8 emoji', "Hello \u{1F600}", 'utf-8', "Hello \u{1F600}"],
    ['Unknown charset with UTF-8', "Hello \u{1F600}", 'unknown-charset', "Hello \u{1F600}"],
    ['Unknown charset with legacy bytes', "\x93hello\x94", 'unknown-charset', "\u{201C}hello\u{201D}"],
    ['Empty declaration', 'hello', '', 'hello'],
    ['Zero body', '0', 'windows-1257', '0'],
    ['Empty body', '', 'windows-1257', ''],
    ['Invalid UTF-8 body', "broken\xFF", 'UTF-8', null],
];

foreach ($cases as [$label, $body, $charset, $expected]) {
    $actual = $decode->invoke($parser, $body, $charset);
    if (!mb_check_encoding($actual, 'UTF-8') || ($expected !== null && $actual !== $expected)) {
        throw new RuntimeException("Failed: {$label}");
    }
    echo "PASS: {$label}\n";
}
