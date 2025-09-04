# Woo AI Account Manager (Under Development)

This is a custom WordPress + WooCommerce plugin for **automated selling and assignment of AI accounts** (e.g., ChatGPT Plus, Gemini Pro, Super Grok).

## Features
- Manage account inventory in a custom MySQL table.
- Assign accounts automatically after WooCommerce order completion.
- Supports both **Dedicated** (اختصاصی) and **Shared** (اشتراکی) account types.
- Tracks usage via `current_users` and `max_capacity`.
- Displays assigned accounts in the customer’s panel via `[ai_account_assignments]`.
- Sends email with login details after purchase.
- Admin panel for adding, editing, and reporting account inventory.

## Database Tables
- `wp_inventory_accounts` – holds all available accounts.
- `wp_customer_assignments` – stores which account is assigned to which customer.

## CSV Import
You can bulk import accounts into MySQL using phpMyAdmin or CLI.

### Example SQL Import
```sql
LOAD DATA LOCAL INFILE '/path/to/accounts.csv'
INTO TABLE wp_inventory_accounts
FIELDS TERMINATED BY ',' 
ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(status, current_users, max_capacity, password_ref, account_email, plan_type, duration, product_type, product_id, account_id);





#########################################################
Usage

Create WooCommerce products with IDs that match your account CSV.

Place a test order and mark it Processing/Completed.

The plugin:

Finds the correct account.

Assigns it to the customer.

Sends an email with login details.

Shows the info in [ai_account_assignments].

Development Status

This project is work in progress.
Next steps:

Extend admin UI.

Improve error handling.

Add cron for account expiry (end_date).
