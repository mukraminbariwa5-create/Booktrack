# Security

This document explains what BookTrack protects, the attacks it is designed to stop, how each defense works, and the limits that remain. Every defense listed here has an automated test (`tests/rules_test.php`, `tests/e2e_test.py`), and the tests run on every push (see `.github/workflows/tests.yml`).

## What needs protecting

| Asset | Why it matters |
|---|---|
| Member accounts | Names, emails, phone numbers and reading history are personal data |
| Librarian accounts | A librarian can edit the catalogue, issue and check in loans, waive fees and manage every member |
| Loan and fee records | They decide who can borrow and who owes money |
| Uploaded files | E-book PDFs are for signed-in members only; covers must never become a way to run code |
| Emails | Reset links in emails are as powerful as a password for 30 minutes |

## Who might attack it

- **Someone on the internet** with no account, trying to guess passwords, inject code or reach private files
- **A signed-in member** trying to do librarian things, see other members' data, or dodge the borrowing rules
- **A malicious website** the user visits while signed in, trying to make the browser act on BookTrack (CSRF, clickjacking)
- **Someone who got a copy of the database**, for example from a leaked backup

## Threats and defenses

### Accounts and sign-in

| Threat | Defense | Where |
|---|---|---|
| Stolen or reused password | Optional two-step sign-in with an authenticator app (TOTP, RFC 6238). After the password, the session is only *pending* until a 6-digit code is entered, within 5 minutes. Codes work once (replay protection) and allow 30 seconds of clock drift. 10 one-time recovery codes are shown once. Librarians can be **required** to use it (`auth.require_2fa_for_librarians`) | `includes/two_factor.php`, `two_factor.php` |
| Password guessing | 5 wrong passwords for one username (or 20 from one IP) in 5 minutes lock sign-in for 5 minutes. The count is in the database, so clearing cookies doesn't reset it. Wrong two-step codes are limited the same way (5, then the password must be entered again) | `includes/auth.php` |
| Cracking a leaked database | Passwords are hashed with `password_hash` (bcrypt) and upgraded automatically when PHP's default gets stronger. Reset tokens and recovery codes are stored only as SHA-256 hashes | `includes/auth.php`, `includes/password_reset.php` |
| Session hijacking | The session cookie is `HttpOnly`, `SameSite=Lax` and `Secure` on HTTPS. The session ID changes at sign-in, at the two-step step and on password change, so a cookie captured before sign-in is useless | `includes/bootstrap.php`, `sign_in_as()` |
| Abusing "Forgot password" | Links carry a random 256-bit token, work once, expire after 30 minutes, and asking again cancels older links. The page answers the same way whether or not an account exists, so it can't be used to find usernames. At most 3 emails per account and 10 requests per connection each hour | `includes/password_reset.php` |
| Fake sign-ups | A hidden "honeypot" field catches bots, and one connection can create at most 5 accounts an hour. Librarians can turn self-registration off (`auth.allow_registration`) | `register.php` |

### Doing what you're not allowed to

| Threat | Defense | Where |
|---|---|---|
| A member opening librarian pages or endpoints | Every page and API checks the role on the server (`require_role`), not just by hiding links. A member's request to a librarian action is redirected or gets `403` | `includes/auth.php` |
| Reading or changing someone else's data | Every action that takes an ID also checks it belongs to the signed-in member (loans, holds, reviews, calendar files, fees) | `includes/library.php`, `calendar.php` |
| Breaking the borrowing rules | Rules live in one place on the server: loan limits, overdue and unpaid-fee blocks, one copy per member, holds. Availability is calculated from the loans themselves inside a transaction, so it can't drift or be double-booked | `includes/library.php`, `includes/fines.php` |
| Avoiding or tampering with fees | Fees are fixed on the server at check-in; members can't settle them. Paying and waiving are recorded with who did it and when, and fee records survive if the loan is deleted | `includes/fines.php` |
| Open redirects after sign-in | The "go back to where you were" link is only followed when it points inside BookTrack | `redirect_after_sign_in()` |

### Attacks through the browser

| Threat | Defense | Where |
|---|---|---|
| Cross-site scripting (XSS) | All output is escaped with `htmlspecialchars` (`e()`). On top of that, a **Content-Security-Policy** only lets scripts load from BookTrack's own files: no inline or injected scripts run even if escaping were ever missed | `includes/helpers.php`, `includes/bootstrap.php` |
| Cross-site request forgery (CSRF) | Every form and every `POST` carries a per-session token, compared in constant time (`hash_equals`). Wrong or missing tokens get `419` | `verify_csrf()` |
| Clickjacking | `X-Frame-Options: SAMEORIGIN` and CSP `frame-ancestors 'self'` stop other sites framing BookTrack | `includes/bootstrap.php` |
| Leaking secrets in links | Reset pages send `Referrer-Policy: no-referrer` and `Cache-Control: no-store`; other pages send `same-origin` | `reset_password.php` |
| Spreadsheet formula injection | CSV exports put an apostrophe before cells that start with `=`, `+`, `-` or `@`, so a book title like `=HYPERLINK(...)` can't run in Excel | `csv_download()` |
| Unwanted browser features | `Permissions-Policy` allows only the camera (for scanning library cards), and only for BookTrack | `includes/bootstrap.php` |

### The server and its files

| Threat | Defense | Where |
|---|---|---|
| SQL injection | Every value that comes from a user goes into SQL as a bound parameter of a PDO prepared statement (emulated prepares are off). The only things written into SQL text are fixed conditions and whole numbers such as page limits | `includes/db.php` |
| Uploading code | Uploads are checked by their real content (image headers, the `%PDF-` signature), not their names, and get random file names. `uploads/` can't run PHP or scripts | `store_cover()`, `uploads/.htaccess` |
| Reading private files | PDFs are served only through `read.php` to signed-in users, with a path check against the PDF folder. `includes/`, `database/`, `storage/`, `tests/`, `tools/` and the config are blocked from the web | `read.php`, `.htaccess` files |
| Making the server fetch other sites (SSRF) | Cover downloads only accept Open Library cover URLs, with size limits and timeouts | `includes/covers.php` |
| Header injection in emails | Line breaks are removed from every email header value | `includes/mailer.php` |
| Covering tracks | An activity log records every change to books, loans, fees, members and security settings, with who made it | `log_activity()` |

## Known limits

These are trade-offs or work for later, written down so nobody assumes otherwise.

- **Two-step secrets are stored as they are.** The server must read them to check codes, so someone with a full copy of the database could generate codes. Encrypting them with a key kept outside the database would close this.
- **Other devices stay signed in after a password change or reset.** Sessions are PHP files, not a list BookTrack can revoke. Sessions end when the browser closes or PHP clears them.
- **Inline styles are allowed** (`style-src 'unsafe-inline'`), because some layouts set styles in the HTML. Scripts are fully locked down; styles alone can't run code.
- **Email addresses aren't verified** at sign-up. Password reset links only go to the address already on the account.
- **Rate limits use the connection's IP address.** Behind a proxy or CDN everyone may share one address; the proxy's real-IP header would need to be trusted first.
- **The lockout can be used to annoy.** Someone can lock a username for 5 minutes by guessing wrong on purpose. Accepted, as it stops password guessing.
- **The `.htaccess` rules only work on Apache** (XAMPP and most shared hosting). On nginx, the same folders must be blocked in the server config.
- **CSV exports change a few values.** Phone numbers starting with `+` get an apostrophe in front. That is the price of the formula protection.

## Before going live

1. Serve the site over **HTTPS** only (the session cookie is then marked `Secure`), and turn on HSTS at the host.
2. In `config.php`: `'debug' => false`, `'show_demo_logins' => false`, a database user with its own strong password (not `root`), and `'app_url'` set to the real address.
3. Change or remove the demo accounts.
4. Turn on two-step sign-in for every librarian, then set `'require_2fa_for_librarians' => true`.
5. Set up real email (SMTP) so password resets and reminders reach people, instead of the `log` driver.
6. Make sure `storage/`, `includes/`, `database/`, `tests/` and `tools/` can't be opened in a browser.
7. Back up the database regularly, and keep the backups as private as the database itself.

## Emergency: a librarian is locked out

If a librarian lost their phone and their recovery codes, someone with access to the server can turn two-step sign-in off for that account:

```
php tools/reset_two_factor.php <username>
```

Members who lose their phone can ask a librarian, who turns it off at **Desk** after checking who they are in person.

## Reporting a vulnerability

Please email **mukraminansao@gmail.com** with what you found and how to reproduce it. Please don't open a public issue for security problems. You'll get a reply within a few days.
