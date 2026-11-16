# Codi Mail Inbound Mail Plan

**Status:** Source implemented; live Microsoft Graph ingress and the full CRM external-reply loop are validated on BHI DEV; production mailbox separation still pending  
**Current plugin version:** 1.2.2

## Current implementation handoff

Codi Mail 1.2.2 is deployed on BHI DEV and Microsoft Graph ingress has been proven with a real mailbox. For CRM direct replies, Codi must poll the same mailbox configured as FluentCRM's global From email; the external Contact must use a different mailbox. DEV is configured for that topology. The CRM-side continuation record is `mwf-plugins/mwf/modules/crm-wp/docs/DIRECT_EMAIL_REPLY_HANDOFF.md`.

Current public contracts are live, not proposals:

- outbound success: `do_action('codi_mail/sent', $result)`;
- inbound normalized message: `do_action('codi_mail_inbound_message', $message)`.

Current Graph behavior:

- app-only client-credentials authentication;
- one durable delta cursor per stable inbound account id;
- initial sync enumerates current Inbox history;
- the cursor advances only after all returned pages/messages have been dispatched successfully;
- HTTP 404 for a message that disappeared between delta and fetch is skipped;
- HTTP 410 clears the cursor and surfaces the failure so a later poll restarts from a fresh delta session;
- changing an account's provider or mailbox also clears that account's cursor and health state;
- transient provider/network errors increment `consecutive_failures`; a successful poll resets it;
- account failures are isolated when cron polls multiple enabled accounts.

Validated on BHI DEV:

- Graph authentication and mailbox access;
- initial catch-up followed by zero duplicate dispatch on an immediate second poll;
- plus-address preservation on a real received message;
- attachment metadata for a small text attachment;
- a transient Microsoft/Outlook timeout recorded as health state without destroying configuration;
- outbound custom-header capture for consumer correlation;
- SES provider message id capture.
- final CRM direct-reply loop with a separate Gmail Contact: SES outbound → external Gmail reply → Graph dispatch → one CRM inbound row → zero duplicate dispatch on immediate subsequent poll → CRM continuation using the authoritative inbound Gmail Message-ID for `In-Reply-To` / `References`; Gmail displayed the three turns as one conversation.

Important SES rule: SES raw send may replace a supplied MIME `Message-ID`. Codi therefore leaves `codi_mail/sent.message_id` blank for SES when the delivered RFC id is not authoritatively available. Consumers must not substitute PHPMailer's pre-send id.

The configured DEV Graph account is the CRM reply-ingress mailbox because it matches FluentCRM's global From email. It must not also be used as the Contact receiving/replying to the test message. Final DEV UAT therefore uses a separate external/test Contact mailbox. Production can later switch FluentCRM's From email and the corresponding Codi mailbox to `crm@bhimembers.co.uk` without adding a separate CRM mailbox configuration surface.

## Purpose

Extend Codi Mail from outbound WordPress transport into a small, reusable mail transport/ingress layer.

Codi Mail should remain application-neutral. It must not know about FluentCRM, SupportCandy, Opportunities, Contacts, tickets or other consuming domain objects.

Its responsibilities should be limited to:

- outbound WordPress mail transport;
- inbound provider connectivity;
- authenticated receipt/fetch of incoming messages;
- MIME/header normalisation;
- generic classification of obvious automated mail;
- provider health/error state;
- dispatch of a stable WordPress inbound-message event for consumers.

Consumers remain responsible for deciding what an inbound message means.

## Reference implementation reviewed

SupportCandy Email Piping 3.4.0 on BHI dev is useful implementation evidence, not a dependency.

The existing JobsAware deployment is also useful operational evidence: `support@jobsaware.co.uk` is backed by a paid Microsoft 365 account because SupportCandy's Exchange adapter consumes a real mailbox through Microsoft Graph. Codi Mail remains provider-neutral, but the first BHI implementation deliberately accepts the mailbox licence because the durable mailbox is useful recovery infrastructure rather than an accidental transport dependency.

The reusable patterns worth retaining are:

- separate IMAP, Gmail and Microsoft Exchange ingestion adapters;
- one normalised inbound-email representation;
- capture of Message-ID, In-Reply-To and References;
- exact provider/message identity for consumer-side idempotency;
- classification/blocking of mailer-daemon, auto-reply and automated messages;
- provider health/error logging;
- forwarding/original-recipient awareness.

The SupportCandy-specific ticket creation, reply creation, customer resolution, ticket subject token parsing and ticket notification behaviour must not be copied into Codi Mail.

Fluent Support also implements email piping, but its hosted Lambda forwarding architecture is product-specific. Codi Mail should preserve the option of directly controlled infrastructure.

## Boundary

### Codi Mail owns

- outbound SMTP/SES transport;
- zero or more independently configured inbound mailbox accounts, each with a stable internal account id and provider configuration;
- inbound transport authentication;
- raw-provider locator where applicable;
- provider message id;
- Internet Message-ID;
- In-Reply-To;
- References;
- envelope/original recipient where available;
- From/To/Cc/Reply-To;
- subject;
- plain-text and HTML body normalisation;
- attachment metadata/provider locator;
- received timestamp;
- generic message classification;
- at-least-once delivery of the normalised message to WordPress consumers;
- ingress health and processing diagnostics.

### Codi Mail does not own

- CRM/ticket/contact lookup;
- business-object association;
- conversation ownership;
- application notification recipients;
- application-side retention policy;
- user-facing inbox/ticket/CRM UI;
- outbound business workflow;
- heuristic association by sender or subject.

## Normalised inbound contract

Expose the documented payload through the stable public action:

`do_action('codi_mail_inbound_message', $message)`

The payload should be application-neutral and include at least:

- `inbound_account_id` as Codi's stable opaque account identity
- `inbound_account_label`
- `mailbox` as the normalized lowercase mailbox address consumers normally filter on
- `provider`
- `provider_message_id`
- `provider_locator` where raw content is retained externally
- `message_id`
- `in_reply_to`
- `references`
- `envelope_to`
- `to`
- `cc`
- `from_name`
- `from_email`
- `reply_to`
- `subject`
- `text_body`
- `html_body`
- `attachments` as metadata/opaque locators
- `received_at`
- `classification`
- a constrained set of raw headers needed for consumers

Do not expose provider SDK/domain objects to consumers.

Consumers may route work simply by the normalized `mailbox` value. The stable account id exists so Codi can keep cursor, health and configuration identity independent from editable mailbox/provider settings.

If an account's provider or mailbox changes while retaining the same stable account id, Codi resets that account's provider cursor and health state. Reusing a delta cursor across different mailboxes/providers is invalid and can otherwise strand or misroute ingestion.

## Delivery and idempotency

Codi Mail delivers normalised inbound messages at least once. It persists the provider's durable incremental cursor, but does not maintain a second message ledger.

Consumers must make processing idempotent using the inbound account identity plus provider message identity, for example:

`inbound_account_id + provider + provider_message_id`

Internet Message-ID remains useful for RFC thread correlation, but must not be the sole replay key because it may be absent or malformed.

This keeps persistence with the application that owns the side effect. If a provider cursor is lost or invalidated and messages are replayed, each consumer rejects duplicates using its own authoritative storage.

## Classification

Provide a small generic classification layer:

- `normal`
- `auto_reply`
- `automated`
- `bounce`

Classification should use standard headers first, including Auto-Submitted and Precedence, then conservative sender/subject rules.

Consumers decide whether each class should be persisted, surfaced or notified.

Existing provider-native bounce/complaint handling remains authoritative where already configured. For BHI, FluentCRM + SES/SNS continues to own marketing/direct-send bounce state; inbound mail parsing must not duplicate that integration.

## Provider architecture

Do not build a broad provider framework before a concrete provider is selected.

Each provider adapter should terminate in the same normalised message contract.

Potential adapters include:

- Amazon SES email receiving;
- IMAP;
- Gmail API;
- Microsoft Graph/Exchange.

Implement only the first provider needed by a real consumer.

## Preferred first provider for BHI

Microsoft 365 / Exchange Online via Microsoft Graph is the preferred first inbound provider.

The live `bhimembers.co.uk` MX already points to Microsoft 365 (`bhimembers-co-uk.mail.protection.outlook.com`), and BHI is willing to provision a dedicated `crm@bhimembers.co.uk` mailbox. This gives the inbound path a durable recovery source: if WordPress, Codi Mail or a consuming application is unavailable, messages remain in the mailbox and can be replayed later.

The preferred configuration is:

`From: Jane at BHI <crm@bhimembers.co.uk>`

`Reply-To: crm+<opaque-token>@bhimembers.co.uk`

Exchange Online plus addressing is receive-only and resolves the tagged address back to the real `crm@bhimembers.co.uk` mailbox. Codi Mail should preserve the original recipient address so consumers can recover the routing token.

Codi Mail should access the mailbox through Microsoft Graph using an application identity suitable for unattended WordPress processing. Request only the permissions required to read/process this mailbox, and scope application access to the CRM mailbox using Exchange Online application RBAC rather than granting effective access to unrelated organisational mailboxes.

Prefer Graph incremental/delta processing or an equivalent durable cursor over repeatedly scanning the whole inbox. The mailbox remains authoritative for raw inbound content and recovery; consuming applications provide replay protection using provider message identity.

Amazon SES receiving remains a future alternative adapter behind the same normalised inbound contract if another deployment has no suitable mailbox or the economics/operational model favour infrastructure-level receiving.

Before relying on plus-address routing, the Microsoft Graph spike must prove exactly where the original tagged recipient (`crm+<token>@bhimembers.co.uk`) is preserved after Exchange Online delivery. Check Graph recipient fields and available transport/internet headers against a real received message. Do not silently fall back to sender/subject matching if Graph normalises the address to `crm@bhimembers.co.uk`; if necessary, add an Exchange-side transport/header-preservation mechanism or choose another deterministic correlation mechanism before CRM integration.

## Outbound observability

Codi Mail exposes a generic successful-send event containing transport facts that applications cannot reliably obtain from `wp_mail()` alone:

`do_action('codi_mail/sent', $result)`

should expose, where available:

- transport/provider;
- MIME Internet Message-ID where authoritatively known after transport;
- provider message id;
- recipient addresses;
- sender;
- timestamp.

Codi Mail receives the SES `MessageId` internally and exposes that provider id rather than forcing consumers to infer delivery identity.

For Amazon SES raw sending, the provider may replace the MIME `Message-ID`. Codi therefore exposes the SES provider message id but leaves the RFC `message_id` blank when the post-provider RFC id is not authoritatively known. Consumers must not treat PHPMailer's pre-transport id as the delivered RFC id.

This event is transport metadata only. It must not contain CRM/ticket semantics.

## Security

- Inbound provider authentication is mandatory.
- Provider secrets use least privilege and are separate from application-domain permissions.
- Treat body/attachments as attacker-controlled input.
- Consumers receive sanitised/normalised content but must still escape/render safely.
- Never use a routing token or Message-ID alone as proof of user identity.
- Do not automatically execute or expose arbitrary attachments.
- Keep raw MIME outside public WordPress uploads where possible.

## Health and operations

Codi Mail settings use separate Outbound and Inbound tabs. The Inbound tab manages any number of mailbox accounts, and each account exposes its own health:

- enabled provider;
- last successful receive/check;
- last provider error;
- consecutive provider failures, reset on the next successful poll;
- last dispatched message timestamp;
- manual connectivity/test action appropriate to the provider.

Do not create a mailbox-style message browser.

A dedicated ingress mailbox is strongly preferred for applications that also send mail using the same business identity. Consumers remain responsible for application-specific loop suppression. Codi must not silently discard provider messages based on application semantics.

## Tests

Use invariant tests rather than fixture-specific regressions.

Core invariants should include:

- provider message identity is preserved for consumer-side replay protection;
- missing Message-ID does not prevent distinct provider messages being distinguished;
- In-Reply-To/References are preserved exactly after normalisation;
- one provider adapter cannot leak provider-specific objects into the consumer contract;
- automated/bounce classification does not create notification loops;
- outbound mail behaviour is unchanged when inbound support is disabled;
- an inbound provider failure cannot break normal WordPress outbound mail.
- changing an inbound account's provider or mailbox resets that account's cursor and health state;
- transient poll failures increment consecutive failure health without disabling configuration, and a later successful poll resets the count;
- SES sent events retain the provider message id while refusing to expose PHPMailer's overwritten pre-transport Message-ID as authoritative.

## Continuation checklist

The Microsoft Graph adapter is implemented. Before changing or extending it:

1. preserve Codi's provider-neutral contract and existing SMTP/SES outbound behaviour;
2. inspect the current adapter and invariant tests before changing cursor, health, header or attachment semantics;
3. use SupportCandy Email Piping only as an implementation reference for mature mailbox-ingress edge cases, not as a source of ticket/application semantics;
4. re-check current Microsoft Graph/Exchange permission and delta contracts when changing authentication or sync behavior;
5. retain the proven plus-address preservation requirement and never replace it with sender/subject heuristics;
6. preserve at-least-once dispatch with consumer-owned idempotency;
7. treat provider-assigned/delivered identifiers truthfully, especially where SES rewrites RFC headers;
8. validate changes with invariant tests and BHI DEV runtime checks without using the same mailbox as both ingress and the external Contact.

Do not add FluentCRM, Opportunity, Contact, ticket or BHI business semantics to Codi Mail. Application-specific loop suppression belongs in the consumer.

## Implementation sequence

1. Define the normalised inbound payload.
2. Define outbound `codi_mail/sent` transport metadata.
3. Implement Microsoft 365 / Microsoft Graph as the first concrete inbound provider after completing the preflight above.
4. Implement provider authentication + incremental retrieval + normalisation.
5. Add provider health/admin controls.
6. Validate with a trivial test consumer before integrating any CRM/helpdesk domain.
7. Only then connect the BHI CRM consumer.
