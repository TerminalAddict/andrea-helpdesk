<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Tickets;

use Andrea\Helpdesk\Core\Database;
use Andrea\Helpdesk\Core\Exceptions\HttpException;

class AttachmentService
{
    private string $storagePath;
    private int $maxSize;
    private array $allowedMimeTypes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain', 'text/csv', 'text/html',
        'application/zip', 'application/x-zip-compressed',
        'application/x-tar', 'application/gzip',
        'video/mp4', 'video/mpeg', 'audio/mpeg', 'audio/wav',
        'application/octet-stream',
    ];

    public function __construct()
    {
        $this->storagePath = rtrim(getenv('STORAGE_PATH') ?: '/var/www/andrea-helpdesk-storage', '/');
        $this->maxSize     = (int)(getenv('MAX_ATTACHMENT_SIZE') ?: 10485760);
    }

    /**
     * Store an uploaded file. $file is a $_FILES entry.
     */
    public function store(int $ticketId, array $file, ?int $replyId = null, ?int $agentId = null, ?int $customerId = null): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new HttpException('File upload error: ' . $this->uploadErrorMessage($file['error']), 400);
        }

        if ($file['size'] > $this->maxSize) {
            $maxMb = round($this->maxSize / 1048576, 1);
            throw new HttpException("File size exceeds maximum allowed ({$maxMb} MB)", 400);
        }

        $mimeType = mime_content_type($file['tmp_name']) ?: ($file['type'] ?? 'application/octet-stream');
        $originalName = $this->sanitiseFilename($file['name']);

        // Build storage path: {ticketId}/{uniqid}_{filename}
        $subDir      = $ticketId . ($replyId ? "/{$replyId}" : '');
        $uniqueFile  = uniqid('', true) . '_' . $originalName;
        $relativePath = $subDir . '/' . $uniqueFile;
        $absolutePath = $this->storagePath . '/attachments/' . $relativePath;

        // Ensure directory exists
        $dir = dirname($absolutePath);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new HttpException('Storage directory could not be created', 500);
        }

        if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
            throw new HttpException('Failed to save uploaded file', 500);
        }

        $db    = Database::getInstance();
        $token = $this->generateDownloadToken(0); // Temporary, will update after insert

        $id = $db->insert(
            "INSERT INTO attachments (ticket_id, reply_id, filename, stored_path, mime_type, size_bytes, uploaded_by_agent_id, uploaded_by_customer_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$ticketId, $replyId, $originalName, $relativePath, $mimeType, $file['size'], $agentId, $customerId]
        );

        // Generate and store proper download token
        $token = $this->generateDownloadToken($id);
        $db->execute("UPDATE attachments SET download_token = ? WHERE id = ?", [$token, $id]);

        return $db->fetch("SELECT * FROM attachments WHERE id = ?", [$id]) ?? [];
    }

    /**
     * Store raw binary data (from IMAP attachment).
     */
    public function storeRaw(int $ticketId, string $filename, string $data, string $mimeType, ?int $replyId = null): array
    {
        $originalName = $this->sanitiseFilename($filename);
        $subDir       = $ticketId . ($replyId ? "/{$replyId}" : '');
        $uniqueFile   = uniqid('', true) . '_' . $originalName;
        $relativePath = $subDir . '/' . $uniqueFile;
        $absolutePath = $this->storagePath . '/attachments/' . $relativePath;

        $dir = dirname($absolutePath);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Storage directory could not be created');
        }

        file_put_contents($absolutePath, $data);

        $db = Database::getInstance();
        $id = $db->insert(
            "INSERT INTO attachments (ticket_id, reply_id, filename, stored_path, mime_type, size_bytes)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$ticketId, $replyId, $originalName, $relativePath, $mimeType, strlen($data)]
        );

        $token = $this->generateDownloadToken($id);
        $db->execute("UPDATE attachments SET download_token = ? WHERE id = ?", [$token, $id]);

        return $db->fetch("SELECT * FROM attachments WHERE id = ?", [$id]) ?? [];
    }

    public function delete(int $attachmentId): bool
    {
        $db         = Database::getInstance();
        $attachment = $db->fetch("SELECT * FROM attachments WHERE id = ?", [$attachmentId]);

        if (!$attachment) return false;

        $filePath = $this->storagePath . '/attachments/' . $attachment['stored_path'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        return $db->execute("DELETE FROM attachments WHERE id = ?", [$attachmentId]);
    }

    public function getStoredPath(string $relativePath): string
    {
        return $this->storagePath . '/attachments/' . $relativePath;
    }

    public function generateDownloadToken(int $attachmentId): string
    {
        $secret  = (string)getenv('JWT_SECRET');
        $payload = json_encode(['id' => $attachmentId, 'exp' => time() + 86400]);
        return hash_hmac('sha256', $payload, $secret) . '.' . base64_encode($payload);
    }

    public function verifyDownloadToken(string $token, int $attachmentId): bool
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) return false;

        [$hmac, $payloadB64] = $parts;
        $payload = json_decode(base64_decode($payloadB64), true);
        if (!$payload) return false;

        $secret       = (string)getenv('JWT_SECRET');
        $expectedHmac = hash_hmac('sha256', base64_decode($payloadB64), $secret);

        if (!hash_equals($expectedHmac, $hmac)) return false;
        if ($payload['exp'] < time()) return false;
        if ($payload['id'] !== $attachmentId) return false;

        return true;
    }

    public function getAttachmentsForReply(int $replyId): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT * FROM attachments WHERE reply_id = ?",
            [$replyId]
        );
    }

    public function getAttachmentsForTicket(int $ticketId): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT * FROM attachments WHERE ticket_id = ? ORDER BY created_at ASC",
            [$ticketId]
        );
    }

    private function sanitiseFilename(string $filename): string
    {
        $filename = basename($filename);
        $filename = preg_replace('/[^\w\s\-\.]/u', '', $filename);
        $filename = preg_replace('/\s+/', '_', $filename);
        return substr($filename, 0, 255) ?: 'file';
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write to disk',
            default               => "Upload error code {$code}",
        };
    }
}
