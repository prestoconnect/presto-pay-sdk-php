<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use PrestoUniverse\PrestoPay\Exception\PrestoPayException;
use PrestoUniverse\PrestoPay\PrestoPay;
use PrestoUniverse\PrestoPay\Request\QueryRequest;
use PrestoUniverse\PrestoPay\Request\RefundRequest;
use PrestoUniverse\PrestoPay\Request\ReverseRequest;

/** Curl-friendly JSON routes, matching the Go SDK sample and the plain-PHP sample's equivalents. */
class PaymentController extends Controller
{
    public function __construct(private readonly PrestoPay $presto) {}

    public function query(string $paymentRefNum): JsonResponse
    {
        try {
            $result = $this->presto->payments()->query(new QueryRequest(
                prestoMrn: (string) config('prestopay.mrn'),
                paymentRefNum: $paymentRefNum,
            ));
        } catch (PrestoPayException $error) {
            return $this->errorResponse($error);
        }
        return response()->json($result);
    }

    public function reverse(string $paymentRefNum): JsonResponse
    {
        try {
            $result = $this->presto->payments()->reverse(new ReverseRequest(
                prestoMrn: (string) config('prestopay.mrn'),
                paymentRefNum: $paymentRefNum,
                reversalRefNum: 'rev-' . Str::random(16),
                remark: 'Requested via demo',
            ));
        } catch (PrestoPayException $error) {
            return $this->errorResponse($error);
        }
        return response()->json($result);
    }

    public function refund(string $paymentRefNum): JsonResponse
    {
        try {
            $result = $this->presto->payments()->refund(new RefundRequest(
                prestoMrn: (string) config('prestopay.mrn'),
                paymentRefNum: $paymentRefNum,
                refundRefNum: 'rfnd-' . Str::random(16),
                remark: 'Requested via demo',
            ));
        } catch (PrestoPayException $error) {
            return $this->errorResponse($error);
        }
        return response()->json($result);
    }

    private function errorResponse(PrestoPayException $error): JsonResponse
    {
        return response()->json([
            'message' => $error->getMessage(),
            'mayHaveTakenEffect' => $error->mayHaveTakenEffect(),
            'reconcileBy' => $error->reconcileBy(),
        ], 502);
    }
}
