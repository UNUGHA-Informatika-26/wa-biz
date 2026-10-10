# Manual Meta Cloud API smoke test

This is an operator-run procedure. The application and automated tests never perform a live Meta request or send a WhatsApp message automatically.

## Prerequisites

- A local WABiz backend and an authenticated owner/admin account.
- A Meta system-user access token with the required WhatsApp permissions.
- The WABA ID, phone number ID, and a Graph API version supported by the Meta app.
- A recipient you are authorized to message. Follow WhatsApp's current messaging rules; outside an open customer-service window, use an approved template (this stage's endpoint only sends free-form text).

Set `WHATSAPP_META_GRAPH_API_VERSION` in the backend's local `.env`, then run `php artisan config:clear` if config is cached. Do not commit `.env` or place credentials in source control.

## Save and verify the connection

Using the authenticated local API, create a connection through the existing endpoint. Keep the Meta token in a local API client's secret variable; do not paste it into chat or save it in a checked-in collection:

```http
POST /api/whatsapp/connections
Authorization: Bearer <WABIZ_USER_TOKEN>
Content-Type: application/json

{
  "provider": "meta_cloud",
  "display_name": "Meta WhatsApp",
  "phone_number": "+62...",
  "phone_number_id": "<META_PHONE_NUMBER_ID>",
  "business_account_id": "<META_WABA_ID>",
  "enabled": true,
  "is_primary": true,
  "credentials": { "access_token": "<META_ACCESS_TOKEN>" }
}
```

The backend encrypts `credentials` and does not include it in the response. Verify the connection with:

```http
POST /api/whatsapp/connections/<CONNECTION_ID>/verify
Authorization: Bearer <WABIZ_USER_TOKEN>
```

Verification performs a read-only Graph API request that checks the configured phone number ID under the configured WABA. It does not send a WhatsApp message.

## Send one explicitly authorized text

Only run this final request after checking the recipient and message. It sends a real WhatsApp message. Use a fresh UUID for `Idempotency-Key`; if the request times out or returns an ambiguous error, do not retry with a new key until delivery has been reconciled. Reusing the same key will not resend the message.

```http
POST /api/conversations/<TENANT_CONVERSATION_ID>/messages/send
Authorization: Bearer <WABIZ_USER_TOKEN>
Idempotency-Key: <NEW_UUID>
Content-Type: application/json

{ "body": "<MESSAGE_TO_SEND>" }
```

The endpoint derives the recipient from the conversation's tenant-scoped client; it does not accept a recipient, connection ID, or sender type from the request. A confirmed Meta response ID and delivery status are returned. Pending/unknown deliveries require operator reconciliation and are not automatically resent.
