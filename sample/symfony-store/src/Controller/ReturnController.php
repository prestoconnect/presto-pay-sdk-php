<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ActivityStore;
use App\Service\PrestoPayFactory;
use PrestoUniverse\PrestoPay\Exception\PrestoPayException;
use PrestoUniverse\PrestoPay\PrestoPay;
use PrestoUniverse\PrestoPay\Request\QueryRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReturnController extends AbstractController
{
    public function __construct(
        private readonly PrestoPay $presto,
        private readonly PrestoPayFactory $prestoPayFactory,
        private readonly ActivityStore $activityStore,
    ) {}

    #[Route('/return/{txnRefNum}', name: 'return_show', methods: ['GET'], defaults: ['txnRefNum' => null])]
    public function show(?string $txnRefNum = null): Response
    {
        if ($txnRefNum === null || $txnRefNum === '') {
            return $this->render('checkout/return.html.twig', [
                'missingTxnRefNum' => true,
                'txnRefNum' => null,
                'checkout' => null,
                'query' => null,
                'queryError' => null,
                'recentWebhooks' => $this->activityStore->recentWebhooks(),
            ]);
        }

        $data = [
            'missingTxnRefNum' => false,
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
                prestoMrn: $this->prestoPayFactory->merchantRefNum(),
                txnRefNum: $txnRefNum,
            ));
            if ($data['query']->paymentStatus !== null) {
                $this->activityStore->applyPaymentStatus($txnRefNum, $data['query']->paymentStatus);
            }
        } catch (PrestoPayException $error) {
            $data['queryError'] = $error->getMessage();
        }

        return $this->render('checkout/return.html.twig', $data);
    }
}
