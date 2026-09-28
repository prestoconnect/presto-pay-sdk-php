<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Request;

use PrestoUniverse\PrestoPay\Exception\ConfigException;

final readonly class LineItem
{
    public function __construct(
        public string $itemDesc,
        public int $quantity,
        public int $unitAmount,
        public int $totalAmount,
        public ?string $imageUrl = null,
        public ?string $itemUrl = null,
        public ?string $category = null,
        public ?string $categoryDesc = null,
        public ?string $supplier = null,
        public ?string $supplierDesc = null,
        public ?string $supplierUrl = null,
    ) {
        if ($itemDesc === '' || $quantity <= 0 || $unitAmount < 0 || $totalAmount < 0) {
            throw new ConfigException('Invalid line item');
        }
    }

    /** @return array<string, mixed> */
    public function toWire(): array
    {
        /** @var array<string, mixed> $fields */
        $fields = get_object_vars($this);
        return array_filter($fields, static fn (mixed $value): bool => $value !== null);
    }
}
