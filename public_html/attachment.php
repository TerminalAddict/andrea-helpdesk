<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createUnsafeImmutable($projectRoot);
$dotenv->safeLoad();

use Andrea\Helpdesk\Core\Database;
use Andrea\Helpdesk\Auth\JwtService;
use Andrea\Helpdesk\Tickets\AttachmentService;

function sendError(int $code, string $message): never {
    http_response_code($code);
    exit($message);
}

$id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$token = $_GET['token'] ?? '';

// Also accept Authorization header
if (!$token) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (str_starts_with($authHeader, 'Bearer ')) {
        $token = substr($authHeader, 7);
    }
}

if (!$id) {
    sendError(400, 'Bad Request');
}

try {
    $db = Database::getInstance();
    $attachmentService = new AttachmentService();

    // Load attachment record
    $attachment = $db->fetch(
        "SELECT a.*, t.customer_id, t.assigned_agent_id, t.deleted_at as ticket_deleted
         FROM attachments a
         JOIN tickets t ON t.id = a.ticket_id
         WHERE a.id = ?",
        [$id]
    );

    if (!$attachment) {
        sendError(404, 'Not Found');
    }

    // Authorise: check download_token OR valid JWT
    $authorised = false;

    // Method 1: signed download token
    if ($token && $attachmentService->verifyDownloadToken($token, $id)) {
        $authorised = true;
    }

    // Method 2: valid JWT (agent or customer)
    if (!$authorised && $token) {
        try {
            $jwtService = new JwtService();
            $payload = $jwtService->verify($token);

            if ($payload->type === 'agent') {
                $authorised = true; // Agents can access all attachments
            } elseif ($payload->type === 'customer') {
                // Customer must be the ticket owner or a participant
                $customer = $db->fetch(
                    "SELECT id FROM customers WHERE id = ?",
                    [$payload->sub]
                );
                if ($customer) {
                    if ($attachment['customer_id'] == $payload->sub) {
                        $authorised = true;
                    } else {
                        $participant = $db->fetch(
                            "SELECT id FROM ticket_participants WHERE ticket_id = ? AND customer_id = ?",
                            [$attachment['ticket_id'], $payload->sub]
                        );
                        $authorised = (bool)$participant;
                    }
                }
            }
        } catch (\Throwable) {
            // Invalid token - not authorised
        }
    }

    if (!$authorised) {
        sendError(403, 'Forbidden');
    }

    $storagePath = getenv('STORAGE_PATH') ?: '/var/www/andrea-helpdesk-storage';
    $filePath = $storagePath . '/attachments/' . $attachment['stored_path'];

    if (!file_exists($filePath) || !is_readable($filePath)) {
        sendError(404, 'File not found');
    }

    $filename = $attachment['filename'];
    $mimeType = $attachment['mime_type'] ?: 'application/octet-stream';
    $fileSize = filesize($filePath);

    // Inline for types browsers can render natively; force download for everything else
    $inlineTypes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
        'application/pdf',
        'text/plain', 'text/html', 'text/csv',
        'video/mp4', 'video/webm',
        'audio/mpeg', 'audio/wav', 'audio/ogg',
    ];
    $disposition = in_array($mimeType, $inlineTypes, true) ? 'inline' : 'attachment';

    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($filename) . '"');
    header('Content-Length: ' . $fileSize);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');

    readfile($filePath);
    exit;

} catch (\Throwable $e) {
    sendError(500, 'Server Error');
}
