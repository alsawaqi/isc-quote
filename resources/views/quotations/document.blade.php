<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @include('documents.partials.page-chrome-css')

        body {
            color: #111827;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 10px;
            line-height: 1.35;
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
        .items-table td:nth-child(2),
        .items-table td:nth-child(4) {
            text-align: center;
        }

        .items-table td:nth-child(5),
        .items-table td:nth-child(6),
        .items-table td:nth-child(7) {
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

        .highlight,
        mark,
        span[style*="background-color"] {
            background: #fff59d;
            padding: 0 1px;
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
    <div class="title">Commercial Offer</div>
    @php
        $rfqTitle = $snapshot['quotation']['rfq_title'] ?? trim(implode(' ', array_filter([
            ! empty($snapshot['quotation']['rfq_number']) ? 'RFQ '.$snapshot['quotation']['rfq_number'] : null,
            ! empty($snapshot['quotation']['pr_number']) ? 'PR '.$snapshot['quotation']['pr_number'] : null,
        ])));
        $currencyDisplay = $snapshot['quotation']['currency_display'] ?? $snapshot['quotation']['currency'];
    @endphp

    @if($rfqTitle)
        <div class="subtitle">- {{ $rfqTitle }}</div>
    @endif

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
            <td><span class="section-label">ACCEPTED INVOICE CURRENCY</span>{{ $currencyDisplay }}</td>
        </tr>
    </table>

    @if(! empty($snapshot['payment_schedule']))
        <div class="terms-title">Payment Schedule:</div>
        @foreach($snapshot['payment_schedule'] as $schedule)
            <div class="term">
                <strong>{{ $schedule['line_number'] }}. {{ $schedule['label'] }}:</strong>
                {{ $schedule['payment_percentage'] }}% by {{ $schedule['payment_method'] }}, {{ $schedule['due'] }}
                @if($schedule['notes'])
                    - {{ $schedule['notes'] }}
                @endif
            </div>
        @endforeach
    @endif

    @if($rfqTitle || $snapshot['quotation']['closing_at'])
        <div class="rfq-line">
            @if($rfqTitle)
                {{ $rfqTitle }}<br>
            @endif
            @if($snapshot['quotation']['closing_at'])
                Closing Date: {{ $snapshot['quotation']['closing_at'] }}
            @endif
        </div>
    @endif

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 7%;">SL No</th>
                <th style="width: 10%;">Material / Item Code</th>
                <th style="width: 40%;">Description</th>
                <th style="width: 10%;">QTY</th>
                <th style="width: 12%;">Unit Price</th>
                <th style="width: 9%;">VAT %</th>
                <th style="width: 12%;">Total excl. VAT</th>
            </tr>
        </thead>
        <tbody>
            @foreach($snapshot['items'] as $item)
                @php
                    $descriptionChunks = $item['pdf_description_chunks'] ?? [(string) $item['description']];
                    $isSplitDescription = count($descriptionChunks) > 1;
                @endphp
                @foreach($descriptionChunks as $chunkIndex => $descriptionChunk)
                    <tr @class(['item-continuation' => $chunkIndex > 0])>
                        <td>{{ $chunkIndex === 0 ? $item['line_number'] : '' }}</td>
                        <td>{{ $chunkIndex === 0 ? ($item['product_code'] ?? '-') : '' }}</td>
                        <td>
                            @if($chunkIndex === 0)
                                <div class="product-title">{{ trim(($item['manufacturer'] ? $item['manufacturer'].' - ' : '').$item['title']) }}</div>
                                @if(($item['delivery_date'] ?? null) || ($item['incoterm'] ?? null))
                                    <div style="font-size: 8px; color: #4b5563; margin-bottom: 3px;">
                                        @if($item['delivery_date'] ?? null)
                                            Delivery Date: {{ $item['delivery_date'] }}
                                        @endif
                                        @if(($item['delivery_date'] ?? null) && ($item['incoterm'] ?? null))
                                            |
                                        @endif
                                        @if($item['incoterm'] ?? null)
                                            Incoterm: {{ $item['incoterm'] }}
                                        @endif
                                    </div>
                                @endif
                            @endif
                            @if($isSplitDescription)
                                {!! nl2br(e($descriptionChunk)) !!}
                            @elseif($chunkIndex === 0)
                                {!! ($item['description_html'] ?? '') ?: nl2br(e($item['description'])) !!}
                            @endif
                        </td>
                        <td>{{ $chunkIndex === 0 ? $item['quantity'].' '.$item['uom'] : '' }}</td>
                        <td>{{ $chunkIndex === 0 ? $item['unit_price'] : '' }}</td>
                        <td>{{ $chunkIndex === 0 ? ($item['vat_rate'] ?? '0.000') : '' }}</td>
                        <td>{{ $chunkIndex === 0 ? $item['total_price'] : '' }}</td>
                    </tr>
                @endforeach
            @endforeach
            <tr>
                <td colspan="6" class="total-label">Total Net Amount {{ $currencyDisplay }} (Excluding VAT):</td>
                <td>{{ $snapshot['totals']['subtotal'] }}</td>
            </tr>
            @foreach(($snapshot['charges'] ?? []) as $charge)
                <tr>
                    <td colspan="6" class="total-label">{{ $charge['label'] }}:</td>
                    <td>{{ $charge['amount'] }}</td>
                </tr>
            @endforeach
            @foreach(($snapshot['discounts'] ?? []) as $discount)
                <tr>
                    <td colspan="6" class="total-label">
                        {{ $discount['label'] }}@if($discount['discount_type'] === 'percentage') ({{ $discount['amount'] }}%)@endif:
                    </td>
                    <td>-{{ $discount['computed_amount'] }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="6" class="total-label">VAT Amount {{ $currencyDisplay }} After Discount ({{ ucfirst($snapshot['quotation']['vat_pricing'] ?? 'exclusive') }}):</td>
                <td>{{ $snapshot['totals']['vat'] ?? '0.000' }}</td>
            </tr>
            <tr>
                <td colspan="6" class="total-label">Total Amount {{ $currencyDisplay }} (Including VAT):</td>
                <td>{{ $snapshot['totals']['grand_total'] ?? $snapshot['totals']['subtotal'] }}</td>
            </tr>
        </tbody>
    </table>

    <div class="terms-title">Terms & Conditions:</div>
    @foreach($snapshot['terms'] as $term)
        <div class="term">
            <strong>{{ $term['line_number'] ?? $loop->iteration }}. {{ $term['title'] }}:</strong>
            {!! ($term['description_html'] ?? '') ?: e($term['description_plain'] ?? $term['description']) !!}
        </div>
    @endforeach

    @if($assets['stamp'] ?? null)
        <img class="abb" src="{{ $assets['stamp'] }}" alt="Industrial Supplies Center stamp">
    @endif

    @if($assets['abb'])
        <img class="abb" src="{{ $assets['abb'] }}" alt="ABB value provider">
    @endif

    <div class="signoff">Industrial Supplies Center LLC.</div>
</body>
</html>
