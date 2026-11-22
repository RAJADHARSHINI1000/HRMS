<?php
/**
 * CSRF protection for every state-changing (POST) request.
 */
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Stops the request when a POST arrives without a valid token. */
function csrf_verify(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    $sent = $_POST['csrf_token'] ?? '';
    $known = $_SESSION['csrf'] ?? '';
    if (!is_string($sent) || $sent === '' || $known === '' || !hash_equals($known, $sent)) {
        http_response_code(419);
        exit('Your form expired or is invalid. Please go back, refresh the page and try again.');
    }
}
