<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Request;

use PrestoUniverse\PrestoPay\Exception\ConfigException;
use PrestoUniverse\PrestoPay\Internal\JsonCodec;
use PrestoUniverse\PrestoPay\TxnType;

final readonly class InitRequest
{
    /**
     * @param list<LineItem>|null $itemList
     * @param list<string>|null $allowedPaymentMethods
     */
    public function __construct(
        public string $prestoMrn,
        public TxnType $txnType,
        public string $txnRefNum,
        public string $displayDesc,
        public ?int $amount = null,
        public ?string $currencyCode = null,
        public ?string $notifyUrl = null,
        public ?string $redirectUrl = null,
        public ?string $qrValue = null,
        public ?string $payerRefNum = null,
        public ?string $deviceRefNum = null,
        public ?string $deviceIp = null,
        public ?array $itemList = null,
        public ?string $transactionalData = null,
        public ?string $sessionValidity = null,
        public ?string $additionalData = null,
        public ?string $mode = null,
        public ?string $modeData = null,
        public ?array $allowedPaymentMethods = null,
        public ?string $bindData = null,
        public ?string $themeRefNum = null,
        public ?string $receiptEmail = null,
        public ?string $receiptName = null,
    ) {
        if ($prestoMrn === '' || $txnRefNum === '' || $displayDesc === '') {
            throw new ConfigException('prestoMrn, txnRefNum and displayDesc are required');
        }
        if ($amount !== null && ($amount <= 0 || $amount > 9007199254740991)) {
            throw new ConfigException('amount must be a positive safe integer');
        }
        if ($amount !== null && ($currencyCode === null || $currencyCode === '')) {
            throw new ConfigException('currencyCode is required with amount');
        }
        if ($qrValue !== null && $payerRefNum !== null) {
            throw new ConfigException('qrValue and payerRefNum are mutually exclusive');
        }
        if ($txnType === TxnType::WebPay && ($redirectUrl === null || $redirectUrl === '')) {
            throw new ConfigException('redirectUrl is required for WebPay');
        }
        foreach ($allowedPaymentMethods ?? [] as $method) {
            if ($method === '') {
                throw new ConfigException('allowedPaymentMethods must contain nonempty strings');
            }
        }
    }

    /** @return array<string, mixed> */
    public function toWire(): array
    {
        /** @var array<string, mixed> $fields */
        $fields = get_object_vars($this);
        $fields['txnType'] = $this->txnType->value;
        if ($this->itemList !== null) {
            $fields['itemList'] = JsonCodec::encode(array_map(static fn (LineItem $item): array => $item->toWire(), $this->itemList));
        }
        if ($this->allowedPaymentMethods !== null) {
            $fields['allowedPaymentMethods'] = JsonCodec::encode($this->allowedPaymentMethods);
        }
        return array_filter($fields, static fn (mixed $value): bool => $value !== null);
    }
}
