# BookTrack

A library management system for small libraries, built with plain PHP and MySQL. Members browse the catalogue, borrow and renew books, and message the librarian. Librarians run the whole library from one dashboard: circulation, books, members and messages.

![Member dashboard](docs/screenshots/discover.png)

| Librarian overview | Circulation |
|---|---|
| ![Librarian overview](docs/screenshots/admin-overview.png) | ![Circulation](docs/screenshots/admin-circulation.png) |
| **Book page** | **My loans** |
| ![Book page](docs/screenshots/book-page.png) | ![My loans](docs/screenshots/my-loans.png) |
| **Sign in** | **Mobile** |
| ![Sign in](docs/screenshots/sign-in.png) | ![Mobile](docs/screenshots/mobile.png) |
| **Instant search** | **Day theme** |
| ![Instant search](docs/screenshots/search.png) | ![Day theme](docs/screenshots/discover-light.png) |

## Features

**For members**
- Create an account from the sign-in page and start borrowing right away
- Browse the catalogue with search, categories, sorting and an "available now" filter
- Book pages with description, live availability and when the next copy is due back
- Borrow in one click, renew once for 7 more days, and request a return
- Due-date countdowns that turn gold when a book is due soon and red when it's overdue
- Favorites, reading history, and a Messenger-style chat with the library (live updates, "Seen", Enter to send)
- Read attached e-book PDFs (signed-in users only)
- Instant search from any page (Ctrl+K or `/`): books appear as you type, with arrow-key navigation and page shortcuts
- A reminders bell for books due soon or overdue and new replies from the library
- Undo instead of "are you sure?" for reversible actions (favorites, return requests)
- Night (dark) and Day (light) themes, remembered per device

**For librarians**
- Overview dashboard: key numbers, return requests and overdue loans to action, loans per month, most borrowed books and recent activity
- Circulation: issue books at the desk, check in returns, and filter by on loan, return requests, overdue or returned
- Books: add, edit and delete books, with cover and PDF uploads and multiple copies
- Real covers from [Open Library](https://openlibrary.org): looked up by ISBN or title when a book is added, with "Find online" in the book form and "Find missing covers" for the whole catalogue; books with no cover anywhere get one of five designed jackets
- Instant search also finds members, and the reminders bell lists returns to check in, overdue books and members waiting for a reply
- Members: add, edit, deactivate or delete accounts and reset passwords
- Two-pane chat inbox: every conversation shows the member's name, @username, unread count and loans at a glance; quick replies; start a chat with any member
- An activity log of every change

**Library rules** (in `config.php`)
- Loan period (14 days), maximum books per member (3), and the "due soon" window (3 days)
- Members with an overdue book can't borrow until it's returned
- Availability is calculated from the loans themselves, so it can never drift out of sync

## Tech stack

- PHP 8.1+ (no framework, no Composer dependencies) with PDO and prepared statements
- MySQL / MariaDB (XAMPP)
- Hand-written CSS design system ("Night Shelf": deep navy surfaces, an icon rail, one gold accent from the logo, Bricolage Grotesque + DM Sans), responsive down to phone size
- A little vanilla JavaScript for progressive enhancement. Every page also works without it.

## Security

- Passwords hashed with `password_hash`, and the session ID is regenerated on login and on password change
- Brute-force protection stored in the database: 5 failed sign-ins lock that username for 5 minutes (20 lock the IP address), so clearing cookies doesn't get around it
- Every form is validated on the server too (required fields, formats and lengths that match the database columns)
- CSRF tokens on every form and POST request
- Role checks on every page and endpoint (member vs. librarian)
- All output escaped, and all SQL uses bound parameters
- Uploads are checked by their real content (image headers, the `%PDF-` signature), large covers are resized, and nothing in `uploads/` can run as code
- PDFs are served through `read.php` to signed-in users only
- Cover lookups only ever download from the Open Library covers host, and are checked like uploads
- `.htaccess` rules block direct access to `includes/`, `database/`, `tests/` and the config

## Getting started (XAMPP on Windows)

1. Copy the project folder into `C:\xampp\htdocs\` (for example, `C:\xampp\htdocs\booktrack-v2`).
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open **http://localhost/booktrack-v2/setup.php** and click **Create database**.
   Or import `database/booktrack.sql` in phpMyAdmin.
4. Sign in at **http://localhost/booktrack-v2/**

| Role | Username | Password |
|---|---|---|
| Librarian | `admin` | `admin123` |
| Member | `alice` (also `brian`, `carla`, `diego`, `ella`) | `member123` |

If your MySQL user has a password, or you want a different database name, edit `config.php`.

### Before putting it online

- Set `'debug' => false` in `config.php` so visitors never see error details.
- Use a MySQL user with its own password (not `root`) and put it in `config.php`.
- Change the demo passwords, or remove the demo members from the librarian dashboard.
- `setup.php` refuses to run once the library is installed, so it can't wipe your data.

## Project structure

```
├── index.php, login.php, logout.php, setup.php
├── discover.php, book.php, loans.php, favorites.php, messages.php, profile.php   ← member pages
├── read.php                  ← streams PDFs to signed-in users
├── admin/                    ← librarian pages (overview, loans, books, members, messages, activity)
├── api/favorite.php          ← JSON endpoint for favorite toggling
├── api/chat.php              ← JSON endpoint for sending and receiving chat messages
├── includes/
│   ├── bootstrap.php         ← config, session, security headers
│   ├── db.php                ← PDO connection and query helpers
│   ├── auth.php              ← sign-in, roles, rate limiting
│   ├── library.php           ← circulation rules (borrow, renew, return, availability)
│   ├── helpers.php           ← escaping, CSRF, flash messages, uploads, formatting
│   └── partials/             ← page shell, book card, loan card
├── assets/css/app.css        ← design system
├── assets/js/app.js          ← dialogs, confirmations, favorites, mobile menu
├── database/booktrack.sql    ← schema and demo data
├── uploads/                  ← covers (public) and pdfs (private)
└── tests/                    ← rule tests (run on SQLite)
```

## Tests

The library rules (availability, loan limits, overdue blocking, renewals, returns, favorites, sign-in lockout and seeded logins) are covered by a test script that runs against a throwaway SQLite database. It needs the `pdo_sqlite` extension:

```
php tests/rules_test.php
php tests/chat_migration_test.php   # upgrading an older install carries old messages into chats
```

An end-to-end script signs in as a member, a librarian and a guest and drives every action over HTTP: borrowing limits, renewals, returns and check-in, favorites, messages, book and member management, upload checks, private PDFs, CSRF rejection and the lockout. Run it against a freshly installed copy (it needs Python with `requests`):

```
BOOKTRACK_URL=http://localhost/booktrack-v2 python tests/e2e_test.py
```

## Demo covers

The demo catalogue uses real book titles with short original summaries. Cover artwork isn't included in this repository, so every demo book shows BookTrack's generated typographic cover (seen in the screenshots). Librarians can upload real covers from **Books → Edit**.

## Author & license

Built by **Mukramin Bariwa Ansao**. Released under the [MIT License](LICENSE).
