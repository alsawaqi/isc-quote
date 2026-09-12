<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @if($hasCalibri)
        @font-face { font-family: Calibri; font-style: normal; font-weight: normal; src: url('{{ $fontDirectory }}/calibri.ttf') format('truetype'); }
        @font-face { font-family: Calibri; font-style: normal; font-weight: bold; src: url('{{ $fontDirectory }}/calibrib.ttf') format('truetype'); }
        @endif
        @page { margin: 98pt 32pt 110pt; }
        body { margin: 0; padding: 0; color: #000; font-family: {{ $hasCalibri ? 'Calibri' : 'DejaVu Sans' }}; font-size: 11pt; line-height: 13.5pt; }
        h1 { margin: 0 0 3pt; font-size: 16pt; line-height: 18.5pt; text-align: center; }
        .red { color: #f00; }
        table { border-collapse: collapse; table-layout: fixed; }
        td, th { overflow-wrap: break-word; word-wrap: break-word; }
        .reference { width: 86%; margin: 0 auto 10pt; font-size: 10pt; line-height: 12pt; border-top: 0.5pt solid #000; }
        .reference td { padding: 1pt 0; }
        .reference td:first-child { padding-left: 7pt; }
        .reference td.right { padding-right: 18pt; }
        .right { text-align: right; }
        .info { width: 82.5%; margin: 0 auto; font-size: 10pt; line-height: 12pt; }
        .info td { border: 0.5pt solid #000; padding: 1pt 5pt; vertical-align: top; }
        .info .label td { background: #d9d9d9; font-weight: bold; padding-top: 1pt; padding-bottom: 1pt; }
        .info .company td { height: 47pt; }
        .info .contact td { height: 35pt; }
        .info a { color: #205e99; text-decoration: none; }
        .yellow { background: #ffff00; font-weight: bold; }
        .rfq { text-align: center; margin: 9.5pt 0 3pt; color: #f00; font-weight: bold; font-size: 10pt; line-height: 14pt; }
        .underline { border-bottom: 0.5pt solid; padding-bottom: 3pt; }
        .rfq .closing { font-size: 11pt; }
        .items { width: {{ $technical ? '92.3%' : '97.1%' }}; margin: 0 auto; }
        .items td, .items th { border: 0.75pt solid #000; padding: 1pt 5pt; }
        .items thead { display: table-header-group; }
        .items th { background: #d9d9d9; vertical-align: top; font-weight: bold; text-align: center; height:25pt; }
        .items th.description { text-align: left; }
        .items th:first-child { white-space: nowrap; padding-left: 2pt; padding-right: 2pt; }
        .items td { vertical-align: middle; text-align: center; }
        .items td.description { text-align: left; vertical-align: top; letter-spacing: -0.04pt; }
        .description p, .description div { margin: 0; }
        .description ul, .description ol { margin: 0; padding-left: 14pt; }
        .description img { max-width: 100%; }
        .items tr { page-break-inside: avoid; }
        .items .continued td { border-top: 0; }
        .items .summary td { background: #d9d9d9; font-weight: bold; height:18.5pt; }
        .items .summary .total-label { text-align: right; font-size: 10pt; padding-right: 0; }
        .price-note { font-size: 9pt; margin: 3pt 8pt 0; }
        .terms-title { margin: {{ $technical ? '29pt' : '28pt' }} 0 2pt 24pt; text-align: center; font-weight: bold; page-break-after: avoid; }
        .term { margin: 0 0 2pt 26pt; text-align: justify; line-height: 15pt; }
        .term .number { display: inline-block; width: 26pt; margin-left: -26pt; }
        .term .short-title { display: inline-block; min-width: 121pt; }
        .term .cyan { background: #00ffff; }
        .signoff { margin: 17pt 0 0 17pt; page-break-inside: avoid; }
        .stamp { width: 82pt; height: auto; margin: 0 0 8pt; }
        .signoff p { margin: 0; font-weight: bold; }
    </style>
</head>
<body>
    <h1>{{ $technical ? 'Technical Offer' : 'Commercial Offer' }}@if($snapshot['offer_rfq'])<span class="red"> – {{ $snapshot['offer_rfq'] }}</span>@endif</h1>
    <table class="reference"><tr><td>Ref: {{ $snapshot['quotation']['reference'] }}</td><td class="right">Dated: {{ $snapshot['quotation']['dated'] }}</td></tr></table>
    <table class="info">
        <tr class="label"><td style="width:50%">SUPPLIER</td><td style="width:50%">BUYER</td></tr>
        <tr class="company">
            @foreach(['supplier', 'buyer'] as $party)
            <td><strong>{{ $snapshot[$party]['name'] ?? '' }}</strong><br>{!! nl2br(e($snapshot[$party]['address'] ?? '')) !!}<br>{!! nl2br(e($snapshot[$party]['location'] ?? '')) !!}</td>
            @endforeach
        </tr>
        <tr class="label"><td>SUPPLIERS CONTACT</td><td>BUYERS CONTACT</td></tr>
        <tr class="contact">
            @foreach(['supplier_contact', 'buyer_contact'] as $party)
            <td>
                <strong>{{ trim(($snapshot[$party]['designation'] ?? '').' '.($snapshot[$party]['name'] ?? '')) }}</strong><br>
                @if($snapshot[$party]['mobile'] ?? null)<strong>Mob: {{ $snapshot[$party]['mobile'] }}</strong><br>@endif
                @if($snapshot[$party]['email'] ?? null)E-mail: <a href="mailto:{{ $snapshot[$party]['email'] }}">{{ $snapshot[$party]['email'] }}</a>@endif
            </td>
            @endforeach
        </tr>
        <tr class="label"><td>QUOTATION VALIDITY PERIOD</td><td>{{ $technical ? '' : 'ACCEPTED TERMS OF PAYMENT' }}</td></tr>
        <tr><td>{{ $snapshot['quotation']['validity'] }}</td><td>
            @unless($technical)
                @foreach($snapshot['offer_payment_terms'] as $paymentLine)
                    <div>{{ $paymentLine }}</div>
                @endforeach
            @endunless
        </td></tr>
        <tr class="label"><td>DATE OF DELIVERY</td><td>{{ $technical ? '' : 'ACCEPTED INVOICE CURRENCY' }}</td></tr>
        <tr><td><span class="yellow">{{ $snapshot['quotation']['delivery_period'] }}</span>@if($snapshot['offer_incoterms'])<br><span class="yellow red">INCOTERMS: {{ $snapshot['offer_incoterms'] }}</span>@endif</td><td><strong class="red">{{ $technical ? '' : $snapshot['quotation']['currency'] }}</strong></td></tr>
    </table>
    <div class="rfq">
        <span class="underline">{{ $snapshot['quotation']['rfq_title'] ?? $snapshot['offer_rfq'] }}</span>
        @if($snapshot['quotation']['closing_at'] ?? null)<br><span class="closing underline">Closing Date &amp; time: {{ $snapshot['quotation']['closing_at'] }}</span>@endif
    </div>
    <table class="items">
        <colgroup>
            @if($technical)
            <col style="width:10.7%"><col style="width:79.2%"><col style="width:10.1%">
            @else
            <col style="width:6.8%"><col style="width:62%"><col style="width:8.5%"><col style="width:11%"><col style="width:11.7%">
            @endif
        </colgroup>
        <thead><tr><th style="width:{{ $technical ? '10.7%' : '6.8%' }}">SL No</th><th class="description" style="width:{{ $technical ? '79.2%' : '62%' }}">Description</th><th style="width:{{ $technical ? '10.1%' : '8.5%' }}">QTY</th>@unless($technical)<th style="width:11%">Unit<br>Price</th><th style="width:11.7%">Total excl.<br>VAT</th>@endunless</tr></thead>
        <tbody>
            @foreach($snapshot['items'] as $item)
                @foreach($item['offer_chunks'] as $chunkIndex => $chunk)
                <tr @class(['continued' => $chunkIndex > 0])>
                    <td>{{ $chunkIndex === 0 ? $item['line_number'] : '' }}</td>
                    <td class="description">
                        @if($chunkIndex === 0)
                            <strong>{{ $item['title'] }}</strong><br>
                            @if($item['offer_code'])Material / Item Code: {{ $item['offer_code'] }}<br>@endif
                        @endif
                        @if(count($item['offer_chunks']) === 1)
                            {!! $item['offer_description'] !!}
                        @else
                            {!! nl2br(e($chunk)) !!}
                        @endif
                        @if($loop->last && $item['offer_manufacturer'])<div><strong>MFG: {{ $item['offer_manufacturer'] }}</strong></div>@endif
                    </td>
                    <td>{{ $chunkIndex === 0 ? $layout->quantity($item['quantity']).' '.$item['uom'] : '' }}</td>
                    @unless($technical)
                    <td>{{ $chunkIndex === 0 ? $layout->money($item['unit_price']) : '' }}</td>
                    <td>{{ $chunkIndex === 0 ? $layout->money($item['total_price']) : '' }}</td>
                    @endunless
                </tr>
                @endforeach
            @endforeach
            @unless($technical)
                @foreach($snapshot['offer_totals'] as [$label, $value])
                <tr class="summary"><td></td><td colspan="3" class="total-label"><span class="underline">{{ $label }}</span></td><td><span class="underline">{{ $layout->money($value) }}</span></td></tr>
                @endforeach
            @endunless
        </tbody>
    </table>
    @if(!$technical && ($snapshot['quotation']['vat_pricing'] ?? 'exclusive') === 'inclusive')
    <p class="price-note">Unit prices include VAT; line totals exclude VAT.</p>
    @endif
    <div class="terms-title"><span class="underline">Terms &amp; Conditions:</span></div>
    @foreach($snapshot['terms'] as $index => $term)
    <div class="term"><span class="number">{{ $term['line_number'] ?? $index + 1 }}.</span><span @class(['cyan' => stripos($term['title'], 'Delivery Terms') !== false])><strong @class(['short-title' => mb_strlen($term['title']) < 25])>{{ $term['title'] }}</strong> : {{ $term['description_plain'] ?? strip_tags($term['description_html'] ?? $term['description']) }}</span></div>
    @endforeach
    <div class="signoff">
        @if($stamp)<img class="stamp" src="{{ $stamp }}" alt="Industrial Supplies Center stamp">@endif
        <p>Industrial Supplies Center LLC.</p>
    </div>
</body>
</html>
