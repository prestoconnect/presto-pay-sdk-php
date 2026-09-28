<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Webhook;

use PrestoUniverse\PrestoPay\Exception\ConfigException;
use PrestoUniverse\PrestoPay\Exception\ResponseException;
use PrestoUniverse\PrestoPay\Exception\SignatureException;
use PrestoUniverse\PrestoPay\Internal\JsonCodec;
use PrestoUniverse\PrestoPay\Key\PublicKey;
use PrestoUniverse\PrestoPay\Result\Fields;
use PrestoUniverse\PrestoPay\Result\PaymentDetail;
use PrestoUniverse\PrestoPay\Timestamp;
use PrestoUniverse\PrestoPay\Verifier;
use Psr\Http\Message\ServerRequestInterface;

final readonly class WebhookVerifier
{
    /**
     * @param list<string> $merchantIds
     * @param list<PublicKey> $prestoPublicKeys
     */
    public function __construct(private array $merchantIds, private array $prestoPublicKeys, private int $maxTimestampAge = 900)
    {
        if ($merchantIds === [] || $prestoPublicKeys === [] || $maxTimestampAge <= 0) {
            throw new ConfigException('Webhook verifier needs merchant IDs, public keys and a positive freshness window');
        }
    }

    public function verify(string $rawBody, ?int $nowMillis = null): WebhookEvent
    {
        try {
            $body = JsonCodec::decode($rawBody);
            if (!Verifier::verifyBody($body, $this->prestoPublicKeys)) {
                throw new SignatureException('Webhook signature is invalid', 'webhook');
            }
            $eventCode = Fields::requiredString($body, 'eventCode');
            $mid = Fields::requiredString($body, 'mid');
            $prestoMrn = Fields::requiredString($body, 'prestoMrn');
            $paymentRefNum = Fields::requiredString($body, 'paymentRefNum');
            $txnRefNum = Fields::requiredString($body, 'txnRefNum');
            $eventRefNum = Fields::requiredString($body, 'eventRefNum');
            $eventTs = Fields::requiredString($body, 'eventTs');
            $currencyCode = Fields::requiredString($body, 'currencyCode');
            $ts = Fields::requiredString($body, 'ts');
            if (!isset($body->amount) || !is_int($body->amount) || !isset($body->success) || !is_bool($body->success)) {
                throw new ResponseException('Webhook amount or success field is invalid');
            }
            if (!in_array($mid, $this->merchantIds, true)) {
                throw new SignatureException('Webhook merchant ID is not configured', 'webhook');
            }
            $sentAt = Timestamp::parse($ts);
            $now = $nowMillis ?? (int) floor(microtime(true) * 1000);
            if (abs($now - $sentAt) > $this->maxTimestampAge * 1000) {
                throw new SignatureException('Webhook timestamp is outside the freshness window', 'webhook');
            }
            return new WebhookEvent(
                $eventCode,
                $mid,
                $prestoMrn,
                $paymentRefNum,
                $txnRefNum,
                $eventRefNum,
                $eventTs,
                $body->amount,
                $currencyCode,
                $ts,
                $body->success,
                Fields::optionalString($body, 'userRefNum'),
                Fields::optionalString($body, 'additionalData'),
                array_map(static fn (\stdClass $item): PaymentDetail => new PaymentDetail($item), Fields::list($body, 'paymentDetails')),
                $eventCode === 'Authorised' && !$body->success ? 'Failed' : $eventCode,
                (array) $body,
            );
        } catch (ResponseException $error) {
            throw new ResponseException($error->getMessage(), 'webhook', false, null, $error);
        }
    }

    public function verifyServerRequest(ServerRequestInterface $request, ?int $nowMillis = null): WebhookEvent
    {
        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        } elseif ($stream->tell() !== 0) {
            throw new ResponseException('Webhook request body was already consumed', 'webhook');
        }
        return $this->verify($stream->getContents(), $nowMillis);
    }
}
