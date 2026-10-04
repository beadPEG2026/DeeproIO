@component('mail::message')

{{ __('mail.peer.orders.buy.order.created.body.greeting') }}

{{ __('mail.peer.orders.buy.order.appeal-updated.content') }}

<p>{{ $data['result'] }}</p><br>

@if(isset($data['result2']))
<p>{{ $data['result2'] }}</p><br>
@endif

{{ __('mail.peer.orders.buy.order.created.body.footer') }}

<p>{{ __('Order ID:') }} {{ $data['order_id'] }}</p>
<p>{{ __('Order Creation Time:') }} {{ $data['created_at'] }}</p>

@endcomponent
