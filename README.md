# Robes eInvoice

A white-label NRS e-invoice workspace for a company that does not have its own app. One copy of this folder is one company: their logo, their colours, their NRS keys, and their invoices.

It follows the same NRS path already used in Rocad: draft the invoice, look up a TIN, validate, sign, confirm, then open the signed receipt with the MBS authenticity QR.

## Start

Double-click `start.bat`.

The app opens at http://127.0.0.1:8787 and listens only on this computer. Close the black window to stop it.

The first screen creates the company account. The next screen asks whether to connect sandbox or production, then collects that company’s NRS keys, colours, and invoice prefix. The other environment can be added later in Settings.

## Set up a client

1. Copy this whole folder and rename it, for example `acme-einvoice`.
2. If the copy already contains another company's data, delete `storage\robes.sqlite` and anything in `storage\brand`.
3. Double-click `start.bat` in the new folder.
4. In Settings, set:
   - Company legal name, TIN, email, street, city, state code, and LGA code
   - App name, colours, logo, and invoice prefix
   - ISIC code and service category for their trade
   - Sandbox NRS address, API key, API secret, business ID, and service ID
   - The `crypto_keys.txt` file from NRS (Approved Businesses → the business → Get Cryptographic Keys), so receipts can carry the QR
5. Save, then use **Save and test connection**.
6. Tick **Allow validate, sign, confirm, and receipt calls**.
7. Create a sandbox invoice, validate it, then sign it.
8. When the sandbox invoice confirms, fill the production profile and switch the active mode.

State codes look like `NG-LA` or `NG-KN`. LGA codes look like `NG-LA-IKE`. NRS shows them on the taxpayer record. Use **Look up TIN** on the company and on the buyer.

Invoice numbers are letters and numbers only. The IRN is `NUMBER-SERVICEID-YYYYMMDD`. If NRS says the IRN was already used, choose **New invoice number** and validate again.

## What each person can do

- An administrator changes settings, switches sandbox/production, manages the team, and deletes a local invoice.
- An operator creates customers and invoices, then validates, signs, confirms, and prints receipts.
- Deleting an invoice removes it from this workspace only. It does not cancel anything already sent to NRS.

## CSV import

On Invoices, open **Import a CSV**. A blank column list is in `public\samples\invoices-template.csv`. Each row becomes one invoice. Rows that are not ready to send are saved as drafts.

## Files that matter

- `storage\robes.sqlite` — users, customers, invoices, and NRS keys. Do not send this file to anyone who should not see that company.
- `storage\brand` — the logo.
- `start.bat` — how you run it.

To put it on a server, point the site at the `public` folder. The database stays outside that folder.

## Requirements

PHP 8.1 or newer with PDO SQLite, cURL, OpenSSL, and GD. `start.bat` turns on PDO SQLite when the extension is present but not loaded, which is the case for a normal Laragon install.
