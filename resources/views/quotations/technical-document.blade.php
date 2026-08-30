<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @include('documents.partials.page-chrome-css')

        body {
            color: #111111;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 11px;
            line-height: 1.35;
        }

        .title {
            font-size: 17px;
            font-weight: 700;
            margin: 30px 0 4px;
            text-align: center;
        }

        .title .rfq {
            color: #ff0000;
        }

        .ref-line {
            border-top: 1px solid #111111;
            font-size: 11px;
            margin: 0 auto 12px;
            padding-top: 3px;
            width: 78%;
        }

        .ref-line .right {
            float: right;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        .info-table {
            margin-bottom: 18px;
        }

        .info-table td,
        .items-table td,
        .items-table th {
            border: 1px solid #111111;
            padding: 5px 7px;
            vertical-align: top;
        }

        .section-label,
        .items-table th {
            background: #d9d9d9;
            font-weight: 700;
        }

        .section-label {
            display: block;
            margin: -5px -7px 4px;
            padding: 3px 7px;
        }

        .delivery-highlight {
            background: #fff200;
            font-weight: 700;
        }

        .rfq-line {
            color: #ff0000;
            font-weight: 700;
            margin: 8px 0 6px;
            text-align: center;
            text-decoration: underline;
        }

        .items-table {
            margin-top: 4px;
        }

        .items-table th {
            text-align: center;
        }

        .items-table td:first-child,
        .items-table td:last-child {
            text-align: center;
            vertical-align: middle;
        }

        .product-title {
            font-weight: 700;
            margin-bottom: 5px;
        }

        .product-code {
            font-weight: 700;
            margin: 3px 0;
        }

        .terms-title {
            font-weight: 700;
            margin: 26px 0 6px;
            text-align: center;
            text-decoration: underline;
        }

        .term-row {
            clear: both;
            margin-bottom: 7px;
        }

        .term-number {
            display: inline-block;
            width: 28px;
        }

        .term-title {
            display: inline-block;
            font-weight: 700;
            width: 118px;
        }

        .term-separator {
            display: inline-block;
            width: 10px;
        }

        .term-text {
            display: inline;
        }

        .terms-block {
            page-break-inside: avoid;
        }

        .stamp {
            margin: 22px 0 8px 52px;
            width: 105px;
        }

        .signoff {
            font-weight: 700;
            margin-left: 52px;
        }

        .highlight,
        mark,
        span[style*="background-color"] {
            background: #fff59d;
            padding: 0 1px;
        }

    </style>
</head>
<body>
    @php
        $rfqTitle = $snapshot['quotation']['rfq_title'] ?? trim(implode(' ', array_filter([
            ! empty($snapshot['quotation']['rfq_number']) ? 'RFQ '.$snapshot['quotation']['rfq_number'] : null,
            ! empty($snapshot['quotation']['pr_number']) ? 'PR '.$snapshot['quotation']['pr_number'] : null,
        ])));
    @endphp

    <div class="title">
        Technical Offer
        @if($rfqTitle)
            - <span class="rfq">{{ $rfqTitle }}</span>
        @endif
    </div>

    <div class="ref-line">
        Ref: {{ $snapshot['quotation']['reference'] }}
        <span class="right">Dated: {{ $snapshot['quotation']['dated'] }}</span>
    </div>

    <table class="info-table">
        <tr>
            <td style="width: 50%;">
                <span class="section-label">SUPPLIER</span>
                <strong>{{ $snapshot['supplier']['name'] ?? '' }}</strong><br>
                {{ $snapshot['supplier']['address'] ?? '' }}<br>
                {{ $snapshot['supplier']['location'] ?? '' }}
            </td>
            <td style="width: 50%;">
                <span class="section-label">BUYER</span>
                <strong>{{ $snapshot['buyer']['name'] ?? '' }}</strong><br>
                {{ $snapshot['buyer']['address'] ?? '' }}<br>
                {{ $snapshot['buyer']['location'] ?? '' }}
            </td>
        </tr>
        <tr>
            <td>
                <span class="section-label">SUPPLIERS CONTACT</span>
                <strong>{{ trim(($snapshot['supplier_contact']['designation'] ? $snapshot['supplier_contact']['designation'].' ' : '').($snapshot['supplier_contact']['name'] ?? '')) }}</strong><br>
                @if($snapshot['supplier_contact']['mobile'] ?? null)<strong>Mob: {{ $snapshot['supplier_contact']['mobile'] }}</strong><br>@endif
                @if($snapshot['supplier_contact']['email'] ?? null)E-mail: {{ $snapshot['supplier_contact']['email'] }}@endif
            </td>
            <td>
                <span class="section-label">BUYERS CONTACT</span>
                <strong>{{ trim(($snapshot['buyer_contact']['designation'] ? $snapshot['buyer_contact']['designation'].' ' : '').($snapshot['buyer_contact']['name'] ?? '')) }}</strong><br>
                @if($snapshot['buyer_contact']['mobile'] ?? null)<strong>Mob: {{ $snapshot['buyer_contact']['mobile'] }}</strong><br>@endif
                @if($snapshot['buyer_contact']['email'] ?? null)E-mail: {{ $snapshot['buyer_contact']['email'] }}@endif
            </td>
        </tr>
        <tr>
            <td>
                <span class="section-label">QUOTATION VALIDITY PERIOD</span>
                {{ $technicalValidity }}
            </td>
            <td>&nbsp;</td>
        </tr>
        <tr>
            <td>
                <span class="section-label">DELIVERY PERIOD</span>
                <span class="delivery-highlight">{{ $snapshot['quotation']['delivery_period'] }}</span>
            </td>
            <td><span class="section-label">&nbsp;</span>&nbsp;</td>
        </tr>
    </table>

    @if($rfqTitle)
        <div class="rfq-line">{{ $rfqTitle }}</div>
    @endif

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 8%;">SL No</th>
                <th style="width: 18%;">Material / Item Code</th>
                <th style="width: 63%; text-align: left;">Description</th>
                <th style="width: 11%;">QTY</th>
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
                                @if($item['incoterm'] ?? null)
                                    <div class="product-code">
                                        Incoterm: {{ $item['incoterm'] }}
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
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>

    <div class="terms-block">
        <div class="terms-title">Terms & Conditions:</div>
        @foreach($snapshot['terms'] as $index => $term)
            <div class="term-row">
                <span class="term-number">{{ $term['line_number'] ?? $index + 1 }}.</span>
                <span class="term-title">{{ $term['title'] }}</span>
                <span class="term-separator">:</span>
                <span class="term-text">{!! ($term['description_html'] ?? '') ?: e($term['description_plain'] ?? $term['description']) !!}</span>
            </div>
        @endforeach

        @if($assets['stamp'])
            <img class="stamp" src="{{ $assets['stamp'] }}" alt="Industrial Supplies Center stamp">
        @endif
        <div class="signoff">Industrial Supplies Center LLC</div>
    </div>
</body>
</html>
