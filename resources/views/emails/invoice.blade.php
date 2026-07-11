{{-- resources/views/emails/invoice.blade.php --}}
<p>Hi {{ $order->customer_name }},</p>
<p>Thanks for your order! Your payment for order <strong>{{ $order->order_id }}</strong> was successful.</p>
<p>Your invoice is attached to this email.</p>
<p>— Flybirds</p>