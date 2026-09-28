<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Http;

final class CurlTransport implements HttpTransport
{
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
            // These errors occur before a connection can carry request bytes.
            $notSent = in_array($code, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_RESOLVE_PROXY, CURLE_COULDNT_CONNECT], true);
            throw new HttpFailure('HTTP transport failed: ' . $message, $notSent);
        }
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        return new HttpResponse($status, $headers, is_string($response) ? $response : '');
    }
}
