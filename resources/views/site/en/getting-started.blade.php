@extends('site.en.layout')

@section('description', 'Set up 34Pazar in a few steps: install the app, connect your store, import products and keep stock in sync with Trendyol.')

@section('help_body')
    <p>This guide takes you from installing 34Pazar to your first synced product. It takes about ten minutes.</p>

    <h2>1. Install the app</h2>
    <p>Install 34Pazar from the Shopify App Store. Shopify first shows the permissions the app needs; after you approve, you land on 34Pazar.</p>
    <p>34Pazar is a standalone web app. It opens at 34pazar.com in its own tab, not inside the Shopify admin.</p>

    <h2>2. Create your account</h2>
    <p>Create a 34Pazar account. Your name and store email are filled in for you. If you already have an account, choose <strong>Log in</strong> instead. Either way, your store is connected to the account automatically. You never type a store address or an API key.</p>
    <p>The panel follows your browser language. Use <strong>EN · English</strong> or <strong>TR · Türkçe</strong> at the bottom of the left menu to switch.</p>

    <h2>3. Choose a stock location</h2>
    <p>Open <strong>Channels</strong>. Your store shows as connected. If your store has more than one location, choose the one 34Pazar should keep in sync. Stock is never written to a location you didn't choose.</p>

    <h2>4. Import your products</h2>
    <p>Go to <strong>Products › Bulk import › Import from a channel</strong> and pick your store. Products and their images are imported, and each product is linked to its listing on your store, so nothing is created twice.</p>
    <p>You can also add products by hand (<strong>Add product</strong>) or upload a CSV file.</p>

    <h2>5. Connect Trendyol</h2>
    <p>Go to <strong>Channels › Connect store</strong>, choose <strong>Trendyol</strong> and enter your seller ID and API key from the Trendyol seller panel (Account › Integration information). Connecting a second channel needs the Starter plan or higher.</p>

    <h2>6. Send products to Trendyol</h2>
    <p>Open a product and choose <strong>Channels</strong>. Send it to Trendyol and follow its status: on sale, waiting for Trendyol's approval, or rejected with the reason Trendyol gave.</p>

    <h2>7. Let stock take care of itself</h2>
    <p>From now on there is one stock count for every channel. When an order arrives on any channel, the stock goes down everywhere. To correct a count, click the stock number on the <strong>Products</strong> screen and enter what you have on the shelf.</p>

    <h2>Need help?</h2>
    <p>See the <a href="{{ route('site.en', 'faq') }}">FAQ</a> or write to <a href="mailto:{{ config('site.contact_email') }}">{{ config('site.contact_email') }}</a>.</p>
@endsection
