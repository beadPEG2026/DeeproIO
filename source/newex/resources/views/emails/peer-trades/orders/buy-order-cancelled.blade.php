@component('mail::message')

{{ __('mail.peer.orders.buy.order.created.body.greeting') }}

{{ __('mail.peer.orders.buy.order.cancelled.body.content', ['order_id' => $data['order_id']] ) }}

@endcomponent
