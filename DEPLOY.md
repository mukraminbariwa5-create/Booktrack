# Putting BookTrack online

This guide puts BookTrack on the internet as a **public portfolio demo**: anyone can open it, press "Sign in as librarian" or "Sign in as member", and try everything. Demo mode keeps the demo accounts safe and puts the demo data back every day.

Any host with **PHP 8.1+ and MySQL or MariaDB** works. The steps use [InfinityFree](https://www.infinityfree.com) because it's free, needs no card, and gives you PHP 8.3, MySQL, a free subdomain and free HTTPS. On a paid host with cPanel (Hostinger, Namecheap and others) the steps are the same; only the menu names differ.

## 1. Create the hosting account

1. Sign up at InfinityFree and create a hosting account. Pick a free subdomain (or connect your own domain).
2. In the control panel, open **MySQL Databases** and create a database. Write down the four values it shows: **MySQL host**, **database name**, **username** and **password**.
3. Turn on the free **SSL certificate** for your site and wait until it's active, so the site works on `https://`.

## 2. Upload the files

Upload the contents of the BookTrack folder into the site's `htdocs` folder, using the host's **Online File Manager** or an FTP program such as FileZilla (the FTP details are in the control panel).

Leave these out, they're only for your computer:

- `storage/mail/*.eml` (saved test emails)
- `database/*.sqlite`
- `.git` and `.github`
- `uploads/covers/seed-*.jpg`, if you want the demo to match the repository (books then show BookTrack's generated covers)

## 3. Add the server's settings

1. Make a copy of `config.local.example.php` named **`config.local.php`**, in the same folder.
2. Fill it in:
   - `db`: the four values from step 1
   - `app_url`: your site's address, e.g. `https://booktrack-demo.example.com`
   - `debug`: `false`
   - `demo`: `['enabled' => true]`
   - `mail`: see step 5, or set `'driver' => 'off'` for now
3. Upload `config.local.php`. It's listed in `.gitignore`, so the database password never reaches GitHub, and `.htaccess` stops anyone opening it in a browser.

## 4. Create the tables

Open **`https://your-site/setup.php`** and press **Create database**. It fills the database with the demo books, members and loans, then refuses to run again so it can never wipe the library. Any other page opened before this step sends you to `setup.php` automatically.

Then open the site and try both demo buttons.

## 5. Email (password resets and reminders)

Free hosts block PHP's own `mail()`, so BookTrack sends through SMTP. With a Gmail account:

1. Turn on 2-Step Verification for the Google account.
2. Create an **App password** at <https://myaccount.google.com/apppasswords>.
3. In `config.local.php` set `'driver' => 'smtp'`, `'from'` and `'username'` to the Gmail address, and `'password'` to the 16-letter app password (the example file has the full block).

Brevo, Zoho Mail and your host's own mailbox work the same way with their SMTP settings. To test, create your own account on the site (the demo accounts never receive reset emails) and use **Forgot password?**.

## 6. Check it

- `https://your-site/config.php`, `/config.local.php`, `/storage/`, `/database/booktrack.sql` and `/.git/` should all be refused (403 or 404).
- Sign in as the librarian and try to change the demo password on **Profile**: it should say it's a shared demo account.
- The next day, changes made by visitors should be gone.

To send everyone to `https://` even when they type `http://`, add this near the top of `.htaccess` once HTTPS works (some hosts have a switch for it instead):

```apache
RewriteEngine On
RewriteCond %{HTTPS} !=on
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

## Good to know

- **No scheduled tasks on free plans.** That's fine: the daily demo reset and the reminder emails both run on the first visit of each day.
- **Covers.** "Find online" downloads covers from Open Library. If a host blocks outgoing web requests, books keep their generated covers.
- **Uploads are off in demo mode**, so strangers can't publish images or files on your site.
- **Running a real library instead of a demo?** Keep `demo` off, change or delete the demo accounts, turn on two-step sign-in for every librarian, then set `'require_2fa_for_librarians' => true`. See [SECURITY.md](SECURITY.md) for the full checklist.
- **Updating the site later:** upload the changed files again. New tables are created automatically on the next visit; your `config.local.php` stays as it is.
