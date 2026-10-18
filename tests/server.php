<?php
declare(strict_types=1);

$scenario = json_decode((string) fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    throw new RuntimeException('Could not bind fixture');
}
echo substr(strrchr(stream_socket_get_name($server, false), ':'), 1) . "\n";
flush();
$end = microtime(true) + 20;
$attempt = 0;
while (microtime(true) < $end) {
    $connection = @stream_socket_accept($server, 1);
    if ($connection === false) {
        continue;
    }
    stream_set_timeout($connection, 2);
    $line = fgets($connection);
    if ($line === false) {
        fclose($connection);
        continue;
    }
    $headers = [];
    while (($header = fgets($connection)) !== false && trim($header) !== '') {
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }
    if (strtolower($headers['expect'] ?? '') === '100-continue') {
        fwrite($connection, "HTTP/1.1 100 Continue\r\n\r\n");
    }
    $length = min(1048576, max(0, (int) ($headers['content-length'] ?? 0)));
    $body = '';
    while (strlen($body) < $length) {
        $chunk = fread($connection, $length - strlen($body));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }
    file_put_contents($scenario['log'], json_encode([
        'line' => trim($line), 'headers' => $headers, 'body' => $body, 'time' => microtime(true),
    ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    $response = $scenario['responses'][min($attempt++, count($scenario['responses']) - 1)];
    if ($response['drop'] ?? false) {
        fclose($connection);
        continue;
    }
    usleep((int) (($response['delay'] ?? 0) * 1000000));
    $body = $response['body'] ?? '{}';
    $status = $response['status'] ?? 200;
    $wire = "HTTP/1.1 $status Fixture\r\nContent-Type: application/json\r\nConnection: close\r\nContent-Length: "
        . ($response['length'] ?? strlen($body)) . "\r\n";
    foreach ($response['headers'] ?? [] as $name => $value) {
        $wire .= "$name: $value\r\n";
    }
    @fwrite($connection, $wire . "\r\n");
    usleep((int) (($response['bodyDelay'] ?? 0) * 1000000));
    $offset = 0;
    while ($offset < strlen($body)) {
        $written = @fwrite($connection, substr($body, $offset));
        if ($written === false || $written === 0) {
            break;
        }
        $offset += $written;
    }
    fclose($connection);
}
fclose($server);
