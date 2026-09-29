<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ActivityStore;
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
    ) {}

    #[Route('/presto/notify', name: 'presto_notify', methods: ['POST'])]
    public function notify(Request $request): Response
    {
        try {
            $event = $this->webhookVerifier->verify($request->getContent());
        } catch (\Throwable $error) {
            $this->logger->warning('Webhook rejected: ' . $error->getMessage());
            return new Response(NotifyAck::forThrowable($error)->body(), 200, ['Content-Type' => 'application/json']);
        }

        $firstDelivery = $this->activityStore->recordWebhook($event->eventRefNum, [
            'receivedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'eventCode' => $event->eventCode,
            'paymentStatus' => $event->paymentStatus,
            'txnRefNum' => $event->txnRefNum,
            'paymentRefNum' => $event->paymentRefNum,
            'success' => $event->success,
            'amountMinorUnits' => $event->amount,
            'currencyCode' => $event->currencyCode,
        ]);

        if ($firstDelivery) {
            $this->logger->info(sprintf(
                'Webhook verified eventCode=%s paymentStatus=%s txnRefNum=%s paymentRefNum=%s eventRefNum=%s',
                $event->eventCode,
                $event->paymentStatus,
                $event->txnRefNum,
                $event->paymentRefNum,
                $event->eventRefNum,
            ));
        } else {
            $this->logger->info('Duplicate webhook delivery eventRefNum=' . $event->eventRefNum . '; acking without refulfilling');
        }

        return new Response(NotifyAck::Ok->body(), 200, ['Content-Type' => 'application/json']);
    }
}
