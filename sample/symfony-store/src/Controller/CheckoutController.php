<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ActivityStore;
use App\Service\PrestoPayFactory;
use PrestoUniverse\PrestoPay\Exception\PrestoPayException;
use PrestoUniverse\PrestoPay\PaymentMethod;
use PrestoUniverse\PrestoPay\PrestoPay;
use PrestoUniverse\PrestoPay\Request\InitRequest;
use PrestoUniverse\PrestoPay\TxnType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CheckoutController extends AbstractController
{
    private const CURRENCY = 'MYR';

    /** @var list<array{code: string, name: string, icon: string}> */
    private const PAYMENT_METHODS = [
        ['code' => PaymentMethod::PM_PG_CARD, 'name' => 'Credit / debit card', 'icon' => 'fa-credit-card'],
        ['code' => PaymentMethod::TOUCH_N_GO_EWALLET, 'name' => "Touch 'n Go eWallet", 'icon' => 'fa-wallet'],
        ['code' => PaymentMethod::GRAB_PAY, 'name' => 'GrabPay', 'icon' => 'fa-wallet'],
        ['code' => PaymentMethod::MAYBANK, 'name' => 'Maybank FPX', 'icon' => 'fa-building-columns'],
        ['code' => PaymentMethod::CIMB, 'name' => 'CIMB Clicks', 'icon' => 'fa-building-columns'],
    ];

    public function __construct(
        private readonly PrestoPay $presto,
        private readonly PrestoPayFactory $prestoPayFactory,
        private readonly ActivityStore $activityStore,
    ) {}

    #[Route('/', name: 'checkout_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('checkout/index.html.twig', [
            'paymentMethods' => self::PAYMENT_METHODS,
            'defaultSelectedMethod' => PaymentMethod::PM_PG_CARD,
            'recentWebhooks' => $this->activityStore->recentWebhooks(),
        ]);
    }

    #[Route('/checkout', name: 'checkout_submit', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
        $form = json_decode($request->getContent(), true) ?? [];
        $errors = $this->validate(is_array($form) ? $form : []);
        if ($errors !== []) {
            return new JsonResponse($errors, 400);
        }

        $displayDesc = trim((string) $form['displayDesc']);
        $amountMinorUnits = (int) round(((float) $form['amountInRinggit']) * 100);
        $showPaymentMethods = (bool) ($form['showPaymentMethods'] ?? false);
        $selectedPaymentMethod = $showPaymentMethods ? trim((string) $form['selectedPaymentMethod']) : null;
        $txnRefNum = 'demo-' . bin2hex(random_bytes(8));

        try {
            $result = $this->presto->payments()->init(new InitRequest(
                prestoMrn: $this->prestoPayFactory->merchantRefNum(),
                txnType: TxnType::WebPay,
                txnRefNum: $txnRefNum,
                displayDesc: $displayDesc,
                amount: $amountMinorUnits,
                currencyCode: self::CURRENCY,
                notifyUrl: $this->prestoPayFactory->notifyUrl(),
                redirectUrl: $this->prestoPayFactory->returnUrl($txnRefNum),
                allowedPaymentMethods: $selectedPaymentMethod !== null ? [$selectedPaymentMethod] : null,
            ));
        } catch (PrestoPayException $error) {
            return new JsonResponse([
                'message' => $error->getMessage(),
                'mayHaveTakenEffect' => $error->mayHaveTakenEffect(),
                'reconcileBy' => $error->reconcileBy(),
            ], 502);
        }

        $this->activityStore->saveCheckout($txnRefNum, [
            'txnRefNum' => $txnRefNum,
            'displayDesc' => $displayDesc,
            'amountMinorUnits' => $amountMinorUnits,
            'currencyCode' => self::CURRENCY,
            'selectedPaymentMethod' => $selectedPaymentMethod,
            'initiatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'paymentRefNum' => $result->paymentRefNum,
            'paymentStatus' => $result->paymentStatus,
        ]);

        return new JsonResponse(['paymentUrl' => $result->paymentUrl, 'txnRefNum' => $result->txnRefNum ?? $txnRefNum]);
    }

    /** @return array<string, string> */
    private function validate(array $form): array
    {
        $errors = [];

        $displayDesc = trim((string) ($form['displayDesc'] ?? ''));
        if ($displayDesc === '') {
            $errors['displayDesc'] = 'Description is required';
        } elseif (strlen($displayDesc) > 200) {
            $errors['displayDesc'] = 'Description must be at most 200 characters';
        }

        $amountText = (string) ($form['amountInRinggit'] ?? '');
        if (preg_match('/^\d+(\.\d{1,2})?$/D', trim($amountText)) !== 1 || (float) $amountText < 0.01) {
            $errors['amountInRinggit'] = 'Amount must be at least 0.01';
        }

        $showPaymentMethods = (bool) ($form['showPaymentMethods'] ?? false);
        $selectedPaymentMethod = trim((string) ($form['selectedPaymentMethod'] ?? ''));
        if ($showPaymentMethods && $selectedPaymentMethod === '') {
            $errors['selectedPaymentMethod'] = 'Select a payment method';
        }

        return $errors;
    }
}
