<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Http;

final class CurlTransport implements HttpTransport
{
    /**
     * cURL error codes that can only occur before any request byte reaches
     * the wire: DNS resolution, TCP connect, and the TLS handshake all fail
     * before HTTP transmission starts. 51 is CURLE_PEER_FAILED_VERIFICATION,
     * which PHP does not expose as a named constant.
     *
     * @var list<int>
     */
    private const REQUEST_NOT_SENT_ERRORS = [
        CURLE_COULDNT_RESOLVE_PROXY,
        CURLE_COULDNT_RESOLVE_HOST,
        CURLE_COULDNT_CONNECT,
        CURLE_SSL_CONNECT_ERROR,
        CURLE_SSL_CERTPROBLEM,
        CURLE_SSL_CIPHER,
        CURLE_SSL_CACERT,
        51,
        CURLE_SSL_CACERT_BADFILE,
        CURLE_SSL_PINNEDPUBKEYNOTMATCH,
    ];

    public function post(string $url, string $body, float $timeoutSeconds): HttpResponse
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new HttpFailure('Could not initialize cURL', true);
        }
        $headers = [];
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=UTF-8', 'User-Agent: presto-pay-sdk-php/0.1.0'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => max(1, (int) ceil($timeoutSeconds * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) ceil($timeoutSeconds * 1000)),
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        $response = curl_exec($handle);
        if ($response === false) {
            $code = curl_errno($handle);
            $message = curl_error($handle);
            $notSent = in_array($code, self::REQUEST_NOT_SENT_ERRORS, true);
            throw new HttpFailure('HTTP transport failed: ' . $message, $notSent);
        }
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        return new HttpResponse($status, $headers, is_string($response) ? $response : '');
    }
}
