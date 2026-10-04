@component('mail::message')

{{ __('mail.peer.orders.buy.order.created.body.greeting') }}

{{ __('mail.peer.orders.buy.order.completed.body.content') }}

{{ __('mail.peer.orders.buy.order.created.body.footer') }}

<p>{{ __('Order ID:') }} {{ $data['order_id'] }}</p>
<p>{{ __('Order Creation Time:') }} {{ $data['created_at'] }}</p>
<p>{{ __('Fiat Amount:') }} {{ $data['fiat_amount'] }}</p>
<p>{{ __('Crypto Amount:') }} {{ $data['crypto_amount'] }}</p>

@endcomponent
