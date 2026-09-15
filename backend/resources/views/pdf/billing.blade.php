<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $billing->billing_number }} Billing</title>
    <style>
        @page { margin: 20mm 14mm 18mm; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: #20242a;
            font-family: "DejaVu Sans", sans-serif;
            font-size: 9px;
            line-height: 1.45;
        }
        table { width: 100%; border-collapse: collapse; }
        .document-header { margin-bottom: 22px; }
        .document-header td { vertical-align: top; }
        .brand-cell { width: 58%; }
        .document-cell { width: 42%; text-align: right; }
        .logo {
            display: block;
            width: auto;
            height: auto;
            max-width: 105px;
            max-height: 62px;
            margin-bottom: 9px;
        }
        .business-name { margin: 0 0 5px; color: #171a1f; font-size: 18px; line-height: 1.15; }
        .contact-line { color: #5d6570; }
        .document-title { margin: 0 0 5px; font-size: 24px; letter-spacing: 1.2px; }
        .document-number { margin: 0 0 9px; color: #4b525c; font-size: 11px; font-weight: bold; }
        .status {
            display: inline-block;
            margin-bottom: 10px;
            padding: 3px 9px;
            border: 1px solid #4b525c;
            border-radius: 3px;
            color: #30353c;
            font-size: 8px;
            font-weight: bold;
            letter-spacing: .6px;
            text-transform: uppercase;
        }
        .meta-table { margin-left: auto; width: auto; }
        .meta-table th, .meta-table td { padding: 1px 0 1px 12px; text-align: right; }
        .meta-table th { color: #69717c; font-weight: normal; }
        .details { margin-bottom: 20px; table-layout: fixed; }
        .details td { width: 50%; vertical-align: top; }
        .details td:first-child { padding-right: 9px; }
        .details td:last-child { padding-left: 9px; }
        .panel { border-top: 2px solid #30353c; padding-top: 8px; }
        .section-title {
            margin: 0 0 7px;
            color: #30353c;
            font-size: 8px;
            font-weight: bold;
            letter-spacing: .8px;
            text-transform: uppercase;
        }
        .primary-detail { margin: 0 0 5px; font-size: 11px; font-weight: bold; }
        .detail-line { margin: 2px 0; color: #555d67; }
        .line-items, .payments { margin-bottom: 18px; table-layout: fixed; }
        .line-items thead, .payments thead { display: table-header-group; }
        .line-items tr, .payments tr { page-break-inside: avoid; }
        .line-items th, .payments th {
            padding: 7px 5px;
            border-top: 1px solid #30353c;
            border-bottom: 1px solid #30353c;
            background: #f0f1f2;
            color: #30353c;
            font-size: 7px;
            letter-spacing: .3px;
            text-align: left;
            text-transform: uppercase;
        }
        .line-items td, .payments td { padding: 8px 5px; border-bottom: 1px solid #d9dde1; vertical-align: top; }
        .line-items .service { width: 17%; }
        .line-items .package { width: 15%; }
        .line-items .schedule { width: 25%; }
        .line-items .duration { width: 11%; }
        .line-items .quantity { width: 7%; text-align: center; }
        .line-items .money { width: 12.5%; text-align: right; white-space: nowrap; }
        .payments .date { width: 30%; }
        .payments .method { width: 20%; }
        .payments .reference { width: 30%; }
        .payments .money { width: 20%; text-align: right; white-space: nowrap; }
        .empty-payment {
            margin: 0 0 18px;
            padding: 9px 10px;
            border: 1px solid #d9dde1;
            color: #69717c;
        }
        .summary-section { margin-top: 4px; page-break-inside: avoid; }
        .summary-columns { table-layout: fixed; }
        .summary-columns > tbody > tr > td { width: 50%; vertical-align: top; }
        .summary-columns > tbody > tr > td:first-child { padding-right: 14px; }
        .summary-columns > tbody > tr > td:last-child { padding-left: 14px; }
        .summary th, .summary td { padding: 4px 0 4px 12px; }
        .summary th { color: #555d67; font-weight: normal; text-align: left; }
        .summary td { text-align: right; white-space: nowrap; }
        .summary .total th, .summary .total td {
            padding-top: 8px;
            border-top: 2px solid #30353c;
            color: #171a1f;
            font-size: 12px;
            font-weight: bold;
        }
        .footer {
            position: fixed;
            right: 0;
            bottom: -11mm;
            left: 0;
            border-top: 1px solid #d9dde1;
            padding-top: 5px;
            color: #777f89;
            font-size: 7px;
            text-align: center;
        }
        .page-number::after { content: counter(page); }
    </style>
</head>
<body>
    <div class="footer">
        {{ $billing->billing_number }} · Page <span class="page-number"></span>
    </div>

    <table class="document-header">
        <tr>
            <td class="brand-cell">
                @if ($logoDataUri)
                    <img src="{{ $logoDataUri }}" alt="" class="logo">
                @endif
                <h1 class="business-name">{{ $billing->business_display_name }}</h1>
                @if ($billing->business_email)<div class="contact-line">{{ $billing->business_email }}</div>@endif
                @if ($billing->business_phone)<div class="contact-line">{{ $billing->business_phone }}</div>@endif
                @if ($billing->business_address)<div class="contact-line">{!! nl2br(e($billing->business_address)) !!}</div>@endif
            </td>
            <td class="document-cell">
                <h2 class="document-title">BILLING</h2>
                <p class="document-number">{{ $billing->billing_number }}</p>
                <div class="status">{{ $paymentStatusLabel }}</div>
                <table class="meta-table">
                    <tr><th>Billing date</th><td>{{ $createdDate }}</td></tr>
                    <tr><th>Quotation</th><td>{{ $billing->quotation_number }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="details">
        <tr>
            <td>
                <div class="panel">
                    <p class="section-title">Billed to</p>
                    <p class="primary-detail">{{ $billing->customer_name }}</p>
                    @if ($billing->customer_email)<p class="detail-line">{{ $billing->customer_email }}</p>@endif
                    @if ($billing->customer_phone)<p class="detail-line">{{ $billing->customer_phone }}</p>@endif
                    @if ($billing->customer_address)<p class="detail-line">{!! nl2br(e($billing->customer_address)) !!}</p>@endif
                </div>
            </td>
            <td>
                <div class="panel">
                    <p class="section-title">Event details</p>
                    <p class="primary-detail">{{ $billing->event_name }}</p>
                    <p class="detail-line">{{ $billing->event_type_name }} · {{ $billing->event_date->format('F j, Y') }}</p>
                    <p class="detail-line">{{ $billing->venue_name }}</p>
                    @if ($billing->venue_address)<p class="detail-line">{!! nl2br(e($billing->venue_address)) !!}</p>@endif
                    <p class="detail-line">Contact: {{ $billing->contact_person }} · {{ $billing->contact_number }}</p>
                </div>
            </td>
        </tr>
    </table>

    <p class="section-title">Services</p>
    <table class="line-items">
        <thead>
            <tr>
                <th class="service">Service</th>
                <th class="package">Package</th>
                <th class="schedule">Schedule</th>
                <th class="duration">Duration</th>
                <th class="quantity">Qty</th>
                <th class="money">Unit Price</th>
                <th class="money">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                <tr>
                    <td class="service"><strong>{{ $item['serviceName'] }}</strong></td>
                    <td class="package">{{ $item['packageName'] }}</td>
                    <td class="schedule">{{ $item['schedule'] }}</td>
                    <td class="duration">{{ $item['duration'] }}</td>
                    <td class="quantity">{{ $item['quantity'] }}</td>
                    <td class="money">{{ $item['unitRate'] }}</td>
                    <td class="money"><strong>{{ $item['lineTotal'] }}</strong></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="section-title">Payments received</p>
    @if (count($postedPayments) > 0)
        <table class="payments">
            <thead>
                <tr>
                    <th class="date">Paid date</th>
                    <th class="method">Method</th>
                    <th class="reference">Reference</th>
                    <th class="money">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($postedPayments as $payment)
                    <tr>
                        <td class="date">{{ $payment['paidDate'] }}</td>
                        <td class="method">{{ $payment['method'] }}</td>
                        <td class="reference">{{ $payment['reference'] ?? 'Not provided' }}</td>
                        <td class="money"><strong>{{ $payment['amount'] }}</strong></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p class="empty-payment">No posted payments are currently applied to this Billing.</p>
    @endif

    <div class="summary-section">
        <table class="summary-columns">
            <tr>
                <td>
                    <p class="section-title">Commercial summary</p>
                    <table class="summary">
                        <tr><th>Subtotal</th><td>{{ $money['subtotal'] }}</td></tr>
                        <tr><th>Transportation Fee</th><td>{{ $money['transportationFee'] }}</td></tr>
                        <tr><th>Crew Meal Fee</th><td>{{ $money['crewMealFee'] }}</td></tr>
                        <tr><th>Discount</th><td>{{ $billing->discount_amount === '0.00' ? '' : '-' }}{{ $money['discountAmount'] }}</td></tr>
                        <tr class="total"><th>Total</th><td>{{ $money['total'] }}</td></tr>
                    </table>
                </td>
                <td>
                    <p class="section-title">Payment summary</p>
                    <table class="summary">
                        <tr><th>Payment Status</th><td>{{ $paymentStatusLabel }}</td></tr>
                        <tr><th>Amount Paid</th><td>{{ $money['amountPaid'] }}</td></tr>
                        <tr class="total"><th>Remaining Balance</th><td>{{ $money['remainingBalance'] }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
