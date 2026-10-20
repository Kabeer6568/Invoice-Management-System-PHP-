# Invoice Management System

A PHP and MySQL application for managing clients, projects, invoices, payments, and reports. The existing red color theme is retained.

## Requirements

- PHP 8.0 or newer with `mysqli` and `mysqlnd` enabled
- MySQL 5.7+ or MariaDB 10.3+
- Apache or another web server configured to serve the project directory
- HTTPS for public deployments

## Local setup (XAMPP)

1. Copy the project into the XAMPP `htdocs` directory.
2. Create and select a MySQL database named `invoice_management_system`, then import `database/schema.sql` using phpMyAdmin or the MySQL client.
3. Start Apache and MySQL. By default, development uses `localhost`, database `invoice_management_system`, user `root`, and an empty password. Set the database environment variables below if your local setup differs.
4. Open the project URL in a browser. The application detects its subdirectory automatically.
5. Create the first administrator as described in [Creating the first administrator](#creating-the-first-administrator).

## Production deployment

1. Upload the application to a PHP web root. Configure PHP 8+, MySQL, and HTTPS.
2. Create a dedicated database and a database user with access only to that database. Select the database in your hosting panel, then import `database/schema.sql`.
3. Set the following variables in the PHP-FPM/Apache hosting environment. Keep credentials out of source control.

   | Variable | Required | Description |
   | --- | --- | --- |
   | `APP_ENV` | Yes | Set to `production` to hide database errors from visitors. |
   | `APP_URL` | Recommended | Canonical application URL, including a subdirectory if applicable. Example: `https://invoices.example.com` or `https://example.com/invoices`. Used for links sent to clients. |
   | `APP_BASE_PATH` | Optional | Override the application path used for assets and navigation, e.g. `/invoices`, when automatic detection is unsuitable. |
   | `DB_HOST` | Yes | MySQL host. |
   | `DB_NAME` | Yes | Database name. |
   | `DB_USER` | Yes | Least-privilege database username. |
   | `DB_PASSWORD` | Yes | Database password. |

4. Create the first administrator using a strong, unique password. Generate a password hash on a trusted machine with PHP CLI:

   ```sh
   php -r 'echo password_hash("replace-with-a-strong-password", PASSWORD_DEFAULT), PHP_EOL;'
   ```

   Insert the generated hash and administrator details into the `admins` table using a database client. Do not store a plain-text password or use a shared/default account.
5. Replace the sample business name, contact details, and demo bank details in the invoice templates with verified business details before sending invoices.
6. Keep generated/private files outside public access. Configure the web server to deny direct access to private storage such as `temp_invoices` if it is used; apply equivalent rules in Nginx where applicable.
7. Verify sign-in, client/project management, invoice creation, payment recording, reports, invoice print pages, and client links on the production URL before entering real customer data.

## Creating the first administrator

The database schema intentionally does not create a default administrator. Generate a password hash as shown above, then run an insert similar to this in a database client, replacing the example values with your own:

```sql
INSERT INTO admins (username, password, email, full_name)
VALUES ('your-admin-name', 'paste-generated-password-hash-here', 'you@example.com', 'Administrator');
```

## Configuration and production notes

- Development database defaults are used only when `APP_ENV` is not `production`. Production requires explicit database environment variables.
- Database connection details are written to the server error log; production visitors receive a generic error.
- Set `APP_URL` to the trusted canonical public URL, especially for WhatsApp invoice links. Do not rely on request host headers for customer-facing URLs.
- Apache must allow the provided `.htaccess` rules to take effect; configure equivalent access restrictions in Nginx.
- Business name, contact information, and bank details in invoice templates are demo placeholders. The account number is not a valid payment destination; replace or remove these details before going live.
- Invoice print pages render HTML for browser printing. Use the browser's print-to-PDF feature; this repository does not include a server-side PDF generation library.
- Back up the database and any generated invoice files regularly, with backups stored outside the public web root.
