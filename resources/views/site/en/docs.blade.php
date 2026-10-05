@extends('site.en.layout')

@section('description', '34Pazar documentation: screens, stock sync rules, channels, plans, data handling and uninstalling.')

@section('help_body')
    <p>New to 34Pazar? Start with <a href="{{ route('site.en', 'getting-started') }}">Getting started</a>. This page describes each screen and how the app behaves.</p>

    <h2>Home</h2>
    <p>Today's orders and sales, and a <strong>To do</strong> list: oversold items, orders waiting to ship, orders with products the app doesn't recognise, and products a channel rejected. Each item links to the screen where you fix it.</p>

    <h2>Products</h2>
    <p>Every product with its price, available stock and status on each channel: <strong>On sale</strong>, <strong>Pending</strong> or <strong>Has issues</strong>. Filters: low stock, out of stock, has issues. Click a stock number to count stock in place; the difference is recorded and sent to your channels.</p>
    <p><strong>Bulk import</strong> brings products in from a connected channel or a CSV file (columns: sku, title, price, stock; optional: description, brand, barcode, category).</p>

    <h2>Orders</h2>
    <p>Orders from all channels in one list, with stock status per order: stock deducted, oversold or not in your catalog. Open an order to see its items and history. For orders from your Shopify store, enter a tracking number and click <strong>Mark as shipped</strong>; the fulfillment is created in Shopify.</p>

    <h2>Channels</h2>
    <p>Your connected channels, their connection health and what each one supports. Your Shopify store is connected when you install the app. If it has several locations, choose the one to sync here. Trendyol is connected with your seller ID and API key.</p>

    <h2>Channel approvals</h2>
    <p>Products waiting for a marketplace's approval, and rejected products with the reason the marketplace gave.</p>

    <h2>Advanced</h2>
    <ul>
        <li><strong>Failed updates:</strong> stock or price updates a channel didn't accept, with the reason and a retry button.</li>
        <li><strong>Price and stock check:</strong> differences between 34Pazar and a channel, with a choice to keep the channel's value or send yours.</li>
        <li><strong>Category mapping:</strong> map your categories and attributes to Trendyol's, required before listing on Trendyol.</li>
    </ul>

    <h2>How stock sync works</h2>
    <ul>
        <li>34Pazar holds one stock count per product. Orders, cancellations, returns and your own counts change it.</li>
        <li>After every change, the new available quantity is sent to each connected channel.</li>
        <li>A negative balance (oversold) is sent to channels as zero.</li>
        <li>Orders placed before a channel was connected are not deducted again; they are already part of the stock you imported.</li>
    </ul>

    <h2>Plans and billing</h2>
    <p>Free: 25 products, 1 channel. Starter, Professional and Business add more products and channels. When you install from Shopify, charges are added to your Shopify bill. Change or cancel under <strong>Subscription</strong>; moving to the free plan cancels the Shopify subscription.</p>

    <h2>Data and privacy</h2>
    <p>Channel credentials are stored encrypted. Customer names, emails, phone numbers and addresses are not stored. Details are in the <a href="{{ route('site.legal', 'privacy') }}">Privacy Policy</a>.</p>

    <h2>Uninstalling</h2>
    <p>Uninstalling the app from Shopify closes the connection immediately. Shopify's data erasure request, about 48 hours later, deletes customer references from that store. To close your 34Pazar account entirely, email <a href="mailto:{{ config('site.contact_email') }}">{{ config('site.contact_email') }}</a>.</p>
@endsection
