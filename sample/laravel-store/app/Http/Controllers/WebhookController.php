<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ActivityStore;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use PrestoUniverse\PrestoPay\Webhook\NotifyAck;
use PrestoUniverse\PrestoPay\Webhook\WebhookVerifier;

class WebhookController extends Controller
{
    public function __construct(private readonly WebhookVerifier $webhookVerifier, private readonly ActivityStore $activityStore) {}

    public function notify(Request $request): Response
    {
        try {
            $event = $this->webhookVerifier->verify($request->getContent());
        } catch (\Throwable $error) {
            Log::warning('Webhook rejected: ' . $error->getMessage());
            return response(NotifyAck::forThrowable($error)->body(), 200)
                ->header('Content-Type', 'application/json');
        }

        $firstDelivery = $this->activityStore->recordWebhook($event->eventRefNum, [
            'receivedAt' => now()->toAtomString(),
            'eventCode' => $event->eventCode,
            'paymentStatus' => $event->paymentStatus,
            'txnRefNum' => $event->txnRefNum,
            'paymentRefNum' => $event->paymentRefNum,
            'success' => $event->success,
            'amountMinorUnits' => $event->amount,
            'currencyCode' => $event->currencyCode,
        ]);

        if ($firstDelivery) {
            Log::info(sprintf(
                'Webhook verified eventCode=%s paymentStatus=%s txnRefNum=%s paymentRefNum=%s eventRefNum=%s',
                $event->eventCode,
                $event->paymentStatus,
                $event->txnRefNum,
                $event->paymentRefNum,
                $event->eventRefNum,
            ));
        } else {
            Log::info('Duplicate webhook delivery eventRefNum=' . $event->eventRefNum . '; acking without refulfilling');
        }

        return response(NotifyAck::Ok->body(), 200)->header('Content-Type', 'application/json');
    }
}
