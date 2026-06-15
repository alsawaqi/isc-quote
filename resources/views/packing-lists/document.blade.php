<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        body {
            color: #111c25;
            font-family: Arial, sans-serif;
            font-size: 11px;
            margin: 22px;
        }

        h1 {
            color: #1f4e79;
            font-size: 20px;
            margin: 0 0 12px;
            text-align: center;
        }

        table {
            border-collapse: collapse;
            margin-bottom: 12px;
            width: 100%;
        }

        td,
        th {
            border: 1px solid #bfbfbf;
            padding: 7px;
            vertical-align: top;
        }

        th {
            background: #d9d9d9;
            font-weight: 700;
            text-align: center;
        }

        .label {
            color: #1f4e79;
            font-weight: 700;
        }

        .center {
            text-align: center;
        }
    </style>
</head>
<body>
    <h1>Packing List</h1>

    <table>
        <tr>
            <td><strong>Ref:</strong> {{ $snapshot['packing_list']['reference'] }}</td>
            <td><strong>Dated:</strong> {{ $snapshot['packing_list']['dated'] }}</td>
        </tr>
    </table>

    <table>
        <tr>
            <td>
                <p class="label">SUPPLIER</p>
                @foreach (array_filter([$snapshot['supplier']['name'] ?? null, $snapshot['supplier']['address'] ?? null, $snapshot['supplier']['location'] ?? null]) as $line)
                    <div>{{ $line }}</div>
                @endforeach
            </td>
            <td>
                <p class="label">BUYER</p>
                @foreach (array_filter([$snapshot['buyer']['name'] ?? null, $snapshot['buyer']['address'] ?? null, $snapshot['buyer']['location'] ?? null]) as $line)
                    <div>{{ $line }}</div>
                @endforeach
            </td>
        </tr>
        <tr>
            <td>
                <p class="label">SUPPLIERS CONTACT</p>
                @foreach (array_filter([$snapshot['supplier_contact']['name'] ?? null, $snapshot['supplier_contact']['mobile'] ?? null, $snapshot['supplier_contact']['email'] ?? null]) as $line)
                    <div>{{ $line }}</div>
                @endforeach
            </td>
            <td>
                <p class="label">BUYERS CONTACT</p>
                @foreach (array_filter([$snapshot['buyer_contact']['name'] ?? null, $snapshot['buyer_contact']['job_title'] ?? null, $snapshot['buyer_contact']['email'] ?? null]) as $line)
                    <div>{{ $line }}</div>
                @endforeach
            </td>
        </tr>
        <tr>
            <td><span class="label">LPO NO:</span> {{ $snapshot['buyer_po']['number'] ?? '-' }}</td>
            <td><span class="label">DATED:</span> {{ $snapshot['buyer_po']['date'] ?? '-' }}</td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th style="width: 8%;">SL No</th>
                <th>Item Description</th>
                <th style="width: 10%;">Qty</th>
                <th style="width: 18%;">Size</th>
                <th style="width: 18%;">Gross / Net KG</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($snapshot['items'] as $item)
                <tr>
                    <td class="center">{{ $item['line_number'] }}</td>
                    <td>{!! nl2br(e($item['description'])) !!}</td>
                    <td class="center">{{ $item['quantity'] }}{{ $item['uom'] }}</td>
                    <td class="center">{{ $item['package_size'] }}</td>
                    <td class="center">{{ $item['gross_weight'] }} / {{ $item['net_weight'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if (! empty($snapshot['packing_list']['remarks']))
        <p><strong>Remarks:</strong> {{ $snapshot['packing_list']['remarks'] }}</p>
    @endif
</body>
</html>
