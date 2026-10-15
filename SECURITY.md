# Security Policy

Sales CRM stores personal data of your customers (names, emails, phone numbers,
notes, documents and email bodies), so we take security seriously and welcome
responsible disclosure.

## Supported versions

Security fixes are provided for the latest `1.x` release line.

| Version | Supported |
|---------|-----------|
| 1.x     | ✅        |
| < 1.0   | ❌        |

## Reporting a vulnerability

**Please do not open public GitHub issues for security vulnerabilities.**

Report privately to **security@selli.io**, or use GitHub's
[private vulnerability reporting](https://github.com/Sellinnate/laravel-sales-crm/security/advisories/new).

Please include:

- a description of the issue and its impact;
- steps to reproduce (a minimal proof of concept if possible);
- affected version(s) and environment.

We aim to acknowledge reports within **3 business days** and to ship a fix or
mitigation as quickly as the severity warrants. We'll credit you in the release
notes unless you prefer to remain anonymous.

## Scope & built-in protections

- **Workspace isolation**: every query of the UI, the API and the MCP tools is
  constrained to the workspaces of the user (`WorkspaceResolver`).
- **Authorization** through gates, policies and the `Authorizer` contract on
  every action, not only in the UI.
- **Signed, rate-limited links** for shared quotes; documents are downloaded
  through an authorized controller, never from a public disk URL.
- **HMAC-signed inbound webhook**, closed until a secret is configured.
- **Spreadsheet exports neutralise formulas** (CSV/XLSX injection).
- **Uploads** are limited by size and extension (`documents.mimes`, `import.max_kilobytes`).

Issues that break any of these guarantees — cross-workspace data leaks,
authorization bypasses, unsigned access to documents, webhook forgery, stored
XSS in the UI — are in scope and high priority.

## Hardening reminders for operators

- Define the `sales-crm.access` and `sales-crm.manage` gates: by default every
  authenticated user can work in the CRM.
- Keep documents and imports on a **private** disk.
- Use a long random `SALES_CRM_INBOUND_SECRET` and HTTPS for the webhook.
- Keep the JSON API and the MCP server behind authentication (`auth:sanctum` by default).
