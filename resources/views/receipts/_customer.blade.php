@if ($r['customer'])
    <div>
        <div><span class="strong">{{ __('receipts.customer') }}:</span> {{ $r['customer']['name'] }}</div>
        @if ($r['customer']['shop_name'])<div>{{ __('receipts.shop') }}: {{ $r['customer']['shop_name'] }}</div>@endif
        @if ($r['customer']['address'])<div>{{ __('receipts.address') }}: {{ $r['customer']['address'] }}</div>@endif
        @if ($r['customer']['customer_no'])<div class="num">{{ __('receipts.customer_no') }}: {{ $r['customer']['customer_no'] }}</div>@endif
        @if ($r['customer']['phone'])<div class="num">{{ __('receipts.phone') }}: {{ $r['customer']['phone'] }}</div>@endif
        @if ($r['customer']['vat_bin'])<div class="num">{{ __('receipts.buyer_bin') }}: {{ $r['customer']['vat_bin'] }}</div>@endif
    </div>
@endif
