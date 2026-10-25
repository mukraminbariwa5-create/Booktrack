"""End-to-end HTTP tests for BookTrack.

Run against a FRESHLY INSTALLED copy (it changes data). The site must use the
same database as config.php, because the script checks results with PHP CLI.
"""
import os, re, json, subprocess, pathlib
import requests  # pip install requests
ROOT = pathlib.Path(__file__).resolve().parent.parent  # project folder (reads the DB via config.php)
B = os.environ.get('BOOKTRACK_URL', 'http://127.0.0.1:8100').rstrip('/')
ok = fail = 0
def check(c, m):
    global ok, fail
    if c: ok += 1; print('  ✓', m)
    else: fail += 1; print('  ✗ FAIL:', m)
def q(sql):
    code = "require '%s/includes/bootstrap.php'; echo json_encode(q_all($argv[1]));" % ROOT
    return json.loads(subprocess.check_output(['php', '-r', code, sql]))
def tok(s, path='/login.php'):
    r = s.get(B + path); m = re.search(r'name="_token" value="([^"]+)"', r.text) or re.search(r'name="csrf-token" content="([^"]+)"', r.text)
    return m.group(1) if m else ''
def post(s, path, data=None, files=None, page=None, headers=None):
    d = dict(data or {}); d['_token'] = tok(s, page or path)
    return s.post(B + path, data=d, files=files, headers=headers or {})
def login(u, p):
    s = requests.Session(); r = post(s, '/login.php', {'username': u, 'password': p}); return s, r
def flashes(r): return ' | '.join(re.findall(r'class="alert[^"]*"[^>]*>(?:<[^>]+>)*\s*([^<]{3,})', r.text))
def avail(book_id): return q(f"SELECT copies - (SELECT COUNT(*) FROM loans WHERE book_id={book_id} AND status IN ('borrowed','return_requested')) a FROM books WHERE id={book_id}")[0]['a']

print('1. Sign-in & access control')
s, r = login('alice', 'nope'); check('/login.php' in r.url, 'wrong password rejected')
alice, r = login('alice', 'member123'); check('discover.php' in r.url, 'member signs in → catalogue')
admin, r = login('admin', 'admin123'); check('admin/index.php' in r.url, 'librarian signs in → overview')
r = alice.get(B + '/admin/books.php'); check('admin/books.php' not in r.url, 'member cannot open admin pages')
r = requests.get(B + '/loans.php'); check('login.php' in r.url, 'guest redirected to sign in')
r = requests.post(B + '/api/chat.php', data={'body': 'y'}, cookies=alice.cookies)
check(r.status_code in (419, 302, 303) or 'expired' in r.text, f'POST without CSRF token rejected ({r.status_code})')

print('1b. Sign-up')
new = requests.Session()
r = post(new, '/register.php', {'name': 'Grace Lim', 'username': 'grace', 'email': 'grace@example.com', 'phone': '', 'password': 'password1', 'password_confirm': 'password1'})
check('discover.php' in r.url, 'new member signs up and lands in the catalogue, signed in')
g = q("SELECT role, is_active FROM users WHERE username='grace'")
check(g and g[0]['role'] == 'member' and int(g[0]['is_active']) == 1, 'sign-up creates an active member (never a librarian)')
r = post(requests.Session(), '/register.php', {'name': 'X', 'username': 'grace', 'email': 'grace@example.com', 'password': 'password1', 'password_confirm': 'password1'})
check('That username is taken' in r.text and 'already uses this email' in r.text, 'duplicate username and email are refused, each under its field')
r = post(requests.Session(), '/register.php', {'name': 'Y', 'username': 'yy1', 'email': 'yy@example.com', 'password': 'password1', 'password_confirm': 'different1'})
check("passwords don&#039;t match" in r.text or "passwords don't match" in r.text, 'mismatched passwords refused')
r = post(requests.Session(), '/register.php', {'name': 'Bot', 'username': 'bot1', 'email': 'bot@example.com', 'password': 'password1', 'password_confirm': 'password1', 'website': 'spam.example'})
check(not q("SELECT id FROM users WHERE username='bot1'"), 'bot trap field blocks automated sign-ups')
r = post(requests.Session(), '/register.php', {'name': 'Role', 'username': 'role1', 'email': 'role@example.com', 'password': 'password1', 'password_confirm': 'password1', 'role': 'admin'})
check(q("SELECT role FROM users WHERE username='role1'")[0]['role'] == 'member', 'cannot sign up as a librarian by adding role=admin')

print('2. Borrowing rules')
free = q("SELECT b.id FROM books b WHERE b.copies > (SELECT COUNT(*) FROM loans l WHERE l.book_id=b.id AND l.status IN ('borrowed','return_requested')) AND b.id NOT IN (SELECT book_id FROM loans WHERE user_id=(SELECT id FROM users WHERE username='alice') AND status IN ('borrowed','return_requested')) ORDER BY b.id")
aid = q("SELECT id FROM users WHERE username='alice'")[0]['id']
before = q(f"SELECT COUNT(*) n FROM loans WHERE user_id={aid} AND status IN ('borrowed','return_requested')")[0]['n']
b1 = free[0]['id']; a0 = avail(b1)
post(alice, '/book.php?id=%d' % b1, {'action': 'borrow'})
check(avail(b1) == a0 - 1, f'borrowing reduces availability ({a0} → {avail(b1)})')
r = post(alice, '/book.php?id=%d' % b1, {'action': 'borrow'})
check(q(f"SELECT COUNT(*) n FROM loans WHERE user_id={aid} AND book_id={b1} AND status='borrowed'")[0]['n'] == 1, 'cannot borrow the same book twice')
count = before + 1
for f in free[1:]:
    if count >= 3: break
    post(alice, '/book.php?id=%d' % f['id'], {'action': 'borrow'}); count += 1
extra = [f['id'] for f in free if not q(f"SELECT 1 x FROM loans WHERE user_id={aid} AND book_id={f['id']} AND status IN ('borrowed','return_requested')")][0]
r = post(alice, '/book.php?id=%d' % extra, {'action': 'borrow'})
check(q(f"SELECT COUNT(*) n FROM loans WHERE user_id={aid} AND status IN ('borrowed','return_requested')")[0]['n'] == 3, 'loan limit of 3 enforced')
TODAY = __import__('datetime').date.today().isoformat()  # portable: works on MySQL and the SQLite test copy
over = q(f"SELECT u.username FROM loans l JOIN users u ON u.id=l.user_id WHERE l.status='borrowed' AND l.due_at < '{TODAY}' LIMIT 1")
if over:
    ou = over[0]['username']; os_, _ = login(ou, 'member123'); oid = q(f"SELECT id FROM users WHERE username='{ou}'")[0]['id']
    n0 = q(f"SELECT COUNT(*) n FROM loans WHERE user_id={oid}")[0]['n']
    post(os_, '/book.php?id=%d' % extra, {'action': 'borrow'})
    check(q(f"SELECT COUNT(*) n FROM loans WHERE user_id={oid}")[0]['n'] == n0, f'member with an overdue book ({ou}) is blocked')
    ol = q(f"SELECT id FROM loans WHERE user_id={oid} AND status='borrowed' AND due_at < '{TODAY}' LIMIT 1")[0]['id']
    post(os_, '/loans.php', {'action': 'renew', 'loan_id': ol})
    check(q(f"SELECT renewals FROM loans WHERE id={ol}")[0]['renewals'] == 0, 'overdue loan cannot be renewed')

print('3. Renew, return request, check-in')
lid = q(f"SELECT id, due_at FROM loans WHERE user_id={aid} AND book_id={b1} AND status='borrowed'")[0]
post(alice, '/loans.php', {'action': 'renew', 'loan_id': lid['id']})
check(q(f"SELECT renewals FROM loans WHERE id={lid['id']}")[0]['renewals'] == 1, 'renewed once (+7 days)')
post(alice, '/loans.php', {'action': 'renew', 'loan_id': lid['id']})
check(q(f"SELECT renewals FROM loans WHERE id={lid['id']}")[0]['renewals'] == 1, 'second renewal refused')
other_loan = q(f"SELECT id FROM loans WHERE user_id<>{aid} AND status='borrowed' LIMIT 1")[0]['id']
post(alice, '/loans.php', {'action': 'return', 'loan_id': other_loan})
check(q(f"SELECT status FROM loans WHERE id={other_loan}")[0]['status'] == 'borrowed', "cannot request return of someone else's loan")
post(alice, '/loans.php', {'action': 'return', 'loan_id': lid['id']})
check(q(f"SELECT status FROM loans WHERE id={lid['id']}")[0]['status'] == 'return_requested', 'return requested')
a1 = avail(b1)
post(admin, '/admin/loans.php', {'action': 'return', 'loan_id': lid['id']})
check(q(f"SELECT status FROM loans WHERE id={lid['id']}")[0]['status'] == 'returned' and avail(b1) == a1 + 1, 'librarian check-in puts the copy back')
post(alice, '/admin/loans.php', {'action': 'return', 'loan_id': other_loan})
check(q(f"SELECT status FROM loans WHERE id={other_loan}")[0]['status'] == 'borrowed', 'member cannot use librarian check-in')

print('4. Favorites (JSON API) and messages')
r = alice.post(B + '/api/favorite.php', data={'book_id': b1}, headers={'X-CSRF-Token': tok(alice, '/discover.php'), 'Accept': 'application/json'})
check(r.ok and r.json().get('ok') and r.json().get('favorite') is True, 'favorite toggled on via API')
r = alice.post(B + '/api/favorite.php', data={'book_id': b1}, headers={'Accept': 'application/json'})
check(r.status_code == 419, 'API rejects missing CSRF token')
post(alice, '/api/chat.php', {'body': 'Hello <script>alert(1)</script> Do you have more copies of Atomic Habits?'}, page='/messages.php')
mid = q("SELECT id, member_id FROM chat_messages ORDER BY id DESC LIMIT 1")[0]
check(mid['member_id'] == aid, 'member message lands in their own conversation')
r = admin.get(B + f"/admin/messages.php?member={aid}"); check('<script>alert(1)</script>' not in r.text, 'chat message is escaped (no XSS)')
post(admin, '/api/chat.php', {'member_id': aid, 'body': 'Yes, two more arrive next week.'}, page='/admin/messages.php')
check(q(f"SELECT COUNT(*) n FROM chat_messages WHERE member_id={aid} AND sender='library'")[0]['n'] >= 1, 'librarian replied')
r = alice.get(B + '/messages.php'); check('two more arrive next week' in r.text, 'member sees the reply')
post(alice, '/api/chat.php', {'member_id': 3, 'body': 'not yours'}, page='/messages.php')
check(not q("SELECT id FROM chat_messages WHERE member_id=3 AND body='not yours'"), "member cannot post into someone else's conversation")

print('5. Books admin, uploads, private PDFs')
png = (ROOT / 'assets/img/logo.png').read_bytes()
r = post(admin, '/admin/books.php', {'action': 'save', 'title': 'Test Driven Book', 'author': 'Q. A. Tester', 'category': 'Testing', 'copies': '2', 'published_year': '2024', 'description': 'A book for tests.'},
         files={'cover_file': ('c.png', png, 'image/png'), 'pdf_file': ('b.pdf', b'%PDF-1.4\n%test\n', 'application/pdf')})
bk = q("SELECT id, cover, pdf FROM books WHERE title='Test Driven Book'")
check(bk and bk[0]['cover'] and bk[0]['pdf'], 'book added with cover and PDF')
r = alice.get(B + f'/read.php?id={bk[0]["id"]}'); check(r.headers.get('content-type','').startswith('application/pdf'), 'member can read the PDF')
r = requests.get(B + f'/read.php?id={bk[0]["id"]}'); check('application/pdf' not in r.headers.get('content-type',''), 'guest cannot read the PDF')
r = post(admin, '/admin/books.php', {'action': 'save', 'title': 'Fake', 'author': 'X', 'copies': '1'}, files={'pdf_file': ('evil.pdf', b'<?php echo 1;', 'application/pdf')})
check(not q("SELECT id FROM books WHERE title='Fake'"), 'fake PDF rejected')
onloan = q("SELECT book_id FROM loans WHERE status='borrowed' LIMIT 1")[0]['book_id']
post(admin, '/admin/books.php', {'action': 'delete', 'id': onloan})
check(q(f"SELECT id FROM books WHERE id={onloan}"), 'book on loan cannot be deleted')
r = post(admin, '/admin/books.php', {'action': 'save', 'title': 'T' * 250, 'author': 'Long Title', 'copies': '1'})
check(r.status_code < 500 and 'Fatal' not in r.text and 'Uncaught' not in r.text, f'over-long title handled gracefully ({r.status_code})')

print('6. Members admin')
r = post(admin, '/admin/members.php', {'action': 'save', 'name': 'Test Member', 'username': 'tmember', 'email': 'tm@example.com', 'password': 'password1', 'is_active': '1'})
check(q("SELECT id FROM users WHERE username='tmember'"), 'member created')
r = post(admin, '/admin/members.php', {'action': 'save', 'name': 'Dup', 'username': 'tmember', 'password': 'password1', 'is_active': '1'})
check(len(q("SELECT id FROM users WHERE username='tmember'")) == 1, 'duplicate username refused')
tid = q("SELECT id FROM users WHERE username='tmember'")[0]['id']
post(admin, '/admin/members.php', {'action': 'save', 'id': tid, 'name': 'Test Member', 'username': 'tmember', 'is_active': '0'})
s, r = login('tmember', 'password1'); check('login.php' in r.url, 'deactivated member cannot sign in')
post(admin, '/admin/members.php', {'action': 'delete', 'id': aid})
check(q(f"SELECT id FROM users WHERE id={aid}"), 'member with books on loan cannot be deleted')
r = post(admin, '/admin/members.php', {'action': 'save', 'name': 'N' * 300, 'username': 'longname', 'password': 'password1', 'is_active': '1'})
check(r.status_code < 500 and 'Fatal' not in r.text and 'Uncaught' not in r.text, f'over-long name handled gracefully ({r.status_code})')

print('6b. Holds, reviews, calendar and the desk')
diego, r = login('diego', 'member123'); did = q("SELECT id FROM users WHERE username='diego'")[0]['id']
out = q("SELECT b.id FROM books b WHERE b.copies <= (SELECT COUNT(*) FROM loans l WHERE l.book_id=b.id AND l.status IN ('borrowed','return_requested')) AND b.id NOT IN (SELECT book_id FROM loans WHERE user_id=%d AND status IN ('borrowed','return_requested')) AND b.id NOT IN (SELECT book_id FROM holds WHERE user_id=%d AND status IN ('waiting','ready')) LIMIT 1" % (did, did))
if out:
    hb = out[0]['id']
    r = post(diego, '/book.php?id=%d' % hb, {'action': 'hold'})
    check(q(f"SELECT id FROM holds WHERE user_id={did} AND book_id={hb} AND status='waiting'"), 'member can place a hold on a book that is all out')
    r = diego.get(B + '/loans.php'); check('Holds' in r.text and 'in line' in r.text, 'holds show on My loans')
rv_book = q(f"SELECT book_id FROM loans WHERE user_id={did} AND book_id NOT IN (SELECT book_id FROM reviews WHERE user_id={did}) LIMIT 1")[0]['book_id']
post(diego, '/reviews.php?book=%d' % rv_book, {'action': 'review', 'rating': '4', 'body': 'Solid read.'}, page='/book.php?id=%d' % rv_book)
check(q(f"SELECT id FROM reviews WHERE user_id={did} AND book_id={rv_book} AND rating=4"), 'member can review a book they borrowed')
never = q(f"SELECT id FROM books WHERE id NOT IN (SELECT book_id FROM loans WHERE user_id={did}) LIMIT 1")[0]['id']
post(diego, '/reviews.php?book=%d' % never, {'action': 'review', 'rating': '5'}, page='/book.php?id=%d' % never)
r = diego.get(B + '/reviews.php?book=%d&stars=4' % rv_book); check('Ratings and reviews' in r.text and 'rsum' in r.text, 'all-reviews page shows the summary and filters')
check(not q(f"SELECT id FROM reviews WHERE user_id={did} AND book_id={never}"), 'reviews need a past loan of the book')
dloan = q(f"SELECT id FROM loans WHERE user_id={did} AND status='borrowed' LIMIT 1")
if dloan:
    r = diego.get(B + '/calendar.php?loan=%d' % dloan[0]['id'])
    check(r.headers.get('content-type', '').startswith('text/calendar') and 'BEGIN:VEVENT' in r.text, 'due date downloads as a calendar event')
other = q(f"SELECT id FROM loans WHERE user_id<>{did} AND status='borrowed' LIMIT 1")[0]['id']
check(diego.get(B + '/calendar.php?loan=%d' % other).status_code == 404, "members can't download someone else's loan")
check('BT-%06d' % did in diego.get(B + '/profile.php').text, 'profile shows the library card number')
r = admin.get(B + '/admin/desk.php?card=BT-%06d' % did); check('Diego' in r.text and 'Lend a book' in r.text, 'desk opens a member from their card number')
r = admin.get(B + '/admin/desk.php?card=diego'); check('admin/desk.php?card=BT-%06d' % did in r.url or 'Lend a book' in r.text, 'desk finds a member by name')
check('admin/' in diego.get(B + '/admin/desk.php').url or 'Desk' not in diego.get(B + '/admin/desk.php').text, 'members cannot open the desk')
free = q("SELECT b.id FROM books b WHERE b.copies > (SELECT COUNT(*) FROM loans l WHERE l.book_id=b.id AND l.status IN ('borrowed','return_requested')) + (SELECT COUNT(*) FROM holds h WHERE h.book_id=b.id AND h.status='ready') AND b.id NOT IN (SELECT book_id FROM loans WHERE user_id=%d AND status IN ('borrowed','return_requested')) LIMIT 1" % did)[0]['id']
before = q(f"SELECT COUNT(*) n FROM loans WHERE user_id={did} AND book_id={free} AND status='borrowed'")[0]['n']
post(admin, '/admin/desk.php', {'action': 'lend', 'member_id': did, 'book_id': free, 'override': '1'}, page='/admin/desk.php?card=BT-%06d' % did)
lent = q(f"SELECT id FROM loans WHERE user_id={did} AND book_id={free} AND status='borrowed'")
check(len(lent) == before + 1, 'desk lends a book to the scanned member')
post(admin, '/admin/desk.php', {'action': 'return', 'member_id': did, 'loan_id': lent[0]['id']}, page='/admin/desk.php?card=BT-%06d' % did)
check(q(f"SELECT status FROM loans WHERE id={lent[0]['id']}")[0]['status'] == 'returned', 'desk checks a book in')

print('7. Sign-in lockout')
for i in range(5): s, r = login('carla', 'wrong-%d' % i)
s, r = login('carla', 'member123')
check('login.php' in r.url, 'lockout still applies when the attacker starts a fresh session')
print('8. Exports, password reset and security headers')
import csv, io
r = admin.get(B + '/admin/export.php?type=loans&tab=overdue')
rows = list(csv.reader(io.StringIO(r.content.decode('utf-8-sig')))) if r.ok else []
check(r.headers.get('content-type', '').startswith('text/csv') and rows and rows[0][0] == 'Book', 'librarians can export the overdue list as CSV')
tab_count = re.search(r'Overdue <span class="count">(\d+)</span>', admin.get(B + '/admin/loans.php').text)
check(tab_count and len(rows) - 1 == int(tab_count.group(1)), 'the export matches the Overdue tab')
check(len(admin.get(B + '/admin/export.php?type=members').content.decode('utf-8-sig').strip().splitlines()) - 1 == q("SELECT COUNT(*) n FROM users WHERE role='member'")[0]['n'], 'the member export has every member')
check('text/csv' not in diego.get(B + '/admin/export.php?type=members').headers.get('content-type', ''), "members can't download exports")
anon = requests.Session(); r = post(anon, '/forgot_password.php', {'identifier': 'diego'})
check('Check your email' in r.text, 'forgot password confirms without saying whether the account exists')
r = post(requests.Session(), '/forgot_password.php', {'identifier': 'no-such-user-xyz'})
check('Check your email' in r.text, 'unknown accounts get the same confirmation')
check('expired' in anon.get(B + '/reset_password.php?token=' + 'a' * 64).text, 'a made-up reset link is refused')
h = anon.get(B + '/login.php').headers.get('content-security-policy', '')
check("script-src 'self'" in h and "object-src 'none'" in h and 'frame-ancestors' in h, 'pages send a Content-Security-Policy')

print('9. Late fees')
def php_today():
    return subprocess.check_output(['php', '-r', "require '%s/includes/bootstrap.php'; echo today();" % ROOT]).decode().strip()
today_s = php_today()
late = q(f"SELECT id, due_at FROM loans WHERE status IN ('borrowed','return_requested') AND due_at < '{today_s}' ORDER BY due_at LIMIT 1")
if late:
    r = post(admin, '/admin/loans.php', {'action': 'return', 'loan_id': late[0]['id']}, page='/admin/loans.php')
    fee = q(f"SELECT amount FROM fines WHERE loan_id={late[0]['id']}")
    check(bool(fee) and float(fee[0]['amount']) > 0 and 'fee was added' in r.text, 'checking in a late book charges a fee and says so')
else:
    check(False, 'test data has an overdue loan to check in')
r = admin.get(B + '/admin/loans.php?tab=fines'); check('Fines <span class="count">' in r.text and 'Waive' in r.text, 'circulation has a Fines tab with unpaid fees')
fid = q("SELECT id FROM fines WHERE status='unpaid' ORDER BY id LIMIT 1")[0]['id']
post(diego, '/admin/loans.php', {'action': 'fine_waive', 'fine_id': fid}, page='/loans.php')
check(q(f"SELECT status FROM fines WHERE id={fid}")[0]['status'] == 'unpaid', "members can't waive fees")
post(admin, '/admin/loans.php', {'action': 'fine_paid', 'fine_id': fid}, page='/admin/loans.php?tab=fines')
check(q(f"SELECT status FROM fines WHERE id={fid}")[0]['status'] == 'paid', 'librarians can mark a fee paid')
r = admin.get(B + '/admin/export.php?type=fines'); check(r.headers.get('content-type', '').startswith('text/csv') and 'Days late' in r.content.decode('utf-8-sig'), 'late fees export as CSV')

print('10. Two-step sign-in')
import hmac, hashlib, struct, time as _time, base64, html
def totp(secret, offset=0):
    key = base64.b32decode(secret.replace(' ', '')); step = int(_time.time() // 30) + offset
    h = hmac.new(key, struct.pack('>Q', step), hashlib.sha1).digest(); o = h[19] & 15
    return str((struct.unpack('>I', h[o:o + 4])[0] & 0x7fffffff) % 1000000).zfill(6)
ella, _ = login('ella', 'member123')
post(ella, '/profile.php', {'action': '2fa_start'}, page='/profile.php')
page = ella.get(B + '/profile.php?setup=2fa').text
m = re.search(r'class="twofa__key">([A-Z2-7 ]+)<', page); secret = m.group(1) if m else ''
check(bool(secret) and '<svg class="qr"' in page, 'setup shows a QR code and a setup key')
r = post(ella, '/profile.php', {'action': '2fa_confirm', 'code': totp(secret)}, page='/profile.php?setup=2fa')
codes = re.findall(r'<li><code>([a-z2-7]{4}-[a-z2-7]{4})</code></li>', r.text)
check(len(codes) == 10, 'turning it on shows 10 recovery codes once')
check('<li><code>' not in ella.get(B + '/profile.php').text, 'recovery codes are not shown again')
s2, r = login('ella', 'member123')
check(r.url.endswith('/two_factor.php'), 'the password leads to the code step')
check('login.php' in s2.get(B + '/discover.php').url, 'a half-signed-in session can\'t open pages')
r = s2.get(B + '/api/search.php?q=atomic', headers={'Accept': 'application/json', 'X-Requested-With': 'fetch'}, allow_redirects=False)
check(r.status_code in (401, 403) or (r.status_code == 302 and 'login.php' in r.headers.get('Location', '')), '…or the APIs')
r = post(s2, '/two_factor.php', {'code': '000000' if totp(secret) != '000000' else '111111'})
check('didn' in html.unescape(r.text) and 'more tries' in r.text, 'a wrong code is refused')
_time.sleep(31 - _time.time() % 30)
r = post(s2, '/two_factor.php', {'code': totp(secret)})
check(r.url.endswith('/discover.php'), 'the right code signs in')
s3, r = login('ella', 'member123')
r = post(s3, '/two_factor.php', {'code': totp(secret)})
check(r.url.endswith('/two_factor.php'), 'a code can\'t be used twice')
r = post(s3, '/two_factor.php', {'code': codes[0]})
check(r.url.endswith('/discover.php') and '9 codes left' in r.text, 'a recovery code signs in and says how many are left')
eid = q("SELECT id FROM users WHERE username='ella'")[0]['id']
check('Turn off two-step' in admin.get(B + '/admin/desk.php?card=BT-%06d' % eid).text, 'the desk shows the turn-off button for that member')
post(admin, '/admin/desk.php', {'action': '2fa_off', 'member_id': eid}, page='/admin/desk.php?card=BT-%06d' % eid)
s4, r = login('ella', 'member123')
check(r.url.endswith('/discover.php'), 'a librarian can turn it off at the desk')

print(f'\n{ok} passed, {fail} failed')
raise SystemExit(1 if fail else 0)
