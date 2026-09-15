<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $quotation->quotation_number }} Quotation</title>
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
        .logo { max-width: 105px; max-height: 62px; margin-bottom: 9px; }
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
        .notice {
            margin: 0 0 18px;
            padding: 8px 10px;
            border: 1px solid #a7adb5;
            background: #f4f5f6;
            color: #3f454d;
        }
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
        .services { margin-bottom: 18px; table-layout: fixed; }
        .services thead { display: table-header-group; }
        .services tr { page-break-inside: avoid; }
        .services th {
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
        .services td { padding: 8px 5px; border-bottom: 1px solid #d9dde1; vertical-align: top; }
        .services .service { width: 17%; }
        .services .package { width: 15%; }
        .services .schedule { width: 25%; }
        .services .duration { width: 11%; }
        .services .quantity { width: 7%; text-align: center; }
        .services .money { width: 12.5%; text-align: right; white-space: nowrap; }
        .summary-wrap { page-break-inside: avoid; }
        .summary { margin-left: auto; width: 45%; }
        .summary th, .summary td { padding: 4px 0 4px 12px; }
        .summary th { color: #555d67; font-weight: normal; text-align: left; }
        .summary td { text-align: right; white-space: nowrap; }
        .summary .total th, .summary .total td {
            padding-top: 8px;
            border-top: 2px solid #30353c;
            color: #171a1f;
            font-size: 13px;
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
        {{ $quotation->quotation_number }} · Page <span class="page-number"></span>
    </div>

    <table class="document-header">
        <tr>
            <td class="brand-cell">
                @if ($logoDataUri)
                    <img src="{{ $logoDataUri }}" alt="" class="logo">
                @endif
                <h1 class="business-name">{{ $quotation->business_display_name }}</h1>
                @if ($quotation->business_email)<div class="contact-line">{{ $quotation->business_email }}</div>@endif
                @if ($quotation->business_phone)<div class="contact-line">{{ $quotation->business_phone }}</div>@endif
                @if ($quotation->business_address)<div class="contact-line">{!! nl2br(e($quotation->business_address)) !!}</div>@endif
            </td>
            <td class="document-cell">
                <h2 class="document-title">QUOTATION</h2>
                <p class="document-number">{{ $quotation->quotation_number }}</p>
                <div class="status">{{ $statusLabel }}</div>
                <table class="meta-table">
                    <tr><th>Issue date</th><td>{{ $issueDate }}</td></tr>
                    <tr><th>Valid until</th><td>{{ $validUntil }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    @if ($quotation->status->value === 'OUTDATED')
        <div class="notice">This quotation has been superseded by changes to the booking.</div>
    @endif

    <table class="details">
        <tr>
            <td>
                <div class="panel">
                    <p class="section-title">Prepared for</p>
                    <p class="primary-detail">{{ $quotation->customer_name }}</p>
                    @if ($quotation->customer_email)<p class="detail-line">{{ $quotation->customer_email }}</p>@endif
                    @if ($quotation->customer_phone)<p class="detail-line">{{ $quotation->customer_phone }}</p>@endif
                    @if ($quotation->customer_address)<p class="detail-line">{!! nl2br(e($quotation->customer_address)) !!}</p>@endif
                </div>
            </td>
            <td>
                <div class="panel">
                    <p class="section-title">Event details</p>
                    <p class="primary-detail">{{ $quotation->event_name }}</p>
                    <p class="detail-line">{{ $quotation->event_type_name }} · {{ $quotation->event_date->format('F j, Y') }}</p>
                    <p class="detail-line">{{ $quotation->venue_name }}</p>
                    @if ($quotation->venue_address)<p class="detail-line">{!! nl2br(e($quotation->venue_address)) !!}</p>@endif
                    <p class="detail-line">Contact: {{ $quotation->contact_person }} · {{ $quotation->contact_number }}</p>
                </div>
            </td>
        </tr>
    </table>

    <p class="section-title">Services</p>
    <table class="services">
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

    <div class="summary-wrap">
        <table class="summary">
            <tr><th>Subtotal</th><td>{{ $money['subtotal'] }}</td></tr>
            <tr><th>Transportation Fee</th><td>{{ $money['transportationFee'] }}</td></tr>
            <tr><th>Crew Meal Fee</th><td>{{ $money['crewMealFee'] }}</td></tr>
            <tr><th>Discount</th><td>{{ $quotation->discount_amount === '0.00' ? '' : '-' }}{{ $money['discountAmount'] }}</td></tr>
            <tr class="total"><th>Total</th><td>{{ $money['total'] }}</td></tr>
        </table>
    </div>
</body>
</html>
