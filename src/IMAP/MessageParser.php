<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\IMAP;

class MessageParser
{
    /**
     * Parse a single IMAP message into a structured array.
     */
    public function parse($imap, int $msgNum): array
    {
        $header     = imap_headerinfo($imap, $msgNum);
        $rawHeaders = imap_fetchheader($imap, $msgNum);

        $autoSubmitted  = strtolower(trim($this->extractRawHeader($rawHeaders, 'Auto-Submitted')));
        $precedence     = strtolower(trim($this->extractRawHeader($rawHeaders, 'Precedence')));
        $autoSuppress   = $this->extractRawHeader($rawHeaders, 'X-Auto-Response-Suppress');
        $decodedSubject = $this->decodeSubject($header->subject ?? '(No Subject)');

        $isAutoReply = ($autoSubmitted !== '' && $autoSubmitted !== 'no')
            || in_array($precedence, ['auto-reply', 'bulk', 'junk'], true)
            || $autoSuppress !== ''
            || (bool)preg_match('/^(out of office|automatic reply|auto reply|auto-reply|vacation|away from|absence|autosvar|automatische antwort)/i', $decodedSubject);

        // Forwarded emails must not have their quoted sections stripped — the
        // forwarded content IS the primary content, not a reply quote.
        $isForwarded = (bool)preg_match('/^\s*(fwd?|forward):/i', $decodedSubject);

        $result = [
            'message_id'   => $this->extractRawHeader($rawHeaders, 'Message-ID'),
            'in_reply_to'  => $this->extractRawHeader($rawHeaders, 'In-Reply-To'),
            'references'   => $this->extractRawHeader($rawHeaders, 'References'),
            'x_ticket_id'  => $this->extractRawHeader($rawHeaders, 'X-Ticket-ID'),
            'is_auto_reply'=> $isAutoReply,
            'is_bounce'    => false,
            'delivery_failure' => null,
            'subject'     => $decodedSubject,
            'from_email'  => '',
            'from_name'   => '',
            'reply_to'    => '',
            'to'          => [],
            'cc'          => [],
            'body_html'   => '',
            'body_text'   => '',
            'attachments' => [],
            'date'        => date('Y-m-d H:i:s', $header->udate ?? time()),
        ];

        // From
        if (!empty($header->from)) {
            $from               = $header->from[0];
            $result['from_email'] = strtolower($from->mailbox . '@' . $from->host);
            $result['from_name']  = isset($from->personal) ? imap_utf8($from->personal) : $result['from_email'];
        }

        // Reply-To
        if (!empty($header->reply_to)) {
            $rt = $header->reply_to[0];
            $result['reply_to'] = strtolower($rt->mailbox . '@' . $rt->host);
        }

        // To
        $result['to'] = $this->decodeAddressList($header->to ?? null);

        // CC
        $result['cc'] = $this->decodeAddressList($header->cc ?? null);

        // Body and attachments
        $structure = imap_fetchstructure($imap, $msgNum);
        if (!$structure) {
            $result['body_html']   = '';
            $result['body_text']   = '';
            $result['attachments'] = [];
            return $result;
        }
        [$htmlBody, $textBody, $attachments] = $this->parseStructure($imap, $msgNum, $structure);

        $rawText = $textBody ?: trim(strip_tags($htmlBody));
        $result['body_html']   = $htmlBody ? $this->replaceCidImages($isForwarded ? $htmlBody : $this->stripHtmlQuotes($htmlBody)) : '';
        $result['body_text']   = $textBody ? ($isForwarded ? $textBody : $this->stripPlainTextQuotes($textBody)) : '';
        $result['attachments'] = $attachments;
        $result['is_bounce'] = $this->detectBounce($decodedSubject, $result['from_email'], $rawText, $rawHeaders);
        if ($result['is_bounce']) {
            $result['delivery_failure'] = $this->extractDeliveryFailure($rawText, $decodedSubject);
        }

        // Clean up message IDs - remove angle brackets
        foreach (['message_id', 'in_reply_to'] as $field) {
            $result[$field] = trim($result[$field], '<> ');
        }

        return $result;
    }

    private function parseStructure($imap, int $msgNum, object $structure, string $partNum = ''): array
    {
        $htmlBody    = '';
        $textBody    = '';
        $attachments = [];

        if ($structure->type === TYPETEXT) {
            $body = $this->fetchPart($imap, $msgNum, $partNum ?: '1', $structure->encoding);
            $charset = 'UTF-8';
            if (!empty($structure->parameters)) {
                foreach ($structure->parameters as $param) {
                    if (strtolower($param->attribute) === 'charset') {
                        $charset = $param->value;
                    }
                }
            }
            if ($charset !== 'UTF-8') {
                $body = mb_convert_encoding($body, 'UTF-8', $charset) ?: $body;
            }

            if (strtolower($structure->subtype) === 'html') {
                $htmlBody = $body;
            } else {
                $textBody = $body;
            }

        } elseif ($structure->type === TYPEMULTIPART && !empty($structure->parts)) {
            foreach ($structure->parts as $i => $part) {
                $subPartNum = $partNum ? "{$partNum}." . ($i + 1) : (string)($i + 1);
                [$subHtml, $subText, $subAttachments] = $this->parseStructure($imap, $msgNum, $part, $subPartNum);
                if (!$htmlBody) $htmlBody = $subHtml;
                if (!$textBody) $textBody = $subText;
                $attachments = array_merge($attachments, $subAttachments);
            }

        } else {
            // Possible attachment
            $filename    = $this->getPartFilename($structure);
            $disposition = strtolower($structure->ifid ? 'inline' : ($structure->ifdisposition ? $structure->disposition : ''));

            if ($filename || $disposition === 'attachment') {
                $data = $this->fetchPart($imap, $msgNum, $partNum ?: '1', $structure->encoding);
                $attachments[] = [
                    'filename'  => $filename ?: 'attachment',
                    'data'      => $data,
                    'mime_type' => $this->getMimeType($structure),
                    'size'      => strlen($data),
                ];
            }
        }

        return [$htmlBody, $textBody, $attachments];
    }

    private function fetchPart($imap, int $msgNum, string $partNum, int $encoding, bool $decode = true): string
    {
        $data = imap_fetchbody($imap, $msgNum, $partNum);
        if (!$decode) return $data;

        return match($encoding) {
            ENCBASE64        => base64_decode($data),
            ENCQUOTEDPRINTABLE => quoted_printable_decode($data),
            default          => $data,
        };
    }

    private function decodeSubject(string $subject): string
    {
        $decoded = imap_utf8($subject);
        return $decoded ?: $subject;
    }

    private function decodeAddressList(?array $addresses): array
    {
        if (!$addresses) return [];
        $result = [];
        foreach ($addresses as $addr) {
            if (empty($addr->mailbox) || empty($addr->host)) continue;
            $email = strtolower($addr->mailbox . '@' . $addr->host);
            $name  = isset($addr->personal) ? imap_utf8($addr->personal) : $email;
            $result[] = ['email' => $email, 'name' => $name];
        }
        return $result;
    }

    private function getPartFilename(object $part): string
    {
        if ($part->ifdparameters) {
            foreach ($part->dparameters as $param) {
                if (strtolower($param->attribute) === 'filename') {
                    return imap_utf8($param->value);
                }
            }
        }
        if ($part->ifparameters) {
            foreach ($part->parameters as $param) {
                if (strtolower($param->attribute) === 'name') {
                    return imap_utf8($param->value);
                }
            }
        }
        return '';
    }

    private function getMimeType(object $structure): string
    {
        $types = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'other'];
        $type  = $types[$structure->type] ?? 'application';
        return $type . '/' . strtolower($structure->subtype ?? 'octet-stream');
    }

    /**
     * Replace CID inline-image references with a paperclip icon.
     * The images are stored as regular attachments; the cid: URI scheme
     * is not renderable in this context.
     */
    private function replaceCidImages(string $html): string
    {
        return preg_replace(
            '/<img[^>]+src=["\']cid:[^"\']*["\'][^>]*\/?>/si',
            '<span class="bi bi-paperclip text-muted" title="Inline image (see attachments below)"></span>',
            $html
        ) ?? $html;
    }

    private function stripHtmlQuotes(string $html): string
    {
        // Gmail: <div class="gmail_attr">On … wrote:</div> + <blockquote class="gmail_quote">
        $html = preg_replace('/<div[^>]+class="gmail_attr"[^>]*>.*?<\/div>\s*/si', '', $html);
        $html = preg_replace('/<blockquote[^>]+class="gmail_quote"[^>]*>.*?<\/blockquote>\s*/si', '', $html);

        // Yahoo Mail
        $html = preg_replace('/<div[^>]+class="[^"]*yahoo_quoted[^"]*"[^>]*>.*?<\/div>\s*/si', '', $html);

        // Outlook: <div id="divRplyFwdMsg"> and everything after it
        $html = preg_replace('/<div[^>]+id="divRplyFwdMsg"[^>]*>.*$/si', '', $html);

        // Outlook HR separator
        $html = preg_replace('/<hr[^>]+id="stopSpelling"[^>]*\/?\>.*$/si', '', $html);

        // Apple Mail / generic <blockquote type="cite">
        $html = preg_replace('/<blockquote[^>]+type=["\']cite["\'][^>]*>.*?<\/blockquote>\s*/si', '', $html);

        return trim($html);
    }

    private function stripPlainTextQuotes(string $text): string
    {
        $lines = preg_split('/\r?\n/', $text);
        $out   = [];

        for ($i = 0, $n = count($lines); $i < $n; $i++) {
            $trimmed = ltrim($lines[$i]);

            // Quoted line — stop
            if (str_starts_with($trimmed, '>')) break;

            // Separator line — stop
            if (preg_match('/^[\-_]{2,}\s*(original message|forwarded message)/i', $trimmed)) break;

            // "On DATE, NAME <email> wrote:" — may wrap across up to 3 lines
            if (preg_match('/^On /i', $trimmed)) {
                $chunk = implode(' ', array_map('trim', array_slice($lines, $i, 3)));
                if (preg_match('/wrote:\s*$/si', $chunk)) break;
            }

            $out[] = $lines[$i];
        }

        return rtrim(implode("\n", $out));
    }

    private function detectBounce(string $subject, string $fromEmail, string $rawText, string $rawHeaders): bool
    {
        $fromEmail = strtolower($fromEmail);
        if (preg_match('/^(mailer-daemon|postmaster)@/i', $fromEmail)) {
            return true;
        }

        if (preg_match('/\b(mail delivery failed|delivery status notification|undelivered|returned mail|failure notice)\b/i', $subject)) {
            return true;
        }

        $haystack = $rawHeaders . "\n" . $rawText;
        return (bool)preg_match(
            '/(This message was created automatically by the mail system|The following address(?:es)? failed|Diagnostic-Code:|Final-Recipient:|delivery to the following recipient failed permanently)/i',
            $haystack
        );
    }

    private function extractDeliveryFailure(string $rawText, string $subject): array
    {
        $recipient = null;
        $status = null;
        $diagnostic = null;
        $remoteMta = null;

        if (preg_match('/^Final-Recipient:\s*rfc822;\s*(.+)$/mi', $rawText, $m)) {
            $recipient = trim($m[1]);
        } elseif (preg_match('/^Original-Recipient:\s*rfc822;\s*(.+)$/mi', $rawText, $m)) {
            $recipient = trim($m[1]);
        } elseif (preg_match('/The following address(?:es)? failed:\s*(.+?)(?:\r?\n\r?\n|\z)/is', $rawText, $m)) {
            if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $m[1], $email)) {
                $recipient = $email[0];
            } else {
                $recipient = trim(preg_split('/\r?\n/', trim($m[1]))[0] ?? '');
            }
        }

        if (preg_match('/^Status:\s*([0-9.]+)$/mi', $rawText, $m)) {
            $status = trim($m[1]);
        }
        if (preg_match('/^Diagnostic-Code:\s*[^;]+;\s*(.+)$/mi', $rawText, $m)) {
            $diagnostic = trim($m[1]);
        }
        if (preg_match('/^Remote-MTA:\s*[^;]+;\s*(.+)$/mi', $rawText, $m)) {
            $remoteMta = trim($m[1]);
        }

        $summary = $diagnostic ?: ($status ? "SMTP status {$status}" : 'Outbound delivery failed');
        $summary = mb_substr($summary, 0, 255);

        $details = [];
        if ($recipient) {
            $details[] = "Recipient: {$recipient}";
        }
        if ($status) {
            $details[] = "Status: {$status}";
        }
        if ($diagnostic) {
            $details[] = "Diagnostic: {$diagnostic}";
        }
        if ($remoteMta) {
            $details[] = "Remote server: {$remoteMta}";
        }
        if (empty($details)) {
            $details[] = "Subject: {$subject}";
            $details[] = trim(mb_substr(preg_replace('/\s+/', ' ', $rawText) ?: '', 0, 255));
        }

        return [
            'recipient' => $recipient,
            'status' => $status,
            'diagnostic' => $diagnostic,
            'remote_mta' => $remoteMta,
            'summary' => $summary,
            'details' => array_values(array_filter($details)),
        ];
    }

    private function extractRawHeader(string $rawHeaders, string $name): string
    {
        if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/mi', $rawHeaders, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }
}
