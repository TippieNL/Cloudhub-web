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
 *
 * Two modes stand in for the SMS gateway apps SMS_WEBHOOK_FORMAT speaks to,
 * as strict as the apps themselves: traccar answers 401 unless Authorization
 * is the key itself, and 500 for a field it does not know (its JsonReader
 * cannot skip one); smsgate answers 401 without the right basic credentials
 * and 400 without textMessage.text and phoneNumbers, and 202 with an id.
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

$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$json = json_decode($raw, true);
switch ($mode) {
    case 'traccar':
        if ($auth !== 'traccar-test-key') { http_response_code(401); break; }
        if (!is_array($json) || array_diff(array_keys($json), ['to', 'message', 'slot']) !== []) {
            http_response_code(500);
            echo 'Expected a name but was STRING';
            break;
        }
        if (!is_string($json['to'] ?? null) || !is_string($json['message'] ?? null)) {
            http_response_code(500);
            echo 'Missing phone or message';
        }
        break;
    case 'smsgate':
        if ($auth !== 'Basic '.base64_encode('gw-user:gw-pass')) { http_response_code(401); break; }
        if (!is_string($json['textMessage']['text'] ?? null) || !is_array($json['phoneNumbers'] ?? null) || $json['phoneNumbers'] === []) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo '{"message":"invalid request"}';
            break;
        }
        http_response_code(202);
        header('Content-Type: application/json');
        echo json_encode(['id' => 'gate-'.bin2hex(random_bytes(4)), 'state' => 'Pending']);
        break;
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
