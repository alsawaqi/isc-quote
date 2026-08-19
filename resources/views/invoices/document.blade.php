<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @include('documents.partials.page-chrome-css')

        body { color: #111827; font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; }
        h1 { color: #1f4e79; font-size: 20px; margin: 10px 0 12px; text-align: center; }
        table { border-collapse: collapse; width: 100%; }
        td, th { border: 1px solid #bfbfbf; padding: 6px; vertical-align: top; }
        th { background: #1f4e79; color: #fff; font-weight: bold; text-align: center; }
        .info .label { color: #1f4e79; font-weight: bold; }
        .items { margin-top: 14px; }
        .items td:nth-child(1), .items td:nth-child(2), .items td:nth-child(4) { text-align: center; }
        .amount { text-align: right; }
        .bank { margin-top: 14px; white-space: pre-line; }

    </style>
</head>
<body>
    <h1>Tax Invoice</h1>

    <table class="info">
        <tr>
            <td><strong>Ref:</strong> {{ $snapshot['invoice']['reference'] }}</td>
            <td><strong>Dated:</strong> {{ $snapshot['invoice']['dated'] }}</td>
        </tr>
        <tr>
            <td><span class="label">SUPPLIER</span><br>@foreach (array_filter($snapshot['supplier']) as $line){{ $line }}<br>@endforeach</td>
            <td><span class="label">BUYER</span><br>@foreach (array_filter($snapshot['buyer']) as $line){{ $line }}<br>@endforeach</td>
        </tr>
        <tr>
            <td><span class="label">SUPPLIERS CONTACT</span><br>@foreach (array_filter($snapshot['supplier_contact']) as $line){{ $line }}<br>@endforeach</td>
            <td><span class="label">BUYERS CONTACT</span><br>@foreach (array_filter($snapshot['buyer_contact']) as $line){{ $line }}<br>@endforeach</td>
        </tr>
        <tr>
            <td><span class="label">PAYMENT TERMS</span><br>{{ $snapshot['invoice']['payment_terms'] }}</td>
            <td><span class="label">DUE DATE</span><br>{{ $snapshot['invoice']['due_date'] }}</td>
        </tr>
        <tr>
            <td><span class="label">SUPPLIER DO REF</span><br>{{ $snapshot['delivery_order']['reference'] ?: '-' }}</td>
            <td><span class="label">BUYER LPO NO.</span><br>{{ $snapshot['buyer_po']['number'] ?: '-' }}</td>
        </tr>
    </table>

    @if (! empty($snapshot['payment_schedule']))
        <div class="bank">
            <strong>Agreed Payment Schedule</strong><br>
            @foreach ($snapshot['payment_schedule'] as $schedule)
                {{ $schedule['line_number'] }}. {{ $schedule['label'] }}: {{ $schedule['payment_percentage'] }}% by {{ $schedule['payment_method'] }}, {{ $schedule['due'] }}@if($schedule['notes']) - {{ $schedule['notes'] }}@endif<br>
            @endforeach
        </div>
    @endif

    <table class="items items-table">
        <thead>
            <tr>
                <th style="width: 10%;">SL No</th>
                <th style="width: 14%;">Buyer Item Code</th>
                <th>Item Description</th>
                <th style="width: 14%;">Qty</th>
                <th style="width: 16%;">Unit Price</th>
                <th style="width: 16%;">Total excl. VAT</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($snapshot['items'] as $item)
                @foreach ($item['pdf_description_chunks'] ?? [(string) $item['description']] as $chunkIndex => $descriptionChunk)
                    <tr @class(['item-continuation' => $chunkIndex > 0])>
                        <td>{{ $chunkIndex === 0 ? $item['line_number'] : '' }}</td>
                        <td>{{ $chunkIndex === 0 ? ($item['buyer_item_code'] ?: '-') : '' }}</td>
                        <td>{!! nl2br(e($descriptionChunk)) !!}</td>
                        <td>{{ $chunkIndex === 0 ? $item['quantity'].' '.$item['uom'] : '' }}</td>
                        <td class="amount">{{ $chunkIndex === 0 ? $item['unit_price'] : '' }}</td>
                        <td class="amount">{{ $chunkIndex === 0 ? $item['total_price'] : '' }}</td>
                    </tr>
                @endforeach
            @endforeach
            <tr>
                <td colspan="5" class="amount"><strong>Total Excluding VAT {{ $snapshot['invoice']['currency'] }}</strong></td>
                <td class="amount"><strong>{{ $snapshot['invoice']['subtotal'] }}</strong></td>
            </tr>
            <tr>
                <td colspan="5" class="amount"><strong>VAT {{ $snapshot['invoice']['vat_rate'] }}%</strong></td>
                <td class="amount"><strong>{{ $snapshot['invoice']['vat_amount'] }}</strong></td>
            </tr>
            <tr>
                <td colspan="5" class="amount"><strong>Total Including VAT {{ $snapshot['invoice']['currency'] }}</strong></td>
                <td class="amount"><strong>{{ $snapshot['invoice']['total_amount'] }}</strong></td>
            </tr>
        </tbody>
    </table>

    @if ($snapshot['invoice']['bank_details'])
        <div class="bank"><strong>Bank Details</strong><br>{{ $snapshot['invoice']['bank_details'] }}</div>
    @endif

    @if ($snapshot['invoice']['remarks'])
        <p><em>Remarks: {{ $snapshot['invoice']['remarks'] }}</em></p>
    @endif

</body>
</html>
