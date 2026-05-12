<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Chat;

class ChatRenderService
{
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
        return (string)preg_replace_callback(
            '~(?<![\w/-])(?:ticket\s*)?#(\d{1,10})\b~i',
            static function (array $matches): string {
                $id = (int)$matches[1];
                return '<a href="#/tickets/' . $id . '" class="chat-internal-link">#' . $id . '</a>';
            },
            $html
        );
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
