<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ActivityStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PrestoUniverse\PrestoPay\Exception\PrestoPayException;
use PrestoUniverse\PrestoPay\PaymentMethod;
use PrestoUniverse\PrestoPay\PrestoPay;
use PrestoUniverse\PrestoPay\Request\InitRequest;
use PrestoUniverse\PrestoPay\TxnType;

class CheckoutController extends Controller
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

    public function __construct(private readonly PrestoPay $presto, private readonly ActivityStore $activityStore) {}

    public function index()
    {
        return view('checkout.index', [
            'paymentMethods' => self::PAYMENT_METHODS,
            'defaultSelectedMethod' => PaymentMethod::PM_PG_CARD,
            'recentWebhooks' => $this->activityStore->recentWebhooks(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        // Validated manually (not $request->validate()) so this always answers with the JSON error-map
        // shape below, regardless of the request's Accept header -- the checkout page's fetch() call sets
        // none, and Laravel's automatic redirect-on-failure behaviour only kicks in when it does.
        $validator = Validator::make($request->all(), [
            'displayDesc' => ['required', 'string', 'max:200'],
            'amountInRinggit' => ['required', 'regex:/^\d+(\.\d{1,2})?$/', 'numeric', 'min:0.01'],
            'showPaymentMethods' => ['sometimes', 'boolean'],
            'selectedPaymentMethod' => [
                Rule::requiredIf((bool) $request->boolean('showPaymentMethods')),
                'nullable',
                'string',
            ],
        ], [
            'amountInRinggit.regex' => 'Amount must be at least 0.01',
            'amountInRinggit.min' => 'Amount must be at least 0.01',
            'selectedPaymentMethod.required' => 'Select a payment method',
        ]);

        if ($validator->fails()) {
            $firstMessagePerField = array_map(
                static fn (array $messages): string => $messages[0],
                $validator->errors()->toArray(),
            );
            return response()->json($firstMessagePerField, 400);
        }
        $data = $validator->validated();

        $showPaymentMethods = (bool) ($data['showPaymentMethods'] ?? false);
        $selectedPaymentMethod = $showPaymentMethods ? trim((string) $data['selectedPaymentMethod']) : null;
        $amountMinorUnits = (int) round(((float) $data['amountInRinggit']) * 100);
        $txnRefNum = 'demo-' . Str::random(16);

        $this->activityStore->saveCheckout($txnRefNum, [
            'txnRefNum' => $txnRefNum,
            'displayDesc' => $data['displayDesc'],
            'amountMinorUnits' => $amountMinorUnits,
            'currencyCode' => self::CURRENCY,
            'selectedPaymentMethod' => $selectedPaymentMethod,
            'initiatedAt' => now()->toAtomString(),
        ]);

        try {
            $result = $this->presto->payments()->init(new InitRequest(
                prestoMrn: (string) config('prestopay.mrn'),
                txnType: TxnType::WebPay,
                txnRefNum: $txnRefNum,
                displayDesc: $data['displayDesc'],
                amount: $amountMinorUnits,
                currencyCode: self::CURRENCY,
                notifyUrl: rtrim((string) config('prestopay.public_base_url'), '/') . '/presto/notify',
                redirectUrl: rtrim((string) config('prestopay.public_base_url'), '/') . '/return/' . $txnRefNum,
                allowedPaymentMethods: $selectedPaymentMethod !== null ? [$selectedPaymentMethod] : null,
            ));
        } catch (PrestoPayException $error) {
            return response()->json([
                'message' => $error->getMessage(),
                'mayHaveTakenEffect' => $error->mayHaveTakenEffect(),
                'reconcileBy' => $error->reconcileBy(),
            ], 502);
        }

        $this->activityStore->saveCheckout($txnRefNum, [
            'txnRefNum' => $txnRefNum,
            'displayDesc' => $data['displayDesc'],
            'amountMinorUnits' => $amountMinorUnits,
            'currencyCode' => self::CURRENCY,
            'selectedPaymentMethod' => $selectedPaymentMethod,
            'initiatedAt' => now()->toAtomString(),
            'paymentRefNum' => $result->paymentRefNum,
            'paymentStatus' => $result->paymentStatus,
        ]);

        return response()->json(['paymentUrl' => $result->paymentUrl, 'txnRefNum' => $result->txnRefNum ?? $txnRefNum]);
    }
}
