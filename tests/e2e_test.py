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
r = requests.post(B + '/messages.php', data={'subject': 'x', 'body': 'y'}, cookies=alice.cookies)
check(r.status_code in (419, 302, 303) or 'expired' in r.text, f'POST without CSRF token rejected ({r.status_code})')

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
over = q("SELECT u.username FROM loans l JOIN users u ON u.id=l.user_id WHERE l.status='borrowed' AND l.due_at < CURDATE() LIMIT 1")
if over:
    ou = over[0]['username']; os_, _ = login(ou, 'member123'); oid = q(f"SELECT id FROM users WHERE username='{ou}'")[0]['id']
    n0 = q(f"SELECT COUNT(*) n FROM loans WHERE user_id={oid}")[0]['n']
    post(os_, '/book.php?id=%d' % extra, {'action': 'borrow'})
    check(q(f"SELECT COUNT(*) n FROM loans WHERE user_id={oid}")[0]['n'] == n0, f'member with an overdue book ({ou}) is blocked')
    ol = q(f"SELECT id FROM loans WHERE user_id={oid} AND status='borrowed' AND due_at < CURDATE() LIMIT 1")[0]['id']
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
post(alice, '/messages.php', {'subject': 'Hello <script>alert(1)</script>', 'body': 'Do you have more copies of Atomic Habits?'})
mid = q("SELECT id FROM messages ORDER BY id DESC LIMIT 1")[0]['id']
r = admin.get(B + '/admin/messages.php'); check('<script>alert(1)</script>' not in r.text, 'message subject is escaped (no XSS)')
post(admin, '/admin/messages.php', {'action': 'reply', 'id': mid, 'reply': 'Yes, two more arrive next week.'})
check(q(f"SELECT reply FROM messages WHERE id={mid}")[0]['reply'] is not None, 'librarian replied')
r = alice.get(B + '/messages.php'); check('two more arrive next week' in r.text, 'member sees the reply')

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

print('7. Sign-in lockout')
for i in range(5): s, r = login('carla', 'wrong-%d' % i)
s, r = login('carla', 'member123')
check('login.php' in r.url, 'lockout still applies when the attacker starts a fresh session')
print(f'\n{ok} passed, {fail} failed')
