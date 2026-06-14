<?php
/**
 * CUEA FYPM – Global Helpers
 */

/**
 * Send a JSON response and terminate.
 *
 * @param int    $status  HTTP status code
 * @param string $message Human-readable message
 * @param array  $data    Optional payload
 */
function jsonResponse(int $status, string $message, array $data = []): void {
    http_response_code($status);
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    echo json_encode([
        'status'  => $status,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Sanitize a string value from user input.
 * Strips tags, trims whitespace, and escapes HTML entities.
 */
function sanitize(string $input): string {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}
