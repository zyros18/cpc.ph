# Manual GCash Business QR setup

This CPC flow uses your official GCash Business QR and staff verification. It does not connect to the GCash API or verify payments automatically. A submitted payment request is not proof of payment; a teacher must verify that the amount was received in the official merchant account before confirming it in CPC.

Never enter or store a GCash MPIN, OTP, password, or personal account credentials in CPC.

## 1. Import the database tables

1. Start Apache and MySQL in the XAMPP Control Panel.
2. Open `http://localhost/phpmyadmin` and select the `cpc_penalty` database.
3. If `paymongo_checkouts` is not present, import `C:\xampp\htdocs\includes\database\paymongo.sql` first. This legacy table is needed for the migration to safely invalidate old test checkout sessions.
4. Import `C:\xampp\htdocs\includes\database\gcash_manual.sql`.
5. Confirm that `gcash_merchant_settings` appears in the database.

The GCash migration clears any unfinished legacy test checkout sessions and returns their still-pending penalties to unpaid. It does not change paid penalties.

## 2. Add the official merchant QR

1. Sign in to CPC with a teacher account.
2. Open **GCash Business QR** in the teacher dashboard navigation, or visit `http://localhost/includes/gcash_settings.php`.
3. Enter the merchant display name and, optionally, the GCash Business account number.
4. Upload the official GCash Business QR image in JPEG or PNG format (up to 5 MB), then save.

Only upload a QR authorized for CPC collections. The image is stored in the CPC database and displayed to authenticated students on the payment page. CPC cannot create a GCash Business account or generate the merchant QR for you.

## 3. Student payment flow

1. The student opens an unpaid penalty and chooses **Online (QR)**.
2. The student scans the configured QR, pays through GCash, then submits a request for teacher verification in CPC.
3. CPC marks the penalty **Pending**, not paid. The student should keep the GCash receipt.
4. The teacher checks the merchant account directly to verify the amount, recipient, and transaction, then confirms or rejects the request on the teacher dashboard.
5. Confirm only after the payment is visible in the official account. Rejection returns the penalty to unpaid so the student can choose again.

Submitting a request or showing a receipt in CPC does not itself prove that funds were received. This manual workflow does not provide automatic reconciliation, refunds, or GCash API integration.
