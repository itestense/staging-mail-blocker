# Staging Mail Blocker

Staging Mail Blocker is a single-file WordPress must-use plugin for staging and local development. It intercepts `wp_mail()` calls and gives you a record of the emails WordPress would have sent, so you can check notifications from a staging copy without contacting real users.

## Installation

Requires WordPress 5.7 or later.

1. Create `wp-content/mu-plugins/` in your staging installation if the directory is missing.
2. Copy [`staging-mail-blocker.php`](staging-mail-blocker.php) directly into that directory.
3. Open **Tools → Email bloccate** in the WordPress dashboard. The plugin screen is currently in Italian. In an Italian dashboard, the menu is **Strumenti → Email bloccate**.

WordPress loads the plugin automatically from the top level of the directory. The block takes effect as soon as the file is in place. [WordPress handbook on must-use plugins](https://developer.wordpress.org/advanced-administration/plugins/mu-plugins/)

## Logging options

| Mode in WordPress | What it does |
| --- | --- |
| **Registro in WordPress** | Stores blocked mail in the database for review from the dashboard. This is the default. |
| **Log PHP** | Writes JSON entries prefixed with `[STAGING MAIL]` to the configured PHP error log. The log location depends on your hosting setup. |
| **Registro in WordPress e log PHP** | Records each blocked email in both places. |
| **Nessuna registrazione** | Blocks email without keeping a record. |

Each record includes the timestamp, recipients, and subject. You can opt in to recording the body, headers, and file paths for attachments and embedded files. The dashboard displays the body as text. Long fields are truncated, and attachment contents are excluded.

The database log keeps the newest **100 entries** by default. Set a limit from **1 to 500** under **Numero massimo di voci nel database**. A change takes effect with the next database entry. Use **Svuota il registro di WordPress** to delete existing database entries. PHP log retention follows your server configuration.

Recipients and subjects can contain personal data. Enabling details can also capture password-reset links and other secrets. Existing entries keep their recorded content when you turn details off, and database backups may contain copies.

## Verify on staging

Keep database logging enabled and trigger a notification using a test account. If you have WP-CLI, you can generate a test message from the WordPress root using a reserved `.invalid` address.

```sh
wp eval 'var_export(wp_mail("mail-test@example.invalid", "Staging mail blocker test", "Test message."));'
```

The expected output is `true`. Check **Tools → Email bloccate** for the subject **Staging mail blocker test**. The plugin reports success to the caller after short-circuiting the send, so the return value reflects the interception. [WordPress `pre_wp_mail` hook reference](https://developer.wordpress.org/reference/hooks/pre_wp_mail/)

## Scope and removal

Install the plugin only where outgoing WordPress mail should be blocked. Its scope is the WordPress `wp_mail()` path. Code that sends directly through a separate SMTP client or email API can bypass the WordPress hook. Test your staging setup with the mail integrations you use.

To disable the plugin, remove `staging-mail-blocker.php` from `mu-plugins`. Stored settings and database entries persist after the file is removed. To clear the database entries through the dashboard, use **Svuota il registro di WordPress** before removing the file.

## Author

Paolo Niccolò Giubelli · [iTestense](https://www.itestense.it) · [paoloniccolo.giubelli@itestense.it](mailto:paoloniccolo.giubelli@itestense.it)

## License

Staging Mail Blocker is released under the [MIT License](LICENSE). Include the copyright and permission notices when redistributing copies of the plugin.
