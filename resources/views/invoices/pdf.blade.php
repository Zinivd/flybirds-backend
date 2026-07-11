<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18mm 15mm; }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            color: #222222;
            font-size: 13px;
        }

        /* ===== HEADER (table-based, flex not reliable in dompdf) ===== */
        .header-table { width: 100%; margin-bottom: 24px; }
        .header-table td { vertical-align: top; }
        .header-right { text-align: right; }

        .invoice-title {
            font-size: 26px;
            font-weight: 700;
            color: #1a2b49;
            margin: 0 0 8px 0;
        }
        .meta-line { margin: 2px 0; font-size: 13px; color: #444444; }
        .meta-label { font-weight: 600; color: #1a2b49; }

        .brand-logo { height: 60px; max-width: 180px; }
        .brand-logo-fallback {
            font-size: 22px;
            font-weight: 700;
            color: #1a2b49;
            letter-spacing: 1px;
        }

        /* ===== SECTIONS ===== */
        .section { margin-bottom: 18px; }
        .section-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #1a2b49;
            margin: 0 0 8px 0;
        }
        .section p { margin: 3px 0; font-size: 13px; color: #333333; }
        .party-name { font-weight: 600; color: #111111; }
        .address-block { white-space: pre-line; }

        .two-col-table { width: 100%; }
        .two-col-table td { width: 50%; vertical-align: top; padding-right: 20px; }

        .divider { border: none; border-top: 1px solid #e2e2e2; margin: 16px 0; }

        /* ===== ITEMS TABLE ===== */
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .items-table thead tr { background: #f5f6f8; }
        .items-table th {
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #1a2b49;
            padding: 10px 8px;
            border-bottom: 2px solid #e2e2e2;
        }
        .items-table td {
            font-size: 13px;
            padding: 10px 8px;
            border-bottom: 1px solid #eeeeee;
            color: #333333;
        }
        .col-qty, .col-rate, .col-amount { text-align: right; }
        .items-table th.col-qty, .items-table th.col-rate, .items-table th.col-amount { text-align: right; }
        .no-items { text-align: center; color: #888888; padding: 20px !important; }

        /* ===== SUMMARY (table-based) ===== */
        .summary-table { width: 100%; margin-top: 10px; }
        .summary-table td { vertical-align: top; }
        .payment-block p { margin: 4px 0; font-size: 13px; }

        .status-badge {
            display: inline-block;
            margin-top: 6px;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-paid { background: #e3f7e9; color: #1e8a4c; }
        .status-pending { background: #fff4e0; color: #b57b00; }
        .status-failed { background: #fdeaea; color: #c0392b; }
        .status-refunded { background: #eaeef7; color: #38507c; }

        .totals-block { width: 240px; float: right; }
        .totals-row {
            width: 100%;
            font-size: 13px;
            padding: 6px 0;
            color: #444444;
        }
        .totals-row td { padding: 6px 0; }
        .totals-row .val { text-align: right; }
        .totals-row.grand-total td {
            border-top: 2px solid #1a2b49;
            padding-top: 10px;
            font-size: 16px;
            font-weight: 700;
            color: #1a2b49;
        }

        .invoice-footer {
            text-align: center;
            margin-top: 40px;
            padding-top: 16px;
            border-top: 1px solid #eeeeee;
            font-size: 12px;
            color: #888888;
        }
        .invoice-footer p { margin: 2px 0; }
    </style>
</head>
<body>

    {{-- Header: Title + Logo --}}
    <table class="header-table">
        <tr>
            <td>
                <h1 class="invoice-title">Tax Invoice</h1>
                <p class="meta-line"><span class="meta-label">Invoice No:</span> {{ $data['invoice_no'] }}</p>
                <p class="meta-line"><span class="meta-label">Invoice Date:</span> {{ $data['invoice_date'] }}</p>
            </td>
            <td class="header-right">
                @if (!empty($data['company']['logo_url']))
                    <img src="{{ $data['company']['logo_url'] }}" alt="{{ $data['company']['name'] }}" class="brand-logo" />
                @else
                    <div class="brand-logo-fallback">{{ $data['company']['name'] }}</div>
                @endif
            </td>
        </tr>
    </table>

    {{-- Bill From --}}
    <div class="section">
        <h3 class="section-title">Bill From</h3>
        <p class="party-name">{{ $data['company']['name'] }}</p>
        @if (!empty($data['company']['address_line1']))<p>{{ $data['company']['address_line1'] }}</p>@endif
        @if (!empty($data['company']['address_line2']))<p>{{ $data['company']['address_line2'] }}</p>@endif
        @if (!empty($data['company']['city_state_zip']))<p>{{ $data['company']['city_state_zip'] }}</p>@endif
        @if (!empty($data['company']['email']))<p>Email: {{ $data['company']['email'] }}</p>@endif
        @if (!empty($data['company']['phone']))<p>Phone: {{ $data['company']['phone'] }}</p>@endif
        @if (!empty($data['company']['gstin']))<p>GSTIN: {{ $data['company']['gstin'] }}</p>@endif
    </div>
    <hr class="divider" />

    {{-- Shipping / Billing --}}
    <table class="two-col-table">
        <tr>
            <td>
                <h3 class="section-title">Shipping Address</h3>
                <p class="party-name">{{ $data['shipping']['name'] }}</p>
                <p class="address-block">{{ $data['shipping']['address'] }}</p>
                <p>Email: {{ $data['shipping']['email'] }}</p>
            </td>
            <td>
                <h3 class="section-title">Billing Address</h3>
                <p class="party-name">{{ $data['billing']['name'] }}</p>
                <p class="address-block">{{ $data['billing']['address'] }}</p>
                <p>Email: {{ $data['billing']['email'] }}</p>
            </td>
        </tr>
    </table>
    <hr class="divider" />

    {{-- Order Details --}}
    <div class="section">
        <h3 class="section-title">Order Details</h3>
        <p><span class="meta-label">Order Number:</span> {{ $data['order_id'] }}</p>
        @if (!empty($data['awb_number']))<p><span class="meta-label">AWB Number:</span> {{ $data['awb_number'] }}</p>@endif
        <p><span class="meta-label">Sale Date:</span> {{ $data['sale_date'] }}</p>
    </div>
    <hr class="divider" />

    {{-- Items Table --}}
    <table class="items-table">
        <thead>
            <tr>
                <th class="col-desc">Item Description</th>
                <th class="col-sku">SKU Code</th>
                <th class="col-qty">Qty</th>
                <th class="col-rate">Rate</th>
                <th class="col-amount">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($data['items'] as $item)
                <tr>
                    <td class="col-desc">{{ $item['description'] }}</td>
                    <td class="col-sku">{{ $item['sku'] ?? '-' }}</td>
                    <td class="col-qty">{{ $item['qty'] }}</td>
                    <td class="col-rate">INR {{ number_format($item['rate'], 2) }}</td>
                    <td class="col-amount">INR {{ number_format($item['amount'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="no-items">No items on this order.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- Summary + Payment --}}
    <table class="summary-table">
        <tr>
            <td style="width: 60%;">
                <div class="payment-block">
                    <h3 class="section-title">Payment Type</h3>
                    <p>{{ $data['payment_method'] }}</p>
                    <span class="status-badge status-{{ strtolower($data['payment_status']) }}">
                        {{ $data['payment_status'] }}
                    </span>
                </div>
            </td>
            <td style="width: 40%;">
                <table class="totals-block" style="width:100%;">
                    <tr class="totals-row">
                        <td>Subtotal</td>
                        <td class="val">INR {{ number_format($data['subtotal'], 2) }}</td>
                    </tr>
                    @if ($data['discount'] > 0)
                        <tr class="totals-row">
                            <td>Discount</td>
                            <td class="val">- INR {{ number_format($data['discount'], 2) }}</td>
                        </tr>
                    @endif
                    @if ($data['shipping_charge'] > 0)
                        <tr class="totals-row">
                            <td>Shipping</td>
                            <td class="val">INR {{ number_format($data['shipping_charge'], 2) }}</td>
                        </tr>
                    @endif
                    @if ($data['tax'] > 0)
                        <tr class="totals-row">
                            <td>Tax</td>
                            <td class="val">INR {{ number_format($data['tax'], 2) }}</td>
                        </tr>
                    @endif
                    <tr class="totals-row grand-total">
                        <td>Total</td>
                        <td class="val">INR {{ number_format($data['total'], 2) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- Footer --}}
    <div class="invoice-footer">
        <p>Thank you for shopping with Flybirds.</p>
        @if (!empty($data['company']['website']))<p>{{ $data['company']['website'] }}</p>@endif
    </div>

</body>
</html>