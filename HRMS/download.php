<?php
/**
 * Secure download of an incentive supporting document.
 * Admin: any document. Staff: only documents of their own incentives.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
$user = require_login();

$id = get_int('id');
if (get_str('type', 20) !== 'incentive' || $id <= 0) {
    http_response_code(404);
    exit('Not found.');
}
$st = db()->prepare('SELECT staff_id, document_path, document_name FROM incentives WHERE id = ?');
$st->execute([$id]);
$row = $st->fetch();

if (!$row || empty($row['document_path'])
    || ($user['role'] !== 'admin' && (int)$row['staff_id'] !== (int)$user['staff_id'])) {
    http_response_code(404);
    exit('Not found.');
}

$path = ROOT_PATH . '/uploads/documents/' . basename($row['document_path']);
if (!is_file($path)) {
    http_response_code(404);
    exit('The file is missing on the server.');
}
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'][$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', (string)$row['document_name']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
