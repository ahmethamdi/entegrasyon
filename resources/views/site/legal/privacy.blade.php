{{--
    Gizlilik politikasının İngilizce çevirisi — `gizlilik.blade.php` ile
    BÖLÜM BÖLÜM aynıdır; biri değişince öteki de değişir. Bağlayıcı metin
    Türkçedir (ilk paragraf bunu söyler).

    Shopify App Store incelemesi gizlilik adresi ister ve inceleme
    İngilizcedir; İngilizce panel kullanıcıları da buraya gelir.
--}}
@extends('site.legal.layout')

@section('lang', 'en')
@section('og_locale', 'en_GB')
@section('description', '34Pazar privacy policy: which personal data we process, why, on what legal basis, who we share it with, and your rights.')

@section('legal_body')
    <p>This policy explains how your personal data is processed when you use the {{ config('site.brand') }} service and the 34pazar.com website. It provides the information required by Article 10 of the Turkish Personal Data Protection Law No. 6698 (KVKK) and Article 13 of the EU General Data Protection Regulation (GDPR). This is an English translation of the <a href="{{ route('site.legal', 'gizlilik') }}">Turkish original</a>; if the two differ, the Turkish version prevails.</p>

    <h2>1. Controller</h2>
    <p>
        {{ config('site.operator') }}<br>
        {{ config('site.street') }}, {{ config('site.postal_code') }} {{ config('site.city') }}, {{ config('site.country') }}<br>
        Email: <a href="mailto:{{ config('site.contact_email') }}">{{ config('site.contact_email') }}</a><br>
        Phone: {{ config('site.phone') }}
    </p>
    <p>A data protection officer has not been appointed because this is not legally required. Please use the contact details above for any questions about your personal data.</p>

    <h2>2. What data we process</h2>

    <h3>2.1. When you visit the website</h3>
    <p>To display the website and keep it secure, our server processes technical information with every request: IP address, date and time of the request, the requested address, and browser and operating system information (user agent). Only cookies that are strictly necessary for sessions and security are used; see the <a href="{{ route('site.legal', 'cerez') }}">cookie policy</a> (Turkish). No analytics, advertising or tracking tools are used on the website.</p>

    <h3>2.2. When you create an account</h3>
    <ul>
        <li><strong>Identity and contact:</strong> full name, email address, company or store name.</li>
        <li><strong>Account security:</strong> your password (stored only as an irreversible hash) and session records (IP address, browser information, last activity time).</li>
    </ul>
    <p>No payment information is requested at sign-up.</p>

    <h3>2.3. When you use the service</h3>
    <ul>
        <li><strong>Channel connection details:</strong> API keys and access tokens of the sales channels you connect (for example Shopify, WooCommerce). These are stored <strong>encrypted</strong> in the database and are never written to log files.</li>
        <li><strong>Catalog and stock data:</strong> products, variants, SKUs, stock movements and prices.</li>
        <li><strong>Shopify app:</strong> when you install the app from Shopify, your store domain (xxx.myshopify.com), store name and store email address are collected to create your account and connect your store.</li>
        <li><strong>Order data:</strong> the number, date, status, line items, amounts and shipment tracking information of orders from your channels. The buyer's name, email, phone and address are not kept in the order record; only a reference such as the customer ID provided by the channel is stored. When raw notifications from a channel are stored, these personal fields are masked.</li>
    </ul>

    <h3>2.4. When you upgrade to a paid plan</h3>
    <p>Payment is taken on the checkout page of our payment provider Stripe. Your card details are processed by Stripe; they never reach our servers and are not stored by us. We only store the subscription status, the plan, the Stripe customer and subscription IDs, and billing information.</p>
    <p>If you use {{ config('site.brand') }} as a Shopify app, the subscription fee is added to your Shopify bill and collected by Shopify (Shopify Billing). In that case your payment details are processed only by Shopify; we store the subscription ID, plan and status.</p>

    <h3>2.5. When you contact us</h3>
    <p>When you reach us by email or phone, your name, contact details and the content of your message are processed to answer your request.</p>

    <h2>3. Purposes and legal bases</h2>
    <table>
        <thead>
            <tr><th>Purpose</th><th>KVKK</th><th>GDPR</th></tr>
        </thead>
        <tbody>
            <tr><td>Providing and securing the website, preventing abuse</td><td>Art. 5/2-f (legitimate interest)</td><td>Art. 6(1)(f)</td></tr>
            <tr><td>Creating your account, providing the service, syncing with your channels</td><td>Art. 5/2-c (conclusion and performance of a contract)</td><td>Art. 6(1)(b)</td></tr>
            <tr><td>Subscriptions, payments and invoicing</td><td>Art. 5/2-c and Art. 5/2-ç (legal obligation)</td><td>Art. 6(1)(b) and 6(1)(c)</td></tr>
            <tr><td>Keeping accounting and tax records</td><td>Art. 5/2-ç</td><td>Art. 6(1)(c)</td></tr>
            <tr><td>Answering support requests</td><td>Art. 5/2-c or Art. 5/2-f</td><td>Art. 6(1)(b) or 6(1)(f)</td></tr>
            <tr><td>Establishing, exercising and defending legal claims</td><td>Art. 5/2-e (establishing, exercising or protecting a right)</td><td>Art. 6(1)(f)</td></tr>
        </tbody>
    </table>
    <p>Your personal data is not used for automated decision-making or profiling. We do not send marketing emails; if we ever do, we will ask for your explicit consent first.</p>

    <h2>4. Data processed on behalf of the merchant</h2>
    <p>For order and customer reference data coming from your channels, you, as the merchant making the sale, are the controller. {{ config('site.brand') }} processes this data only on your instructions and only to provide the service, on your behalf (as a processor under KVKK and under Art. 28 GDPR). Informing your buyers through your own privacy notice is your responsibility.</p>

    <h2>5. Who we share data with</h2>
    <p>Your personal data is never sold or shared for advertising. It is shared only with the following parties, as needed to provide the service:</p>
    <ul>
        <li><strong>Hosting — IONOS SE (Germany):</strong> the website and database run on servers in Germany (EU). IONOS acts as a processor under contract.</li>
        <li><strong>Payments — Stripe:</strong> paid subscriptions are charged through Stripe. For users in Europe the contracting party is Stripe Payments Europe, Ltd. (Ireland); data may also be transferred to Stripe, Inc. in the USA. Such transfers are made under appropriate safeguards such as the EU-US Data Privacy Framework and/or the European Commission's standard contractual clauses. Stripe is subject to its own privacy policy for payment services and fraud prevention.</li>
        <li><strong>Email delivery — Google (Google Ireland Ltd.):</strong> service emails such as account verification and password reset are sent via Google Workspace; the recipient's email address and the email content are processed by Google for this purpose. Data may also be transferred to Google LLC in the USA under the EU-US Data Privacy Framework and/or standard contractual clauses.</li>
        <li><strong>Shopify (Shopify International Ltd., Ireland):</strong> when you use the app through Shopify, the subscription fee is collected by Shopify and data is exchanged with your store through Shopify's API.</li>
        <li><strong>Sales channels you connect:</strong> stock, price, product and shipping information is sent via API to the channels you connect on your instructions (for example your Shopify or WooCommerce store or your marketplace account). This transfer is the service itself; the channels' data processing is subject to their own policies.</li>
        <li><strong>Authorities:</strong> only where legally required, to the competent public authorities.</li>
    </ul>

    <h3>Transfers abroad</h3>
    <p>Because the controller is established in Germany and the servers are located in Germany, your personal data is processed in Germany when you use the service from Türkiye. This transfer is made under Art. 9 KVKK, to the extent necessary to conclude and perform the service contract and with the safeguards required by law. The explanation above applies to transfers to Stripe and Google.</p>

    <h2>6. Retention</h2>
    <ul>
        <li><strong>Account, catalog and order data:</strong> as long as your account is open. When you close your account, data without a statutory retention obligation is deleted or anonymised within a reasonable time. You can request account closure by writing to <a href="mailto:{{ config('site.contact_email') }}">{{ config('site.contact_email') }}</a>.</li>
        <li><strong>Channel connection details:</strong> deleted when your account is closed.</li>
        <li><strong>When you uninstall the Shopify app:</strong> your store's access token is revoked and the connection is closed. When Shopify's store data erasure request arrives (about 48 hours after uninstalling), customer references and raw notification content in orders from that store are deleted. Customer data access and erasure requests forwarded by Shopify are answered within 30 days.</li>
        <li><strong>Invoices and payment records:</strong> for the statutory retention periods under tax and commercial law.</li>
        <li><strong>Server and session records:</strong> for a limited time for security purposes; session records expire when your session ends.</li>
        <li><strong>Support correspondence:</strong> for a reasonable time after your request is resolved, so that follow-up questions can be answered.</li>
    </ul>

    <h2>7. Security</h2>
    <p>Connections are encrypted (HTTPS); channel credentials are stored encrypted; passwords are irreversibly hashed; credentials and personal fields are masked in log files. Each account's data is processed separately from other accounts.</p>

    <h2>8. Your rights</h2>
    <p><strong>Under Art. 11 KVKK</strong> you have the right to apply to the controller to:</p>
    <ul>
        <li>learn whether your personal data is processed,</li>
        <li>request information if it has been processed,</li>
        <li>learn the purpose of processing and whether it is used accordingly,</li>
        <li>know the third parties in Türkiye or abroad to whom it is transferred,</li>
        <li>request correction if it is incomplete or inaccurate,</li>
        <li>request deletion or destruction under the conditions of Art. 7 KVKK,</li>
        <li>request that third parties to whom the data was transferred be notified of such correction, deletion or destruction,</li>
        <li>object to a result against you arising from analysis exclusively by automated systems,</li>
        <li>claim compensation if you suffer damage due to unlawful processing.</li>
    </ul>

    <p><strong>Under the GDPR</strong> you have the right of access (Art. 15), rectification (Art. 16), erasure (Art. 17), restriction of processing (Art. 18), data portability (Art. 20) and to object to processing based on legitimate interest (Art. 21). You also have the right to lodge a complaint with a supervisory authority (Art. 77); the authority responsible for the controller is the State Commissioner for Data Protection and Freedom of Information of North Rhine-Westphalia (Landesbeauftragte für Datenschutz und Informationsfreiheit Nordrhein-Westfalen). Your right to complain to the Turkish Personal Data Protection Authority is reserved.</p>

    <h3>How to apply</h3>
    <p>Send your request to <a href="mailto:{{ config('site.contact_email') }}">{{ config('site.contact_email') }}</a> from the email address registered to your account. We may ask for additional information to verify your identity. Requests are handled free of charge within thirty days at the latest; if handling requires additional cost, the fee provided by law may be charged.</p>

    <h2>9. Changes</h2>
    <p>We update this policy when a new feature or service provider is added. The current version is always published on this page; the date at the top shows the latest change. We will notify registered users separately of significant changes.</p>
@endsection
