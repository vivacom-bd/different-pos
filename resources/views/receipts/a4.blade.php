{{-- A4 tax invoice: seller and buyer BIN, customer number, shop name, VAT summary. --}}
@extends('receipts.layout')

@section('title', __('receipts.tax_invoice').' '.$r['number'])

@section('content')
    <div class="grid-2">
        <div>@include('receipts._shop')</div>
        <div class="right">
            <div class="title" style="font-size: 1.6em;">{{ __('receipts.tax_invoice') }}</div>
            <div>{{ __('receipts.invoice_no') }}: <span class="num strong">{{ $r['number'] }}</span></div>
            <div>{{ __('receipts.date') }}: <span class="num">{{ $r['date'] }}</span></div>
            @if ($r['courier'])<div>{{ __('receipts.courier') }}: {{ $r['courier'] }}</div>@endif
            @if ($r['cashier'])<div>{{ __('receipts.cashier') }}: {{ $r['cashier'] }}</div>@endif
            <div class="codes" style="text-align: right;">{!! $r['barcode'] !!}</div>
        </div>
    </div>
    @if ($r['voided'])
        <div class="void-stamp">{{ __('receipts.voided') }}@if ($r['void_reason']) · {{ $r['void_reason'] }}@endif</div>
    @endif
    @if ($r['customer'])
        <div class="box" style="margin: 4mm 0;">@include('receipts._customer')</div>
    @endif
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>{{ __('receipts.item') }}</th>
                <th class="right">{{ __('receipts.qty') }}</th>
                <th class="right">{{ __('receipts.price') }}</th>
                <th class="right">{{ __('receipts.discount') }}</th>
                <th class="right">{{ __('receipts.vat') }}</th>
                <th class="right">{{ __('receipts.amount') }}</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($r['lines'] as $i => $line)
            <tr>
                <td class="num">{{ $r['locale'] === 'bn' ? \App\Support\Numbers\Digits::toBangla((string) ($i + 1)) : $i + 1 }}</td>
                <td>{{ $line['name'] }}<div class="small muted">{{ $line['sku'] }}</div></td>
                <td class="right num">{{ $line['qty'] }}</td>
                <td class="right num">{{ $line['unit_price'] }}</td>
                <td class="right num">{{ $line['discount'] ?? '—' }}</td>
                <td class="right num">{{ $line['vat_rate'] ?? '—' }}</td>
                <td class="right num">{{ $line['total'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div class="grid-2" style="margin-top: 4mm;">
        <div>
            @if ($r['vat_summary'])
                <div class="strong">{{ __('receipts.vat_summary') }}</div>
                <table>
                    <thead><tr><th>{{ __('receipts.vat_rate') }}</th><th class="right">{{ __('receipts.taxable') }}</th><th class="right">{{ __('receipts.vat') }}</th></tr></thead>
                    <tbody>
                    @foreach ($r['vat_summary'] as $v)
                        <tr><td class="num">{{ $v['rate'] }}</td><td class="right num">{{ $v['taxable'] }}</td><td class="right num">{{ $v['tax'] }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
            @if ($r['payments'])
                <div class="strong" style="margin-top: 3mm;">{{ __('receipts.payments') }}</div>
                <table>
                    @foreach ($r['payments'] as $p)
                        <tr><td class="num">{{ $p['date'] }}</td><td>{{ $p['method'] }}@if ($p['reference']) ({{ $p['reference'] }})@endif</td><td class="right num">{{ $p['amount'] }}</td></tr>
                    @endforeach
                </table>
            @endif
        </div>
        <div>@include('receipts._totals')</div>
    </div>
    <div class="grid-2" style="margin-top: 14mm;">
        <div class="small">{{ $r['shop']['footer'] ?: __('receipts.thank_you') }}</div>
        <div class="right">
            @if ($r['qr'])<div class="qr">{!! $r['qr'] !!}</div>@endif
            <div style="border-top: 1px solid black; display: inline-block; min-width: 50mm; padding-top: 1mm;">{{ __('receipts.signature') }}</div>
        </div>
    </div>
@endsection
