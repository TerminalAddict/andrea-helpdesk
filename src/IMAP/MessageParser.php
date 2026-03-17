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

        $result = [
            'message_id'   => $this->extractRawHeader($rawHeaders, 'Message-ID'),
            'in_reply_to'  => $this->extractRawHeader($rawHeaders, 'In-Reply-To'),
            'references'   => $this->extractRawHeader($rawHeaders, 'References'),
            'x_ticket_id'  => $this->extractRawHeader($rawHeaders, 'X-Ticket-ID'),
            'is_auto_reply'=> $isAutoReply,
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
        [$htmlBody, $textBody, $attachments] = $this->parseStructure($imap, $msgNum, $structure);

        $result['body_html']   = $htmlBody;
        $result['body_text']   = $textBody;
        $result['attachments'] = $attachments;

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
                $data = $this->fetchPart($imap, $msgNum, $partNum ?: '1', $structure->encoding, false);
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

    private function extractRawHeader(string $rawHeaders, string $name): string
    {
        if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/mi', $rawHeaders, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }
}
