<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui';
import { computed, onMounted } from 'vue';
import CodeBlock from '@/components/CodeBlock.vue';
import { dashboard, home, login, register } from '@/routes';

const page = usePage();
const dashboardUrl = computed(() =>
    page.props.currentTeam ? dashboard(page.props.currentTeam.slug).url : '/',
);

/** Contents list. Each entry's id is the anchor on its `<section>`. */
const sections = [
    { id: 'quick-start', title: 'Quick start' },
    { id: 'proxies', title: 'Proxies' },
    { id: 'destinations', title: 'Destinations' },
    { id: 'sending', title: 'Sending webhooks' },
    { id: 'signing', title: 'Signing' },
    { id: 'retries', title: 'Retries and failure' },
    { id: 'events', title: 'Events and replay' },
    { id: 'teams', title: 'Teams' },
    { id: 'account', title: 'Account security' },
    { id: 'troubleshooting', title: 'Troubleshooting' },
];

// Snippets live here rather than in the template so braces are not read as
// Vue interpolation.
const curlExample = `curl -X POST https://your-app.example/ingest/YOUR_INGEST_TOKEN \\
  -H 'Content-Type: application/json' \\
  -d '{"type":"invoice.paid","id":"in_123"}'`;

const signingHeaders = `WebhookProxy-Id: msg_9f1c...-b2e4_17
WebhookProxy-Timestamp: 1756713600
WebhookProxy-Signature: v1,K5m2...base64...=`;

/**
 * Receiver-side verification, one entry per language tab. Same three steps in
 * each: decode the secret, HMAC `id.timestamp.body`, compare in constant time
 * against every entry in the header.
 */
const verifyExamples = [
    {
        value: 'node',
        label: 'Node',
        lang: 'javascript' as const,
        code: `import crypto from 'node:crypto';

// whsec_... exactly as the dialog showed it.
const key = Buffer.from(process.env.PROXY_SIGNING_SECRET.slice(6), 'base64');

// rawBody: the exact bytes received, before JSON parsing.
const signed = \`\${id}.\${timestamp}.\${rawBody}\`;
const expected = 'v1,' + crypto.createHmac('sha256', key)
    .update(signed)
    .digest('base64');

// During a rotation the header carries several entries. Any match is valid.
const ok = signatureHeader.split(' ').some((entry) =>
    entry.length === expected.length &&
    crypto.timingSafeEqual(Buffer.from(entry), Buffer.from(expected)));`,
    },
    {
        value: 'php',
        label: 'PHP',
        lang: 'php' as const,
        code: `<?php

// whsec_... exactly as the dialog showed it.
$key = base64_decode(substr($secret, 6), true);

// $rawBody: the exact bytes received, before json_decode().
$signed = "{$id}.{$timestamp}.{$rawBody}";
$expected = 'v1,'.base64_encode(hash_hmac('sha256', $signed, $key, true));

// During a rotation the header carries several entries. Any match is valid.
$ok = false;

foreach (explode(' ', $signatureHeader) as $entry) {
    if (hash_equals($expected, $entry)) {
        $ok = true;
        break;
    }
}`,
    },
];

// The page mounts after the browser has already acted on the hash, so a deep
// link like /docs#signing would otherwise land at the top.
onMounted(() => {
    const id = window.location.hash.slice(1);

    if (id) {
        document.getElementById(id)?.scrollIntoView();
    }
});

const challengeExample = `{
  "type": "destination_validation",
  "message": "A webhook proxy has been configured to send events to this URL...",
  "validation_url": "https://your-app.example/destinations/17/validate?..."
}`;
</script>

<template>
    <Head title="Documentation" />

    <div class="min-h-screen bg-background text-foreground">
        <header class="mx-auto max-w-6xl px-6 py-6">
            <nav class="flex items-center justify-between gap-4 text-sm">
                <Link :href="home()" class="font-medium">
                    Webhook Proxy Service
                </Link>
                <div class="flex items-center gap-2">
                    <Link
                        v-if="$page.props.auth.user"
                        :href="dashboardUrl"
                        class="rounded-sm border border-transparent px-4 py-1.5 hover:border-border"
                    >
                        Dashboard
                    </Link>
                    <template v-else>
                        <Link
                            :href="login()"
                            class="rounded-sm border border-transparent px-4 py-1.5 hover:border-border"
                        >
                            Log in
                        </Link>
                        <Link
                            :href="register()"
                            class="rounded-sm border border-border px-4 py-1.5 hover:bg-accent"
                        >
                            Register
                        </Link>
                    </template>
                </div>
            </nav>
        </header>

        <div
            class="mx-auto grid max-w-6xl gap-10 px-6 pt-4 pb-20 lg:grid-cols-[14rem_minmax(0,1fr)]"
        >
            <!-- Contents. Sticky beside the text on large screens, a plain
                 list above it on small ones. -->
            <nav aria-label="Contents" class="lg:sticky lg:top-8 lg:self-start">
                <h2
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    Contents
                </h2>
                <ul class="mt-3 space-y-1.5 text-sm">
                    <li v-for="section in sections" :key="section.id">
                        <a
                            :href="`#${section.id}`"
                            class="text-muted-foreground hover:text-foreground"
                        >
                            {{ section.title }}
                        </a>
                    </li>
                </ul>
            </nav>

            <main class="min-w-0 space-y-14">
                <div>
                    <h1 class="text-3xl font-semibold tracking-tight">
                        Documentation
                    </h1>
                    <p class="mt-3 max-w-2xl text-muted-foreground">
                        One webhook in, every destination out. Read straight
                        through for a working setup, or jump to the section you
                        need.
                    </p>
                </div>

                <!-- Quick start -->
                <section id="quick-start" class="scroll-mt-8">
                    <h2 class="text-2xl font-semibold tracking-tight">
                        Quick start
                    </h2>
                    <p class="mt-3 text-muted-foreground">
                        Five minutes, from an empty account to a delivered
                        webhook.
                    </p>

                    <ol class="mt-6 space-y-5">
                        <li>
                            <h3 class="text-sm font-medium">
                                1. Create your account
                            </h3>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Register, then open the verification email and
                                follow its link. Most of the app stays locked
                                until your address is verified.
                            </p>
                        </li>
                        <li>
                            <h3 class="text-sm font-medium">
                                2. Create a proxy
                            </h3>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Proxies &rarr; New proxy. Give it a name. The
                                defaults — Simple mode, Async delivery, a 202
                                acknowledgement — are a working setup; you can
                                change them later.
                            </p>
                        </li>
                        <li>
                            <h3 class="text-sm font-medium">
                                3. Add a destination
                            </h3>
                            <p class="mt-1 text-sm text-muted-foreground">
                                On the same form, add the HTTPS URL that should
                                receive the webhook and pick POST or PUT. Add
                                more rows for more destinations, then save.
                            </p>
                        </li>
                        <li>
                            <h3 class="text-sm font-medium">
                                4. Approve the destination
                            </h3>
                            <p class="mt-1 text-sm text-muted-foreground">
                                On the proxy page, click Validate next to the
                                destination. We send that URL a challenge
                                containing an approval link; whoever runs the
                                endpoint opens it and approves. Until then the
                                destination receives nothing.
                            </p>
                        </li>
                        <li>
                            <h3 class="text-sm font-medium">
                                5. Copy the ingest URL and send something
                            </h3>
                            <p class="mt-1 text-sm text-muted-foreground">
                                The proxy page shows your ingest URL. Point the
                                sending system at it, or try it by hand:
                            </p>
                            <CodeBlock
                                class="mt-3"
                                :code="curlExample"
                                lang="bash"
                                label="Terminal"
                            />
                        </li>
                        <li>
                            <h3 class="text-sm font-medium">
                                6. Watch it arrive
                            </h3>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Open Events on the proxy. You will see the
                                captured event, and a row per destination
                                showing whether delivery succeeded.
                            </p>
                        </li>
                    </ol>
                </section>

                <!-- Proxies -->
                <section id="proxies" class="scroll-mt-8">
                    <h2 class="text-2xl font-semibold tracking-tight">
                        Proxies
                    </h2>
                    <p class="mt-3 text-muted-foreground">
                        A proxy is one ingest URL plus the destinations it fans
                        out to. Create one per sender, or per purpose.
                    </p>

                    <dl class="mt-6 space-y-5 text-sm">
                        <div>
                            <dt class="font-medium">Mode</dt>
                            <dd class="mt-1 text-muted-foreground">
                                Simple delivers and records the outcome.
                                Enhanced also stores the payload that was
                                actually dispatched and lets you configure
                                retries. Switching Enhanced &rarr; Simple stops
                                new payload storage and returns retries to the
                                system default; payloads already stored stay
                                until they expire.
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">Delivery order</dt>
                            <dd class="mt-1 text-muted-foreground">
                                Async delivers in parallel with no order
                                guarantee — the default, and the faster of the
                                two. FIFO delivers one event at a time in the
                                order received, at lower throughput. Pick FIFO
                                only when the receiver cares about order.
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">Ingest response</dt>
                            <dd class="mt-1 text-muted-foreground">
                                What the sender gets back, immediately, before
                                any delivery is attempted. Defaults to 202 with
                                an empty body. Set a status and body when the
                                sender expects a particular acknowledgement or a
                                verification challenge echoed back.
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">Sensitive fields</dt>
                            <dd class="mt-1 text-muted-foreground">
                                Field names masked wherever we show you a
                                payload. A default list (password, token, secret
                                and similar) applies to every proxy; add your
                                own names to it. Matching ignores case and
                                separators, so <code>password</code> also covers
                                <code>Password</code> and
                                <code>pass_word</code>. Masking applies to JSON
                                payloads.
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">Pause</dt>
                            <dd class="mt-1 text-muted-foreground">
                                Pausing stops dispatch while ingest keeps
                                accepting and capturing events. Nothing is lost
                                — resuming releases what queued up. Replay is
                                unavailable while paused.
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">Delete</dt>
                            <dd class="mt-1 text-muted-foreground">
                                Deleting a proxy retires its ingest URL
                                immediately: further posts to that token get a
                                404.
                            </dd>
                        </div>
                    </dl>
                </section>

                <!-- Destinations -->
                <section id="destinations" class="scroll-mt-8">
                    <h2 class="text-2xl font-semibold tracking-tight">
                        Destinations
                    </h2>
                    <p class="mt-3 text-muted-foreground">
                        Every event a proxy accepts goes to every approved
                        destination on it, with the body unchanged.
                    </p>

                    <dl class="mt-6 space-y-5 text-sm">
                        <div>
                            <dt class="font-medium">URL and method</dt>
                            <dd class="mt-1 text-muted-foreground">
                                HTTPS only. POST or PUT, per destination. A URL
                                pointing back at this service's own ingest host,
                                or written as a bare IP address, is rejected on
                                save.
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">Approval</dt>
                            <dd class="mt-1 text-muted-foreground">
                                A new destination starts Unvalidated and is
                                skipped by delivery. Click Validate to send it a
                                challenge — a request to that URL, using the
                                method you configured, with this body:
                            </dd>
                            <CodeBlock
                                class="mt-3"
                                :code="challengeExample"
                                lang="json"
                                label="Challenge body"
                            />
                            <p class="mt-3 text-muted-foreground">
                                The destination is Pending until someone opens
                                <code>validation_url</code> and approves, which
                                turns it Validated and starts delivery. The
                                challenge is good for 7 days; send a fresh one
                                if it lapses. Changing a destination's URL
                                resets its approval — the new address has to be
                                approved on its own.
                            </p>
                        </div>
                        <div>
                            <dt class="font-medium">Credential header</dt>
                            <dd class="mt-1 text-muted-foreground">
                                An optional header name and secret sent verbatim
                                on every dispatch to that one destination — an
                                API key or bearer token the receiver expects.
                                Write-only: we never show it back. Replacing it
                                takes effect on the next dispatch, not on
                                in-flight retries.
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">Removing one</dt>
                            <dd class="mt-1 text-muted-foreground">
                                Remove a destination from the proxy's edit form.
                                Past events keep their delivery history.
                            </dd>
                        </div>
                    </dl>
                </section>

                <!-- Sending webhooks -->
                <section id="sending" class="scroll-mt-8">
                    <h2 class="text-2xl font-semibold tracking-tight">
                        Sending webhooks
                    </h2>
                    <p class="mt-3 text-muted-foreground">
                        Point any sender — Stripe, GitHub, your own service — at
                        the proxy's ingest URL.
                    </p>

                    <dl class="mt-6 space-y-5 text-sm">
                        <div>
                            <dt class="font-medium">The endpoint</dt>
                            <dd class="mt-1 text-muted-foreground">
                                <code>POST</code> or <code>PUT</code> to
                                <code>/ingest/&lt;token&gt;</code>. The token in
                                the URL is the only credential, so treat the
                                ingest URL as a secret. An unknown or deleted
                                token returns 404.
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">Any body</dt>
                            <dd class="mt-1 text-muted-foreground">
                                The body is stored and forwarded as received —
                                JSON, form encoding, XML, anything. We do not
                                reshape it.
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">Headers</dt>
                            <dd class="mt-1 text-muted-foreground">
                                Inbound headers are forwarded to every
                                destination, including the sender's own
                                signature headers, so a receiver can still
                                verify the original sender. Only headers that
                                would break the next hop are dropped:
                                <code>Host</code>, <code>Content-Length</code>,
                                and the hop-by-hop set (<code>Connection</code>,
                                <code>Keep-Alive</code>, <code>TE</code>,
                                <code>Trailer</code>,
                                <code>Transfer-Encoding</code>,
                                <code>Upgrade</code>, and the two
                                <code>Proxy-Auth*</code> headers).
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">The response</dt>
                            <dd class="mt-1 text-muted-foreground">
                                Returned as soon as the event is safely
                                captured, before delivery is attempted — the
                                proxy's configured status and body, 202 by
                                default. A slow or failing destination never
                                slows the sender down.
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">Loop protection</dt>
                            <dd class="mt-1 text-muted-foreground">
                                Deliveries carry a hop counter. A request that
                                has already been round-tripped through the
                                service too many times is rejected rather than
                                captured, so a destination wired back to an
                                ingest URL cannot loop forever.
                            </dd>
                        </div>
                    </dl>
                </section>

                <!-- Signing -->
                <section id="signing" class="scroll-mt-8">
                    <h2 class="text-2xl font-semibold tracking-tight">
                        Signing
                    </h2>
                    <p class="mt-3 text-muted-foreground">
                        Optional, per proxy. When enabled, every dispatch is
                        signed so each destination can prove the request came
                        from your proxy and was not altered. We follow the
                        Standard Webhooks signature format under our own header
                        names.
                    </p>

                    <h3 class="mt-6 text-sm font-medium">Turning it on</h3>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Proxy page &rarr; Signing &rarr; Enable. The secret is
                        shown once, at that moment, and never again. Copy it
                        into every receiver before you close the dialog.
                    </p>

                    <h3 class="mt-6 text-sm font-medium">
                        What the receiver gets
                    </h3>
                    <CodeBlock
                        class="mt-3"
                        :code="signingHeaders"
                        lang="text"
                        label="Request headers"
                    />
                    <p class="mt-3 text-sm text-muted-foreground">
                        The signature is a base64 HMAC-SHA256 over
                        <code>id.timestamp.body</code>, using the secret with
                        its <code>whsec_</code> prefix stripped and the rest
                        base64-decoded. The id is stable across retries of the
                        same delivery and differs per destination. Reject a
                        timestamp more than five minutes from now.
                    </p>
                    <TabsRoot default-value="node" class="mt-3">
                        <TabsList
                            class="flex gap-1 border-b border-border text-sm"
                        >
                            <TabsTrigger
                                v-for="example in verifyExamples"
                                :key="example.value"
                                :value="example.value"
                                class="-mb-px border-b-2 border-transparent px-3 py-1.5 text-muted-foreground data-[state=active]:border-foreground data-[state=active]:text-foreground"
                            >
                                {{ example.label }}
                            </TabsTrigger>
                        </TabsList>
                        <TabsContent
                            v-for="example in verifyExamples"
                            :key="example.value"
                            :value="example.value"
                        >
                            <CodeBlock
                                class="mt-3"
                                :code="example.code"
                                :lang="example.lang"
                                :label="example.label"
                            />
                        </TabsContent>
                    </TabsRoot>

                    <h3 class="mt-6 text-sm font-medium">Rotation</h3>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Regenerating issues a new secret and keeps the previous
                        one working for 24 hours. During that overlap the
                        signature header carries an entry per live secret, so
                        receivers you have not updated yet still verify. Update
                        them, then end the overlap early from the same dialog if
                        you want the old secret dead sooner.
                    </p>
                </section>

                <!-- Retries -->
                <section id="retries" class="scroll-mt-8">
                    <h2 class="text-2xl font-semibold tracking-tight">
                        Retries and failure
                    </h2>
                    <p class="mt-3 text-muted-foreground">
                        Each destination is tracked separately: one failing
                        endpoint never holds up the others.
                    </p>

                    <dl class="mt-6 space-y-5 text-sm">
                        <div>
                            <dt class="font-medium">What counts as failure</dt>
                            <dd class="mt-1 text-muted-foreground">
                                Anything that is not a 2xx: an error status, a
                                timeout, a connection failure, or a redirect. We
                                do not follow redirects — point the destination
                                at its final URL.
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">The schedule</dt>
                            <dd class="mt-1 text-muted-foreground">
                                Simple-mode proxies use the system default of 5
                                attempts. Enhanced-mode proxies set their own
                                limit (up to 10) and pick a backoff strategy:
                                exponential waits longer after each attempt,
                                fixed waits the same interval every time.
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">After the last attempt</dt>
                            <dd class="mt-1 text-muted-foreground">
                                The delivery is marked failed and kept on the
                                record with its response status and error. Fix
                                the receiver, then replay the event.
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">Skipped</dt>
                            <dd class="mt-1 text-muted-foreground">
                                A delivery reads Skipped, not failed, when it
                                was never attempted — an unapproved destination,
                                or one removed before its turn came.
                            </dd>
                        </div>
                    </dl>
                </section>

                <!-- Events and replay -->
                <section id="events" class="scroll-mt-8">
                    <h2 class="text-2xl font-semibold tracking-tight">
                        Events and replay
                    </h2>

                    <dl class="mt-6 space-y-5 text-sm">
                        <div>
                            <dt class="font-medium">Where to look</dt>
                            <dd class="mt-1 text-muted-foreground">
                                Events in the sidebar lists every event across
                                the team, newest first — the quickest way to see
                                a backlog. A proxy's own Events page lists just
                                its events and can be filtered by destination,
                                outcome and time window. The dashboard's charts
                                drill through to the same filtered list.
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">One event</dt>
                            <dd class="mt-1 text-muted-foreground">
                                Open an event to see the captured payload and
                                every delivery attempt: destination, status
                                code, error and timing. Sensitive field values
                                are masked in the payload viewer.
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">Replay</dt>
                            <dd class="mt-1 text-muted-foreground">
                                Replay sends the stored payload again, to the
                                destinations you choose — all of them, or the
                                one that failed. A replay is a new dispatch with
                                its own attempts and its own signature; on a
                                FIFO proxy it joins the back of the queue.
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">Retention</dt>
                            <dd class="mt-1 text-muted-foreground">
                                Payloads are kept 30 days by default. After that
                                the event and its outcome remain visible but the
                                payload is gone, and it can no longer be
                                replayed.
                            </dd>
                        </div>
                    </dl>
                </section>

                <!-- Teams -->
                <section id="teams" class="scroll-mt-8">
                    <h2 class="text-2xl font-semibold tracking-tight">Teams</h2>
                    <p class="mt-3 text-muted-foreground">
                        Everything — proxies, destinations, events — belongs to
                        a team. You get one on registration and can create more;
                        the switcher at the top of the sidebar moves between
                        them. Nothing is ever visible across teams.
                    </p>

                    <dl class="mt-6 space-y-5 text-sm">
                        <div>
                            <dt class="font-medium">Inviting people</dt>
                            <dd class="mt-1 text-muted-foreground">
                                Settings &rarr; Teams &rarr; your team &rarr;
                                Invite. They get an email; accepting adds them
                                with the role you picked.
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">Roles</dt>
                            <dd class="mt-1 text-muted-foreground">
                                Members create and manage their own proxies and
                                can replay events. Admins can additionally edit
                                and delete anyone's proxies, manage invitations
                                and rename the team. The Owner can do
                                everything, including deleting the team.
                            </dd>
                        </div>
                    </dl>
                </section>

                <!-- Account -->
                <section id="account" class="scroll-mt-8">
                    <h2 class="text-2xl font-semibold tracking-tight">
                        Account security
                    </h2>
                    <p class="mt-3 text-muted-foreground">
                        Settings &rarr; Security, after confirming your
                        password.
                    </p>
                    <ul
                        class="mt-6 list-disc space-y-2 pl-5 text-sm text-muted-foreground"
                    >
                        <li>Change your password.</li>
                        <li>
                            Turn on two-factor authentication with an
                            authenticator app, and store the recovery codes
                            somewhere safe — they are the way back in if you
                            lose the device.
                        </li>
                        <li>
                            Register a passkey to sign in with your device's
                            biometrics or PIN instead of a password.
                        </li>
                    </ul>
                </section>

                <!-- Troubleshooting -->
                <section id="troubleshooting" class="scroll-mt-8">
                    <h2 class="text-2xl font-semibold tracking-tight">
                        Troubleshooting
                    </h2>

                    <dl class="mt-6 space-y-5 text-sm">
                        <div>
                            <dt class="font-medium">
                                The event arrived but nothing was delivered
                            </dt>
                            <dd class="mt-1 text-muted-foreground">
                                Check the destination is Validated and the proxy
                                is not paused. Unapproved destinations are
                                skipped.
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">
                                The sender gets a 404 from the ingest URL
                            </dt>
                            <dd class="mt-1 text-muted-foreground">
                                The token is wrong or the proxy was deleted.
                                Copy the URL again from the proxy page.
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">
                                The validation challenge will not send
                            </dt>
                            <dd class="mt-1 text-muted-foreground">
                                The URL has to be reachable over HTTPS, resolve
                                to a public address, and answer without
                                redirecting. Sends are also rate limited — one
                                per destination every five minutes.
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">
                                The receiver rejects our signature
                            </dt>
                            <dd class="mt-1 text-muted-foreground">
                                Verify over the raw request bytes, before any
                                JSON parse or re-serialisation, and decode the
                                secret rather than using the
                                <code>whsec_</code> string directly.
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">
                                Replay is unavailable on an old event
                            </dt>
                            <dd class="mt-1 text-muted-foreground">
                                Its payload passed the retention window and was
                                cleaned, or the proxy is paused.
                            </dd>
                        </div>
                    </dl>
                </section>
            </main>
        </div>
    </div>
</template>

<style scoped>
/* Inline code inside prose. Not worth a component. */
main :not(pre) > code {
    border-radius: var(--radius-sm);
    background-color: var(--muted);
    padding: 0.1rem 0.3rem;
    font-size: 0.85em;
}
</style>
