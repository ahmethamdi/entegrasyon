@extends('site.en.layout')

@section('description', 'Answers to common questions about 34Pazar: stock sync, Trendyol, orders, plans, billing and uninstalling.')

@section('help_body')
    <h2>What does 34Pazar do?</h2>
    <p>It keeps one stock count for your store and your Trendyol seller account. When an order arrives on either channel, the stock goes down on both, so you don't sell items you no longer have. You can also import your products, send them to Trendyol and see all orders in one list.</p>

    <h2>Do I need a Trendyol seller account?</h2>
    <p>Yes, to sell on Trendyol. You connect it with your own seller ID and API key from the Trendyol seller panel. Without it, 34Pazar still imports your products and tracks your stock and orders.</p>

    <h2>Does the app work inside the Shopify admin?</h2>
    <p>No. 34Pazar opens at 34pazar.com in its own tab. Your store is connected when you install the app; you don't need to enter any address or keys.</p>

    <h2>How fast does stock update?</h2>
    <p>Usually within a minute of an order or a stock change.</p>

    <h2>What happens if the same item sells on two channels at once?</h2>
    <p>Both orders are recorded and the stock can go below zero. The order is marked <strong>Oversold</strong> on the Orders screen and shows up first on your Home screen, so you can restock or contact the buyer. Channels are never sent a negative number; they see zero.</p>

    <h2>An order says "Stock not deducted". Why?</h2>
    <p>The order contains a product 34Pazar doesn't recognise, usually because its SKU doesn't match any product in your catalog. Add or import the product with the same SKU; the stock is deducted once it matches.</p>

    <h2>Trendyol rejected my product. What now?</h2>
    <p>Open <strong>Channel approvals</strong> or the product's <strong>Channels</strong> page to read Trendyol's reason, fix the product and send it again.</p>

    <h2>How much does it cost?</h2>
    <p>There is a free plan (25 products, 1 channel). Paid plans add more products and channels. Charges are added to your Shopify bill and you can change or cancel your plan at any time under <strong>Subscription</strong>.</p>

    <h2>Which languages does the app support?</h2>
    <p>English and Turkish. Switch at the bottom of the left menu.</p>

    <h2>Do you store my customers' personal data?</h2>
    <p>No names, email addresses, phone numbers or addresses are stored. Orders keep only a customer reference number. See the <a href="{{ route('site.legal', 'privacy') }}">Privacy Policy</a>.</p>

    <h2>What happens when I uninstall?</h2>
    <p>The connection to your store is closed and the access token is revoked right away. When Shopify sends its data erasure request (about 48 hours later), customer references from that store are deleted.</p>

    <h2>How do I contact support?</h2>
    <p>Email <a href="mailto:{{ config('site.contact_email') }}">{{ config('site.contact_email') }}</a>. We answer in English or Turkish.</p>
@endsection
