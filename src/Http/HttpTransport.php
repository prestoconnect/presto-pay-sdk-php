<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Http;

interface HttpTransport
{
    public function post(string $url, string $body, float $timeoutSeconds): HttpResponse;
}
