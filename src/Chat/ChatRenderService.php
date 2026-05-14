<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Chat;

use Andrea\Helpdesk\Core\Database;

class ChatRenderService
{
    private Database $db;
    /** @var array<string, int|null> */
    private array $ticketIdCache = [];
    /** @var array<int, array{id:int, ticket_number:string}|null> */
    private array $ticketByIdCache = [];

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    public function render(string $body, bool $allowExternalLinks): string
    {
        $html = htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = $this->linkTickets($html);
        $html = $this->linkKnowledgeBase($html);
        $html = $this->markMentions($html);

        if ($allowExternalLinks) {
            $html = $this->linkExternalUrls($html);
        }

        return nl2br($html, false);
    }

    private function linkExternalUrls(string $html): string
    {
        return (string)preg_replace_callback(
            '~(?<!["\'])\bhttps?://[^\s<]+~i',
            static function (array $matches): string {
                $url = rtrim($matches[0], '.,;:)');
                $suffix = substr($matches[0], strlen($url));
                $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                return '<a href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer">' . $safeUrl . '</a>' . $suffix;
            },
            $html
        );
    }

    private function linkTickets(string $html): string
    {
        $html = (string)preg_replace_callback(
            '~(?<![\w/-])(ticket\s*)?#([A-Za-z0-9][A-Za-z0-9_-]{0,24}-\d{4}-\d{2}-\d{2}-\d{1,10})\b~i',
            function (array $matches): string {
                $labelPrefix = $matches[1] ?? '';
                $ticketNumber = $matches[2];
                $ticketId = $this->ticketIdForNumber($ticketNumber);
                if (!$ticketId) {
                    return $matches[0];
                }

                $safeLabel = htmlspecialchars($labelPrefix . '#' . $ticketNumber, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                return '<a href="#/tickets/' . $ticketId . '" class="chat-internal-link">' . $safeLabel . '</a>';
            },
            $html
        );

        return (string)preg_replace_callback(
            '~(?<![\w/-])(ticket\s*)?#(\d{1,10})\b~i',
            function (array $matches): string {
                $labelPrefix = $matches[1] ?? '';
                $id = (int)$matches[2];
                $ticket = $this->ticketForId($id);
                if (!$ticket) {
                    return $matches[0];
                }

                $safeLabel = htmlspecialchars($labelPrefix . '#' . $ticket['ticket_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                return '<a href="#/tickets/' . (int)$ticket['id'] . '" class="chat-internal-link">' . $safeLabel . '</a>';
            },
            $html
        );
    }

    private function ticketIdForNumber(string $ticketNumber): ?int
    {
        $key = strtoupper($ticketNumber);
        if (array_key_exists($key, $this->ticketIdCache)) {
            return $this->ticketIdCache[$key];
        }

        $row = $this->db->fetch(
            "SELECT id FROM tickets WHERE ticket_number = ? AND deleted_at IS NULL",
            [$ticketNumber]
        );
        $id = $row ? (int)$row['id'] : null;
        $this->ticketIdCache[$key] = $id ?: null;
        return $this->ticketIdCache[$key];
    }

    /**
     * @return array{id:int, ticket_number:string}|null
     */
    private function ticketForId(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        if (array_key_exists($id, $this->ticketByIdCache)) {
            return $this->ticketByIdCache[$id];
        }

        $row = $this->db->fetch(
            "SELECT id, ticket_number FROM tickets WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );
        $this->ticketByIdCache[$id] = $row
            ? ['id' => (int)$row['id'], 'ticket_number' => (string)$row['ticket_number']]
            : null;
        return $this->ticketByIdCache[$id];
    }

    private function linkKnowledgeBase(string $html): string
    {
        return (string)preg_replace_callback(
            '~(?<![\w/-])kb:([a-z0-9][a-z0-9-]{0,120})\b~i',
            static function (array $matches): string {
                $slug = strtolower($matches[1]);
                return '<a href="#/kb/' . htmlspecialchars($slug, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" class="chat-internal-link">kb:' . htmlspecialchars($slug, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
            },
            $html
        );
    }

    private function markMentions(string $html): string
    {
        return (string)preg_replace_callback(
            '~(?<![\w])@([a-z0-9][a-z0-9_-]{1,79})\b~i',
            static function (array $matches): string {
                $handle = strtolower($matches[1]);
                return '<span class="chat-mention">@' . htmlspecialchars($handle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
            },
            $html
        );
    }
}
