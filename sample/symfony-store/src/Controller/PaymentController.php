<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\PrestoPayFactory;
use PrestoUniverse\PrestoPay\Exception\PrestoPayException;
use PrestoUniverse\PrestoPay\PrestoPay;
use PrestoUniverse\PrestoPay\Request\QueryRequest;
use PrestoUniverse\PrestoPay\Request\RefundRequest;
use PrestoUniverse\PrestoPay\Request\ReverseRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Curl-friendly JSON routes, matching the Go SDK sample and the plain-PHP sample's equivalents. */
final class PaymentController
{
    public function __construct(private readonly PrestoPay $presto, private readonly PrestoPayFactory $prestoPayFactory) {}

    #[Route('/payments/{paymentRefNum}', name: 'payments_query', methods: ['GET'])]
    public function query(string $paymentRefNum): JsonResponse
    {
        try {
            $result = $this->presto->payments()->query(new QueryRequest(
                prestoMrn: $this->prestoPayFactory->merchantRefNum(),
                paymentRefNum: $paymentRefNum,
            ));
        } catch (PrestoPayException $error) {
            return $this->errorResponse($error);
        }
        return new JsonResponse($result);
    }

    #[Route('/payments/{paymentRefNum}/reverse', name: 'payments_reverse', methods: ['POST'])]
    public function reverse(string $paymentRefNum): JsonResponse
    {
        try {
            $result = $this->presto->payments()->reverse(new ReverseRequest(
                prestoMrn: $this->prestoPayFactory->merchantRefNum(),
                paymentRefNum: $paymentRefNum,
                reversalRefNum: 'rev-' . bin2hex(random_bytes(8)),
                remark: 'Requested via demo',
            ));
        } catch (PrestoPayException $error) {
            return $this->errorResponse($error);
        }
        return new JsonResponse($result);
    }

    #[Route('/payments/{paymentRefNum}/refund', name: 'payments_refund', methods: ['POST'])]
    public function refund(string $paymentRefNum): JsonResponse
    {
        try {
            $result = $this->presto->payments()->refund(new RefundRequest(
                prestoMrn: $this->prestoPayFactory->merchantRefNum(),
                paymentRefNum: $paymentRefNum,
                refundRefNum: 'rfnd-' . bin2hex(random_bytes(8)),
                remark: 'Requested via demo',
            ));
        } catch (PrestoPayException $error) {
            return $this->errorResponse($error);
        }
        return new JsonResponse($result);
    }

    private function errorResponse(PrestoPayException $error): JsonResponse
    {
        return new JsonResponse([
            'message' => $error->getMessage(),
            'mayHaveTakenEffect' => $error->mayHaveTakenEffect(),
            'reconcileBy' => $error->reconcileBy(),
        ], 502);
    }
}
