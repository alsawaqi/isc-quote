<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        body { color: #111827; font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; margin: 22px; }
        .header, .footer { text-align: center; }
        .header img, .footer img { max-width: 100%; }
        h1 { color: #1f4e79; font-size: 20px; margin: 10px 0 12px; text-align: center; }
        table { border-collapse: collapse; width: 100%; }
        td, th { border: 1px solid #bfbfbf; padding: 6px; vertical-align: top; }
        th { background: #1f4e79; color: #fff; font-weight: bold; text-align: center; }
        .info .label { color: #1f4e79; font-weight: bold; }
        .items { margin-top: 14px; }
        .items td:nth-child(1), .items td:nth-child(3) { text-align: center; }
        .amount { text-align: right; }
        .footer { margin-top: 20px; }
        .bank { margin-top: 14px; white-space: pre-line; }
    </style>
</head>
<body>
    @if ($assets['header'])
        <div class="header"><img src="{{ $assets['header'] }}" alt=""></div>
    @endif

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

    <table class="items">
        <tr>
            <th style="width: 10%;">SL No</th>
            <th>Item Description</th>
            <th style="width: 14%;">Qty</th>
            <th style="width: 16%;">Unit Price</th>
            <th style="width: 16%;">Total excl. VAT</th>
        </tr>
        @foreach ($snapshot['items'] as $item)
            <tr>
                <td>{{ $item['line_number'] }}</td>
                <td>{!! nl2br(e($item['description'])) !!}</td>
                <td>{{ $item['quantity'] }} {{ $item['uom'] }}</td>
                <td class="amount">{{ $item['unit_price'] }}</td>
                <td class="amount">{{ $item['total_price'] }}</td>
            </tr>
        @endforeach
        <tr>
            <td colspan="4" class="amount"><strong>Total Excluding VAT {{ $snapshot['invoice']['currency'] }}</strong></td>
            <td class="amount"><strong>{{ $snapshot['invoice']['subtotal'] }}</strong></td>
        </tr>
        <tr>
            <td colspan="4" class="amount"><strong>VAT {{ $snapshot['invoice']['vat_rate'] }}%</strong></td>
            <td class="amount"><strong>{{ $snapshot['invoice']['vat_amount'] }}</strong></td>
        </tr>
        <tr>
            <td colspan="4" class="amount"><strong>Total Including VAT {{ $snapshot['invoice']['currency'] }}</strong></td>
            <td class="amount"><strong>{{ $snapshot['invoice']['total_amount'] }}</strong></td>
        </tr>
    </table>

    @if ($snapshot['invoice']['bank_details'])
        <div class="bank"><strong>Bank Details</strong><br>{{ $snapshot['invoice']['bank_details'] }}</div>
    @endif

    @if ($snapshot['invoice']['remarks'])
        <p><em>Remarks: {{ $snapshot['invoice']['remarks'] }}</em></p>
    @endif

    @if ($assets['footer'])
        <div class="footer"><img src="{{ $assets['footer'] }}" alt=""></div>
    @endif
</body>
</html>
