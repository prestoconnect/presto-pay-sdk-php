<?php
$missingTxnRefNum ??= false;
$checkout ??= null;
$query ??= null;
$queryError ??= null;
$recentWebhooks ??= [];

$statusHeading = null;
$statusIconClass = null;
$statusIconBg = null;
if ($query !== null) {
    $status = $query->paymentStatus;
    $isSuccess = $status === 'Authorised' || $status === 'Refunded';
    $isPending = $status === 'PendingAuthorise' || $status === 'PendingReverse' || $status === 'PendingRefund';
    $isFailed = $status === 'Failed' || $status === 'Cancelled' || $status === 'Expired';
    if ($isSuccess) {
        [$statusHeading, $statusIconClass, $statusIconBg] = ['Payment Successful', 'fa-solid fa-check', 'bg-green-600'];
    } elseif ($isPending) {
        [$statusHeading, $statusIconClass, $statusIconBg] = ['Payment Pending', 'fa-solid fa-clock', 'bg-amber-500'];
    } elseif ($isFailed) {
        [$statusHeading, $statusIconClass, $statusIconBg] = ['Payment Failed', 'fa-solid fa-xmark', 'bg-red-600'];
    } else {
        [$statusHeading, $statusIconClass, $statusIconBg] = ['Payment ' . $status, 'fa-solid fa-circle-info', 'bg-slate-500'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Payment result</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"/>
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-900">
<div class="mx-auto max-w-md px-4 py-8">

  @if ($missingTxnRefNum)
  <section class="rounded-xl border border-slate-200 bg-white p-8 text-center shadow-sm">
    <p class="text-left text-sm text-slate-600">
      The return URL must include the merchant transaction reference as a path segment
      (for example <code class="rounded bg-slate-100 px-1 py-0.5 text-xs">/return/demo-abc123</code>). That
      value is set on payment <code class="rounded bg-slate-100 px-1 py-0.5 text-xs">init</code> via
      <code class="rounded bg-slate-100 px-1 py-0.5 text-xs">redirectUrl</code>. Start a payment from the
      <a class="font-semibold text-indigo-600 hover:text-indigo-700" href="/">checkout demo</a> so Presto
      redirects back with the correct reference.
    </p>
  </section>
  @else
  <section class="rounded-xl border border-slate-200 bg-white p-8 text-center shadow-sm">

    @if ($queryError !== null)
    <div>
      <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-red-600 text-2xl text-white">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </div>
      <h1 class="mb-2 text-xl font-bold">Could not confirm payment</h1>
      <p class="mb-4 text-sm text-slate-500">{{ $queryError }}</p>
    </div>
    @elseif ($query !== null)
    <div>
      <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full text-2xl text-white {{ $statusIconBg }}">
        <i class="{{ $statusIconClass }}" aria-hidden="true"></i>
      </div>

      <h1 class="mb-2 text-xl font-bold">{{ $statusHeading }}</h1>
      <p class="mb-5">
        <span class="inline-block rounded-full border border-slate-200 bg-slate-50 px-2.5 py-0.5 text-xs text-slate-700">
          {{ $query->paymentStatus }}
        </span>
      </p>

      <ul class="mb-5 text-left text-sm">
        <li class="flex justify-between gap-4 border-b border-slate-200 py-2">
          <span class="text-slate-500">Amount</span>
          <span class="font-semibold">RM {{ number_format(($query->amount ?? 0) / 100, 2) }}</span>
        </li>
        @if ($checkout !== null && !empty($checkout['displayDesc']))
        <li class="flex justify-between gap-4 border-b border-slate-200 py-2">
          <span class="text-slate-500">Description</span>
          <span class="font-semibold">{{ $checkout['displayDesc'] }}</span>
        </li>
        @endif
        @if ($query->paymentDetails !== [] && ($detail = $query->paymentDetails[0]))
        <li class="flex justify-between gap-4 border-b border-slate-200 py-2">
          <span class="text-slate-500">Payment Method</span>
          <span class="font-semibold">
            {{ $detail->method }}{{ $detail->cardSummary !== null ? ' • ' . $detail->cardSummary : '' }}
          </span>
        </li>
        @elseif ($checkout !== null && !empty($checkout['selectedPaymentMethod']))
        <li class="flex justify-between gap-4 border-b border-slate-200 py-2">
          <span class="text-slate-500">Payment Method</span>
          <span class="font-semibold">{{ $checkout['selectedPaymentMethod'] }}</span>
        </li>
        @endif
        <li class="flex justify-between gap-4 border-b border-slate-200 py-2">
          <span class="text-slate-500">Transaction ID</span>
          <span class="font-semibold">{{ $query->paymentRefNum }}</span>
        </li>
        @if ($query->paymentFinalisedDate !== null)
        <li class="flex justify-between gap-4 py-2">
          <span class="text-slate-500">Payment Time</span>
          <span class="font-semibold">{{ $query->paymentFinalisedDate }}</span>
        </li>
        @endif
      </ul>

      <a class="mb-2 flex w-full items-center justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700" href="/">Back to MyStore</a>
      <a class="flex w-full items-center justify-center rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50"
         href="/return/{{ $txnRefNum }}">Refresh status</a>
    </div>
    @endif
  </section>
  @endif

  @if ($recentWebhooks !== [])
  <section class="mt-6">
    <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Recent webhooks</h3>
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
      <table class="w-full text-xs">
        <thead>
        <tr class="bg-slate-50 text-slate-500">
          <th class="px-3 py-2 text-left font-medium">Time</th>
          <th class="px-3 py-2 text-left font-medium">Event</th>
          <th class="px-3 py-2 text-left font-medium">Status</th>
          <th class="px-3 py-2 text-left font-medium">Txn ref</th>
          <th class="px-3 py-2 text-left font-medium">success</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($recentWebhooks as $webhook)
        <tr class="border-t border-slate-200">
          <td class="px-3 py-2">{{ substr((string) $webhook['receivedAt'], 11, 8) }}</td>
          <td class="px-3 py-2">{{ $webhook['eventCode'] }}</td>
          <td class="px-3 py-2">{{ $webhook['paymentStatus'] }}</td>
          <td class="px-3 py-2">{{ $webhook['txnRefNum'] }}</td>
          <td class="px-3 py-2{{ $webhook['eventCode'] === 'Authorised' ? ' font-semibold text-green-600' : '' }}">
            {{ $webhook['success'] ? 'true' : 'false' }}
          </td>
        </tr>
        @endforeach
        </tbody>
      </table>
    </div>
  </section>
  @endif
</div>
</body>
</html>
