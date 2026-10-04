@component('mail::message')

{{ __('mail.peer.orders.buy.order.appeal-received-admin.content') }}

{{ __('mail.peer.orders.buy.order.created.body.footer') }}

<p>{{ __('Order ID:') }} {{ $data['order_id'] }}</p>
<p>{{ __('Order Creation Time:') }} {{ $data['created_at'] }}</p>

@endcomponent
