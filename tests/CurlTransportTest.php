<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Tests;

use PHPUnit\Framework\TestCase;
use PrestoUniverse\PrestoPay\Http\CurlTransport;
use PrestoUniverse\PrestoPay\Http\HttpFailure;

// Drives real sockets through each way a request can fail, proving that
// CurlTransport's requestNotSent classification agrees with what actually
// happened on the wire. A change to the cURL error codes it treats as "not
// sent" should fail one of these tests instead of silently changing retry
// and idempotency behaviour.
final class CurlTransportTest extends TestCase
{
    public function testUnresolvableHostIsNotSent(): void
    {
        $error = $this->attempt('https://this-host-does-not-exist.invalid./', 5.0);
        self::assertTrue($error->requestNotSent, 'DNS resolution never reaches a connection to write on');
    }

    public function testConnectionRefusedIsNotSent(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, $errstr);
        $port = self::portOf($server);
        fclose($server); // nothing listens here now

        $error = $this->attempt("http://127.0.0.1:$port/", 5.0);
        self::assertTrue($error->requestNotSent, 'a refused connection never wrote anything');
    }

    public function testSelfSignedCertificateIsNotSent(): void
    {
        $server = $this->startFixtureServer('tls-selfsigned');
        try {
            $error = $this->attempt("https://127.0.0.1:{$server['port']}/", 5.0);
            self::assertTrue($error->requestNotSent, 'the TLS handshake fails before any HTTP bytes are sent');
        } finally {
            self::stopFixtureServer($server);
        }
    }

    public function testBlackholedConnectIsAmbiguous(): void
    {
        // Deliberately never accept: the TCP handshake still completes at
        // the kernel's backlog queue, so cURL can write the request into
        // the (unread) receive buffer, but no response ever comes.
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, $errstr);
        $port = self::portOf($server);

        try {
            $error = $this->attempt("http://127.0.0.1:$port/", 1.0);
            self::assertFalse($error->requestNotSent, 'a connect that completed and then stalled may have written the request');
        } finally {
            fclose($server);
        }
    }

    public function testConnectionClosedAfterRequestReadIsAmbiguous(): void
    {
        $server = $this->startFixtureServer('accept-and-close');
        try {
            $error = $this->attempt("http://127.0.0.1:{$server['port']}/", 5.0);
            self::assertFalse($error->requestNotSent, 'the server read the request before closing the connection');
        } finally {
            self::stopFixtureServer($server);
        }
    }

    private function attempt(string $url, float $timeoutSeconds): HttpFailure
    {
        try {
            (new CurlTransport())->post($url, '{}', $timeoutSeconds);
        } catch (HttpFailure $error) {
            return $error;
        }
        self::fail('expected the request to fail');
    }

    /** @return array{process: resource, pipes: list<resource>, port: int} */
    private function startFixtureServer(string $mode): array
    {
        $php = PHP_BINARY;
        $script = __DIR__ . '/fixtures/loopback-server.php';
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([$php, $script, $mode], $descriptors, $pipes);
        self::assertIsResource($process, 'failed to start the loopback fixture server');

        $line = fgets($pipes[1]);
        if ($line === false || !str_starts_with($line, 'PORT=')) {
            $stderr = stream_get_contents($pipes[2]);
            proc_terminate($process);
            self::fail("loopback fixture server did not report a port: $stderr");
        }

        return ['process' => $process, 'pipes' => $pipes, 'port' => (int) substr(trim($line), 5)];
    }

    /** @param array{process: resource, pipes: list<resource>, port: int} $server */
    private static function stopFixtureServer(array $server): void
    {
        foreach ($server['pipes'] as $pipe) {
            fclose($pipe);
        }
        proc_terminate($server['process']);
        proc_close($server['process']);
    }

    /** @param resource $server */
    private static function portOf($server): int
    {
        $name = stream_socket_get_name($server, false);
        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
