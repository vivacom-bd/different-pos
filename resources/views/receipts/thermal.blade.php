{{-- Sale receipt for 80 mm (72 mm printable) and 58 mm (48 mm) thermal paper. --}}
@extends('receipts.layout')

@section('title', __('receipts.receipt').' '.$r['number'])

@section('content')
    @include('receipts._shop')
    <hr>
    <div class="center title">{{ $r['exchange'] ? __('receipts.exchange_receipt') : __('receipts.receipt') }}</div>
    @if ($r['voided'])
        <div class="void-stamp">{{ __('receipts.voided') }}</div>
        @if ($r['void_reason'])<div class="small">{{ __('receipts.void_reason') }}: {{ $r['void_reason'] }}</div>@endif
    @endif
    <div class="row"><span>{{ __('receipts.invoice_no') }}</span><span class="num strong">{{ $r['number'] }}</span></div>
    <div class="row"><span>{{ __('receipts.date') }}</span><span class="num">{{ $r['date'] }}</span></div>
    @if ($r['courier'])<div class="row"><span>{{ __('receipts.courier') }}</span><span>{{ $r['courier'] }}</span></div>@endif
    @if ($r['cashier'])<div class="row"><span>{{ __('receipts.cashier') }}</span><span>{{ $r['cashier'] }}</span></div>@endif
    @include('receipts._customer')
    <hr>
    <table>
        <thead>
            <tr><th>{{ __('receipts.item') }}</th><th class="right">{{ __('receipts.amount') }}</th></tr>
        </thead>
        <tbody>
        @foreach ($r['lines'] as $line)
            <tr>
                <td colspan="2">{{ $line['name'] }}@if ($paper === '80') <span class="small muted">{{ $line['sku'] }}</span>@endif</td>
            </tr>
            <tr>
                <td class="num small">{{ $line['qty'] }} × {{ $line['unit_price'] }}@if ($line['discount']) − {{ $line['discount'] }}@endif @if ($line['vat_rate'])({{ __('receipts.vat') }} {{ $line['vat_rate'] }})@endif</td>
                <td class="right num">{{ $line['total'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <hr>
    @include('receipts._totals')
    @if ($r['exchange'])
        <div class="small">{{ __('receipts.return_no') }} {{ $r['exchange']['return'] }}: {{ $r['exchange']['credit'] }}</div>
    @endif
    @if ($r['payments'])
        <hr>
        @foreach ($r['payments'] as $p)
            <div class="row small"><span>{{ $p['method'] }}@if ($p['reference']) ({{ $p['reference'] }})@endif</span><span class="num">{{ $p['amount'] }}</span></div>
        @endforeach
    @endif
    <hr>
    <div class="center small">{{ $r['shop']['footer'] ?: __('receipts.thank_you') }}</div>
    <div class="codes">
        {!! $r['barcode'] !!}
        @if ($r['qr'])
            <div class="qr">{!! $r['qr'] !!}</div>
            <div class="small">{{ __('receipts.scan_to_view') }}</div>
        @endif
    </div>
@endsection
