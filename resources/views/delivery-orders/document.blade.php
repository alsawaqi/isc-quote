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
        th { background: #d9d9d9; font-weight: bold; text-align: center; }
        .label { color: #1f4e79; font-weight: bold; }
        .items { margin-top: 14px; }
        .signature td { height: 70px; text-align: center; }
        .footer { margin-top: 20px; }
    </style>
</head>
<body>
    @if ($assets['header'])
        <div class="header"><img src="{{ $assets['header'] }}" alt=""></div>
    @endif

    <h1>Delivery Order</h1>

    <table>
        <tr>
            <td><strong>Ref:</strong> {{ $snapshot['delivery_order']['reference'] }}</td>
            <td><strong>Dated:</strong> {{ $snapshot['delivery_order']['dated'] }}</td>
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
            <td><span class="label">LPO NO:</span><br>{{ $snapshot['buyer_po']['number'] ?: '-' }}</td>
            <td><span class="label">DATED:</span><br>{{ $snapshot['buyer_po']['date'] ?: '-' }}</td>
        </tr>
        <tr>
            <td><span class="label">DELIVERY PLACE</span><br>{{ $snapshot['delivery_order']['delivery_place'] }}</td>
            <td><span class="label">TERMS</span><br>{{ $snapshot['delivery_order']['terms'] ?: '-' }}</td>
        </tr>
    </table>

    <table class="items">
        <tr>
            <th style="width: 12%;">SL No</th>
            <th>Item Description</th>
            <th style="width: 18%;">Qty</th>
        </tr>
        @foreach ($snapshot['items'] as $item)
            <tr>
                <td style="text-align: center;">{{ $item['line_number'] }}</td>
                <td>{!! nl2br(e($item['description'])) !!}</td>
                <td style="text-align: center;">{{ $item['quantity'] }} {{ $item['uom'] }}</td>
            </tr>
        @endforeach
    </table>

    <table class="signature" style="margin-top: 24px;">
        <tr>
            <th>Delivered By</th>
            <th>Received By / Customer Signature</th>
        </tr>
        <tr>
            <td></td>
            <td></td>
        </tr>
    </table>

    @if ($assets['footer'])
        <div class="footer"><img src="{{ $assets['footer'] }}" alt=""></div>
    @endif
</body>
</html>
