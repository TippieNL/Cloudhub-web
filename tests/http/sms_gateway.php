<?php
declare(strict_types=1);

/**
 * A stand-in SMS gateway for the HTTP suite: the target of SMS_DRIVER=webhook.
 *
 *   php -S 127.0.0.1:<port> tests/http/sms_gateway.php   (SMS_GATEWAY_DIR set)
 *
 * Records every message it is handed in received.jsonl, and answers the way
 * the file `mode` says: ok, fail (500), reject (400), slow (past the
 * client's timeout) or garbage (200 that is not JSON). That is how the suite
 * reads the codes CloudHub sends, and how it makes the gateway misbehave.
 */
$dir = (string)getenv('SMS_GATEWAY_DIR');
$mode = trim((string)@file_get_contents($dir.'/mode')) ?: 'ok';
$raw = (string)file_get_contents('php://input');
file_put_contents($dir.'/received.jsonl', json_encode([
    'mode' => $mode,
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
    'contentType' => $_SERVER['CONTENT_TYPE'] ?? '',
    'body' => json_decode($raw, true),
])."\n", FILE_APPEND);

switch ($mode) {
    case 'fail':
        http_response_code(500);
        echo 'gateway down';
        break;
    case 'reject':
        http_response_code(400);
        header('Content-Type: application/json');
        echo '{"error":"not a mobile number"}';
        break;
    case 'slow':
        sleep(4);
        echo '{"id":"too-late"}';
        break;
    case 'garbage':
        header('Content-Type: text/html');
        echo '<html>accepted</html>';
        break;
    default:
        header('Content-Type: application/json');
        echo json_encode(['id' => 'msg-'.bin2hex(random_bytes(4))]);
}
