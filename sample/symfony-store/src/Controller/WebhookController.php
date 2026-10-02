<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ActivityStore;
use PrestoUniverse\PrestoPay\Exception\PrestoPayException;
use PrestoUniverse\PrestoPay\Exception\SignatureException;
use PrestoUniverse\PrestoPay\PrestoPay;
use PrestoUniverse\PrestoPay\Request\QueryRequest;
use PrestoUniverse\PrestoPay\Webhook\NotifyAck;
use PrestoUniverse\PrestoPay\Webhook\WebhookVerifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class WebhookController
{
    public function __construct(
        private readonly WebhookVerifier $webhookVerifier,
        private readonly ActivityStore $activityStore,
        private readonly LoggerInterface $logger,
        private readonly PrestoPay $presto,
    ) {}

    #[Route('/presto/notify', name: 'presto_notify', methods: ['POST'])]
    public function notify(Request $request): Response
    {
        try {
            $event = $this->webhookVerifier->verify($request->getContent());
        } catch (SignatureException $error) {
            $this->logger->warning('Webhook rejected: ' . $error->getMessage());
            return new Response('', 401);
        } catch (\Throwable $error) {
            $this->logger->warning('Webhook rejected: ' . $error->getMessage());
            return new Response(NotifyAck::forThrowable($error)->body(), 200, ['Content-Type' => 'application/json']);
        }

        try {
            $payment = $this->presto->payments()->query(new QueryRequest(
                prestoMrn: $event->prestoMrn,
                paymentRefNum: $event->paymentRefNum,
            ));
        } catch (PrestoPayException $error) {
            // A webhook says what happened, not the payment's resulting status, so the status comes from query.
            // If that fails, ask Presto to redeliver rather than acknowledging an event that was never processed.
            $this->logger->warning('Webhook txnRefNum=' . $event->txnRefNum . ' not processed, query failed: ' . $error->getMessage());
            return new Response(NotifyAck::Resend->body(), 200, ['Content-Type' => 'application/json']);
        }

        $this->logger->info(sprintf(
            'Webhook verified eventCode=%s success=%s queried paymentStatus=%s txnRefNum=%s paymentRefNum=%s',
            $event->eventCode,
            $event->success ? 'true' : 'false',
            (string) $payment->paymentStatus,
            $event->txnRefNum,
            $event->paymentRefNum,
        ));
        if ($payment->paymentStatus !== null) {
            $this->activityStore->applyPaymentStatus($event->txnRefNum, $payment->paymentStatus);
        }
        $this->activityStore->recordWebhook([
            'receivedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'eventCode' => $event->eventCode,
            'paymentStatus' => $payment->paymentStatus,
            'txnRefNum' => $event->txnRefNum,
            'paymentRefNum' => $event->paymentRefNum,
            'success' => $event->success,
            'amountMinorUnits' => $event->amount,
            'currencyCode' => $event->currencyCode,
        ]);

        return new Response(NotifyAck::Ok->body(), 200, ['Content-Type' => 'application/json']);
    }
}
