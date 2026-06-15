<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 22px 30px 24px; }

        body {
            color: #111827;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 10px;
            line-height: 1.35;
        }

        .brand-image,
        .footer-image { width: 100%; }

        .title {
            color: #1f4e79;
            font-size: 18px;
            font-weight: 700;
            margin: 10px 0;
            text-align: center;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        .info-table td,
        .ref-table td,
        .items-table td,
        .items-table th,
        .approval-table td {
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

        .ref-table { margin-bottom: 8px; }
        .ref-table td { font-weight: 700; width: 50%; }

        .items-table { margin-top: 12px; }
        .items-table th {
            background: #1f4e79;
            color: #ffffff;
            font-weight: 700;
            text-align: center;
        }

        .items-table td:nth-child(1),
        .items-table td:nth-child(3) { text-align: center; }
        .items-table td:nth-child(4),
        .items-table td:nth-child(5) { text-align: right; }

        .product-title {
            font-weight: 700;
            margin-bottom: 4px;
        }

        .trace-line {
            color: #667085;
            font-size: 8px;
            font-style: italic;
            margin-top: 5px;
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

        .term { margin-bottom: 7px; }
        .term strong { display: inline-block; }

        .approval-table {
            margin-top: 14px;
            text-align: center;
        }

        .approval-table td {
            height: 38px;
            width: 33.333%;
        }

        .approval-table .head {
            font-weight: 700;
            height: auto;
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

    <div class="title">Purchase Order</div>

    <table class="ref-table">
        <tr>
            <td>Ref: {{ $snapshot['po']['reference'] }}</td>
            <td>Dated: {{ $snapshot['po']['dated'] }}</td>
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
                {{ $snapshot['supplier_contact']['job_title'] ?? '' }}<br>
                @if($snapshot['supplier_contact']['email'] ?? null) E-mail: {{ $snapshot['supplier_contact']['email'] }}@endif
            </td>
            <td>
                <span class="section-label">BUYERS CONTACT</span>
                Contact: {{ $snapshot['buyer_contact']['name'] ?? '' }}<br>
                @if($snapshot['buyer_contact']['mobile'] ?? null) Mob: {{ $snapshot['buyer_contact']['mobile'] }}<br>@endif
                @if($snapshot['buyer_contact']['telephone'] ?? null) Tel: {{ $snapshot['buyer_contact']['telephone'] }}<br>@endif
                @if($snapshot['buyer_contact']['email'] ?? null) E-mail: {{ $snapshot['buyer_contact']['email'] }}@endif
            </td>
        </tr>
        <tr>
            <td><span class="section-label">SUPPLIER QUOTATION Ref:</span>{{ $snapshot['po']['supplier_quote_reference'] ?: '-' }}</td>
            <td><span class="section-label">ACCEPTED TERMS OF PAYMENT</span>{{ $snapshot['po']['payment_terms'] }}</td>
        </tr>
        <tr>
            <td><span class="section-label">DELIVERY</span>{{ $snapshot['po']['delivery_period'] }}</td>
            <td><span class="section-label">ACCEPTED INVOICE CURRENCY</span>{{ $snapshot['po']['currency'] }}</td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 8%;">SL No</th>
                <th style="width: 54%;">Item Description</th>
                <th style="width: 10%;">Qty</th>
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
                        <div class="trace-line">Buyer PO: {{ $item['buyer_po_number'] }} | Quotation: {{ $item['quotation_reference'] }}</div>
                    </td>
                    <td>{{ $item['quantity'] }} {{ $item['uom'] }}</td>
                    <td>{{ $item['unit_cost'] }}</td>
                    <td>{{ $item['total_cost'] }}</td>
                </tr>
            @endforeach
            @if((float) $snapshot['po']['additional_charges'] > 0)
                <tr>
                    <td colspan="4" class="total-label">{{ $snapshot['po']['additional_charges_label'] ?: 'Additional Charges' }}:</td>
                    <td>{{ $snapshot['po']['additional_charges'] }}</td>
                </tr>
            @endif
            <tr>
                <td colspan="4" class="total-label">Total Net Amount (VAT Exclusive) {{ $snapshot['po']['currency'] }}:</td>
                <td>{{ $snapshot['totals']['total'] }}</td>
            </tr>
        </tbody>
    </table>

    <div class="terms-title">Terms and Conditions</div>
    @foreach($snapshot['terms'] as $term)
        <div class="term">
            <strong>{{ $term['title'] }}:</strong>
            {{ $term['description'] }}
        </div>
    @endforeach

    <table class="approval-table">
        <tr>
            <td class="head">Prepared By</td>
            <td class="head">Checked By</td>
            <td class="head">Accounts Dept.</td>
        </tr>
        <tr>
            <td>{{ $snapshot['buyer_contact']['name'] ?? '' }}</td>
            <td></td>
            <td></td>
        </tr>
    </table>

    <div class="signoff">Industrial Supplies Center LLC.</div>

    @if($assets['footer'])
        <img class="footer-image" src="{{ $assets['footer'] }}" alt="ISC contact details">
    @endif
</body>
</html>
