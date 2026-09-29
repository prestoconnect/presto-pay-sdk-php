<?php

declare(strict_types=1);

// A standalone loopback TCP server for the CurlTransport socket suite. It
// runs as a separate OS process (via proc_open) so it can accept a
// connection concurrently with the parent process's blocking curl_exec
// call, which a single PHP thread cannot do on its own.
//
// Usage: php loopback-server.php <mode>
//   accept-and-close  Accept one connection, read whatever arrives, then
//                     close without ever sending a response.
//   tls-selfsigned    Accept one TLS connection using a freshly generated,
//                     untrusted self-signed certificate.
//
// Prints "PORT=<n>" followed by a newline once listening, then blocks
// until one connection has been handled.

$mode = $argv[1] ?? '';

if ($mode === 'tls-selfsigned') {
    $privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if ($privateKey === false) {
        fwrite(STDERR, 'openssl_pkey_new failed' . PHP_EOL);
        exit(1);
    }
    $csr = openssl_csr_new(['commonName' => 'presto-pay-sdk-php loopback test'], $privateKey, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $privateKey, 1, ['digest_alg' => 'sha256']);
    openssl_x509_export($cert, $certPem);
    openssl_pkey_export($privateKey, $keyPem);
    $certFile = tempnam(sys_get_temp_dir(), 'ppcert');
    $keyFile = tempnam(sys_get_temp_dir(), 'ppkey');
    file_put_contents($certFile, $certPem);
    file_put_contents($keyFile, $keyPem);
    register_shutdown_function(static function () use ($certFile, $keyFile): void {
        @unlink($certFile);
        @unlink($keyFile);
    });
    $context = stream_context_create(['ssl' => ['local_cert' => $certFile, 'local_pk' => $keyFile]]);
    $server = stream_socket_server('ssl://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
} elseif ($mode === 'accept-and-close') {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
} else {
    fwrite(STDERR, "unknown mode: $mode" . PHP_EOL);
    exit(1);
}

if ($server === false) {
    fwrite(STDERR, "listen failed: $errstr" . PHP_EOL);
    exit(1);
}

$name = stream_socket_get_name($server, false);
$port = (int) substr($name, (int) strrpos($name, ':') + 1);
echo "PORT=$port" . PHP_EOL;
fflush(STDOUT);

$connection = @stream_socket_accept($server, 10.0);
if ($connection === false) {
    exit(0);
}

stream_set_timeout($connection, 5);
$buffer = '';
while (!feof($connection) && !str_contains($buffer, "\r\n\r\n")) {
    $chunk = fread($connection, 4096);
    if ($chunk === false || $chunk === '') {
        break;
    }
    $buffer .= $chunk;
}

fclose($connection);
fclose($server);
