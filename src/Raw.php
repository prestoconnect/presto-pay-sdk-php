<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay;

final readonly class Raw
{
    public function __construct(private PrestoPay $client) {}

    /** @param array<string, mixed> $body */
    public function post(string $path, array $body): \stdClass
    {
        return $this->client->post('raw', $path, $body);
    }
}
