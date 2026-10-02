<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ActivityStore;
use PrestoUniverse\PrestoPay\Exception\PrestoPayException;
use PrestoUniverse\PrestoPay\PrestoPay;
use PrestoUniverse\PrestoPay\Request\QueryRequest;

class ReturnController extends Controller
{
    public function __construct(private readonly PrestoPay $presto, private readonly ActivityStore $activityStore) {}

    public function show(?string $txnRefNum = null)
    {
        if ($txnRefNum === null || $txnRefNum === '') {
            return view('checkout.return', [
                'missingTxnRefNum' => true,
                'recentWebhooks' => $this->activityStore->recentWebhooks(),
            ]);
        }

        $data = [
            'txnRefNum' => $txnRefNum,
            'checkout' => $this->activityStore->findCheckout($txnRefNum),
            'query' => null,
            'queryError' => null,
            'recentWebhooks' => $this->activityStore->recentWebhooks(),
        ];

        // This page's query and the /presto/notify webhook are triggered independently by Presto and can
        // arrive in either order, so both apply the queried status through the same guarded update and
        // whichever comes second changes nothing.
        try {
            $data['query'] = $this->presto->payments()->query(new QueryRequest(
                prestoMrn: (string) config('prestopay.mrn'),
                txnRefNum: $txnRefNum,
            ));
            if ($data['query']->paymentStatus !== null) {
                $this->activityStore->applyPaymentStatus($txnRefNum, $data['query']->paymentStatus);
            }
        } catch (PrestoPayException $error) {
            $data['queryError'] = $error->getMessage();
        }

        return view('checkout.return', $data);
    }
}
