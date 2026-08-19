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
        th { background: #d9d9d9; font-weight: bold; text-align: center; }
        .label { color: #1f4e79; font-weight: bold; }
        .items { margin-top: 14px; }
        .signature td { height: 70px; text-align: center; }

    </style>
</head>
<body>
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

    <table class="items items-table">
        <thead>
            <tr>
                <th style="width: 12%;">SL No</th>
                <th style="width: 18%;">Buyer Item Code</th>
                <th>Item Description</th>
                <th style="width: 18%;">Qty</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($snapshot['items'] as $item)
                @foreach ($item['pdf_description_chunks'] ?? [(string) $item['description']] as $chunkIndex => $descriptionChunk)
                    <tr @class(['item-continuation' => $chunkIndex > 0])>
                        <td style="text-align: center;">{{ $chunkIndex === 0 ? $item['line_number'] : '' }}</td>
                        <td style="text-align: center;">{{ $chunkIndex === 0 ? ($item['buyer_item_code'] ?: '-') : '' }}</td>
                        <td>{!! nl2br(e($descriptionChunk)) !!}</td>
                        <td style="text-align: center;">{{ $chunkIndex === 0 ? $item['quantity'].' '.$item['uom'] : '' }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>

    <table class="signature document-keep-together" style="margin-top: 24px;">
        <tr>
            <th>Delivered By</th>
            <th>Received By / Customer Signature</th>
        </tr>
        <tr>
            <td></td>
            <td></td>
        </tr>
    </table>

</body>
</html>
