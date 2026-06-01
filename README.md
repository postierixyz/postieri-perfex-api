# Postieri Perfex API

Custom REST API module for Perfex CRM (CodeIgniter 3).

## Scope (v1)
- Token-based auth (Bearer tokens, scoped per user)
- Customers + Contacts CRUD
- Invoices + PDF generation
- Subscriptions read + status checks
- Leads CRUD + conversion
- Webhooks: invoice.paid, subscription.expiring, lead.converted

## Architecture
- PHP 8.x, CodeIgniter 3 (Perfex core)
- HMVC module under Perfex `modules/postieri_api/`
- SQLite (perfex database) for token storage + webhook logs
- 100 req/min rate limit per token

## Status
🚧 In planning phase — implementation plan pending.

