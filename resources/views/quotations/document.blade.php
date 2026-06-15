<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page {
            margin: 22px 30px 24px;
        }

        body {
            color: #111827;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 10px;
            line-height: 1.35;
        }

        .brand-image,
        .footer-image {
            width: 100%;
        }

        .title {
            color: #1f4e79;
            font-size: 18px;
            font-weight: 700;
            margin: 10px 0 2px;
            text-align: center;
        }

        .subtitle {
            font-weight: 700;
            margin: 0 0 10px;
            text-align: center;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        .info-table td,
        .ref-table td,
        .items-table td,
        .items-table th {
            border: 1px solid #bfbfbf;
            padding: 6px;
            vertical-align: top;
        }

        .section-label {
            color: #1f4e79;
            display: block;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .ref-table {
            margin-bottom: 8px;
        }

        .ref-table td {
            font-weight: 700;
            width: 50%;
        }

        .rfq-line {
            font-weight: 700;
            margin: 12px 0 6px;
            text-align: right;
        }

        .items-table th {
            background: #1f4e79;
            color: #ffffff;
            font-weight: 700;
            text-align: center;
        }

        .items-table td:nth-child(1),
        .items-table td:nth-child(3) {
            text-align: center;
        }

        .items-table td:nth-child(4),
        .items-table td:nth-child(5) {
            text-align: right;
        }

        .product-title {
            font-weight: 700;
            margin-bottom: 4px;
        }

        .total-label {
            font-weight: 700;
            text-align: right;
        }

        .terms-title {
            color: #1f4e79;
            font-size: 12px;
            font-weight: 700;
            margin: 12px 0 6px;
        }

        .term {
            margin-bottom: 7px;
        }

        .term strong {
            display: inline-block;
        }

        .abb {
            margin-top: 12px;
            width: 160px;
        }

        .signoff {
            font-weight: 700;
            margin: 12px 0 8px;
            text-align: center;
        }
    </style>
</head>
<body>
    @if($assets['header'])
        <img class="brand-image" src="{{ $assets['header'] }}" alt="Industrial Supplies Center">
    @endif

    <div class="title">Commercial Offer</div>
    <div class="subtitle">- RFQ {{ $snapshot['quotation']['rfq_number'] }}</div>

    <table class="ref-table">
        <tr>
            <td>Ref: {{ $snapshot['quotation']['reference'] }}</td>
            <td>Dated: {{ $snapshot['quotation']['dated'] }}</td>
        </tr>
    </table>

    <table class="info-table">
        <tr>
            <td>
                <span class="section-label">SUPPLIER</span>
                {{ $snapshot['supplier']['name'] ?? '' }}<br>
                {{ $snapshot['supplier']['address'] ?? '' }}<br>
                {{ $snapshot['supplier']['location'] ?? '' }}
            </td>
            <td>
                <span class="section-label">BUYER</span>
                {{ $snapshot['buyer']['name'] ?? '' }}<br>
                {{ $snapshot['buyer']['address'] ?? '' }}<br>
                {{ $snapshot['buyer']['location'] ?? '' }}
            </td>
        </tr>
        <tr>
            <td>
                <span class="section-label">SUPPLIERS CONTACT</span>
                {{ trim(($snapshot['supplier_contact']['designation'] ? $snapshot['supplier_contact']['designation'].' ' : '').($snapshot['supplier_contact']['name'] ?? '')) }}<br>
                @if($snapshot['supplier_contact']['mobile'] ?? null) Mob: {{ $snapshot['supplier_contact']['mobile'] }}<br>@endif
                @if($snapshot['supplier_contact']['telephone'] ?? null) Tel: {{ $snapshot['supplier_contact']['telephone'] }}@if($snapshot['supplier_contact']['extension'] ?? null), Ext:{{ $snapshot['supplier_contact']['extension'] }}@endif<br>@endif
                @if($snapshot['supplier_contact']['email'] ?? null) E-mail: {{ $snapshot['supplier_contact']['email'] }}@endif
            </td>
            <td>
                <span class="section-label">BUYERS CONTACT</span>
                {{ trim(($snapshot['buyer_contact']['designation'] ? $snapshot['buyer_contact']['designation'].' ' : '').($snapshot['buyer_contact']['name'] ?? '')) }}<br>
                {{ $snapshot['buyer_contact']['job_title'] ?? '' }}<br>
                @if($snapshot['buyer_contact']['email'] ?? null) E Mail: {{ $snapshot['buyer_contact']['email'] }}@endif
            </td>
        </tr>
        <tr>
            <td><span class="section-label">QUOTATION VALIDITY PERIOD</span>{{ $snapshot['quotation']['validity'] }}</td>
            <td><span class="section-label">ACCEPTED TERMS OF PAYMENT</span>{{ $snapshot['quotation']['payment_terms'] }}</td>
        </tr>
        <tr>
            <td><span class="section-label">DATE OF DELIVERY</span>{{ $snapshot['quotation']['delivery_period'] }}</td>
            <td><span class="section-label">ACCEPTED INVOICE CURRENCY</span>{{ $snapshot['quotation']['currency'] }}</td>
        </tr>
    </table>

    <div class="rfq-line">
        RFQ {{ $snapshot['quotation']['rfq_number'] }}
        PR {{ $snapshot['quotation']['pr_number'] }}
        Closing Date: {{ $snapshot['quotation']['closing_at'] }}
    </div>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 8%;">SL No</th>
                <th style="width: 54%;">Description</th>
                <th style="width: 10%;">QTY</th>
                <th style="width: 14%;">Unit Price</th>
                <th style="width: 14%;">Total excl. VAT</th>
            </tr>
        </thead>
        <tbody>
            @foreach($snapshot['items'] as $item)
                <tr>
                    <td>{{ $item['line_number'] }}</td>
                    <td>
                        <div class="product-title">{{ trim(($item['manufacturer'] ? $item['manufacturer'].' - ' : '').$item['title']) }}</div>
                        {!! nl2br(e($item['description'])) !!}
                    </td>
                    <td>{{ $item['quantity'] }} {{ $item['uom'] }}</td>
                    <td>{{ $item['unit_price'] }}</td>
                    <td>{{ $item['total_price'] }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="4" class="total-label">Total Net Amount {{ $snapshot['quotation']['currency'] }} (Excluding VAT):</td>
                <td>{{ $snapshot['totals']['subtotal'] }}</td>
            </tr>
        </tbody>
    </table>

    <div class="terms-title">Terms & Conditions:</div>
    @foreach($snapshot['terms'] as $term)
        <div class="term">
            <strong>{{ $term['title'] }}:</strong>
            {{ $term['description'] }}
        </div>
    @endforeach

    @if($assets['abb'])
        <img class="abb" src="{{ $assets['abb'] }}" alt="ABB value provider">
    @endif

    <div class="signoff">Industrial Supplies Center LLC.</div>

    @if($assets['footer'])
        <img class="footer-image" src="{{ $assets['footer'] }}" alt="ISC contact details">
    @endif
</body>
</html>
