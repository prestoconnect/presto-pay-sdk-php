<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ActivityStore;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use PrestoUniverse\PrestoPay\Exception\PrestoPayException;
use PrestoUniverse\PrestoPay\Exception\SignatureException;
use PrestoUniverse\PrestoPay\PrestoPay;
use PrestoUniverse\PrestoPay\Request\QueryRequest;
use PrestoUniverse\PrestoPay\Webhook\NotifyAck;
use PrestoUniverse\PrestoPay\Webhook\WebhookVerifier;

class WebhookController extends Controller
{
    public function __construct(
        private readonly WebhookVerifier $webhookVerifier,
        private readonly ActivityStore $activityStore,
        private readonly PrestoPay $presto,
    ) {}

    public function notify(Request $request): Response
    {
        try {
            $event = $this->webhookVerifier->verify($request->getContent());
        } catch (SignatureException $error) {
            Log::warning('Webhook rejected: ' . $error->getMessage());
            return response('', 401);
        } catch (\Throwable $error) {
            Log::warning('Webhook rejected: ' . $error->getMessage());
            return response(NotifyAck::forThrowable($error)->body(), 200)
                ->header('Content-Type', 'application/json');
        }

        try {
            $payment = $this->presto->payments()->query(new QueryRequest(
                prestoMrn: $event->prestoMrn,
                paymentRefNum: $event->paymentRefNum,
            ));
        } catch (PrestoPayException $error) {
            // A webhook says what happened, not the payment's resulting status, so the status comes from query.
            // If that fails, ask Presto to redeliver rather than acknowledging an event that was never processed.
            Log::warning('Webhook txnRefNum=' . $event->txnRefNum . ' not processed, query failed: ' . $error->getMessage());
            return response(NotifyAck::Resend->body(), 200)
                ->header('Content-Type', 'application/json');
        }

        Log::info(sprintf(
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
            'receivedAt' => now()->toAtomString(),
            'eventCode' => $event->eventCode,
            'paymentStatus' => $payment->paymentStatus,
            'txnRefNum' => $event->txnRefNum,
            'paymentRefNum' => $event->paymentRefNum,
            'success' => $event->success,
            'amountMinorUnits' => $event->amount,
            'currencyCode' => $event->currencyCode,
        ]);

        return response(NotifyAck::Ok->body(), 200)->header('Content-Type', 'application/json');
    }
}
