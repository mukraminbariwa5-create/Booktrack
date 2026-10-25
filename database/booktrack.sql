-- ============================================================
--  BookTrack — database schema + demo data (MySQL / MariaDB)
--  Import in phpMyAdmin (Import tab) or:
--    mysql -u root < database/booktrack.sql
--  Demo logins:  admin / admin123    alice / member123
-- ============================================================

CREATE DATABASE IF NOT EXISTS `booktrack_v2` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `booktrack_v2`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS two_factor, fines, password_resets, reviews, reminder_log, holds, login_attempts, activity_log, chat_messages, messages, favorites, loans, books, users;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  username      VARCHAR(60)  NOT NULL UNIQUE,
  email         VARCHAR(160) NULL,
  phone         VARCHAR(40)  NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','member') NOT NULL DEFAULT 'member',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE books (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title          VARCHAR(200) NOT NULL,
  author         VARCHAR(160) NOT NULL,
  category       VARCHAR(80)  NULL,
  isbn           VARCHAR(20)  NULL,
  published_year SMALLINT     NULL,
  description    TEXT         NULL,
  cover          VARCHAR(255) NULL,
  pdf            VARCHAR(255) NULL,
  copies         INT UNSIGNED NOT NULL DEFAULT 1,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_books_title (title),
  INDEX idx_books_author (author),
  INDEX idx_books_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE loans (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  book_id     INT UNSIGNED NOT NULL,
  borrowed_at DATE NOT NULL,
  due_at      DATE NOT NULL,
  returned_at DATE NULL,
  renewals    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status      ENUM('borrowed','return_requested','returned') NOT NULL DEFAULT 'borrowed',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_loans_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_loans_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
  INDEX idx_loans_status_due (status, due_at),
  INDEX idx_loans_user_status (user_id, status),
  INDEX idx_loans_book_status (book_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE favorites (
  user_id    INT UNSIGNED NOT NULL,
  book_id    INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, book_id),
  CONSTRAINT fk_fav_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_fav_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Members in line for a book whose copies are all out. A 'ready' hold keeps a
-- returned copy for that member until expires_at (it counts against availability).
CREATE TABLE holds (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  book_id     INT UNSIGNED NOT NULL,
  status      ENUM('waiting','ready','fulfilled','cancelled','expired') NOT NULL DEFAULT 'waiting',
  created_at  DATETIME NOT NULL,
  ready_at    DATETIME NULL,
  expires_at  DATE NULL,
  notified_at DATETIME NULL,
  CONSTRAINT fk_holds_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_holds_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
  INDEX idx_holds_book_status (book_id, status, created_at),
  INDEX idx_holds_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ratings (1–5) and optional short reviews; one per member per book
CREATE TABLE reviews (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  book_id    INT UNSIGNED NOT NULL,
  rating     TINYINT UNSIGNED NOT NULL,
  body       TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_reviews_user_book (user_id, book_id),
  CONSTRAINT fk_reviews_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_reviews_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
  INDEX idx_reviews_book (book_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which reminder emails each loan has had (so none is sent twice)
CREATE TABLE reminder_log (
  loan_id INT UNSIGNED NOT NULL,
  kind    VARCHAR(20) NOT NULL,
  sent_at DATETIME NOT NULL,
  PRIMARY KEY (loan_id, kind),
  CONSTRAINT fk_reminder_loan FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One ongoing conversation per member with the library (Messenger-style).
-- sender: who wrote it; read_at: when the other side opened the conversation.
CREATE TABLE chat_messages (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id      INT UNSIGNED NOT NULL,
  sender         ENUM('member','library') NOT NULL,
  sender_user_id INT UNSIGNED NULL,
  body           TEXT NOT NULL,
  created_at     DATETIME NOT NULL,
  read_at        DATETIME NULL,
  CONSTRAINT fk_chat_member FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_chat_member (member_id, id),
  INDEX idx_chat_unread (sender, read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE activity_log (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NULL,
  actor      VARCHAR(60)  NOT NULL,
  action     VARCHAR(60)  NOT NULL,
  details    VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_activity_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed sign-ins, used to lock out brute-force attempts (rows older than a day are pruned)
CREATE TABLE login_attempts (
  username     VARCHAR(60) NOT NULL,
  ip           VARCHAR(45) NOT NULL,
  attempted_at INTEGER     NOT NULL,
  INDEX idx_login_user (username, attempted_at),
  INDEX idx_login_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Late fees: fixed when a late book is checked in, then paid or waived at the desk.
-- book_title is kept so the record survives if the loan is deleted.
CREATE TABLE fines (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  loan_id     INT UNSIGNED NULL,
  user_id     INT UNSIGNED NOT NULL,
  book_title  VARCHAR(255) NOT NULL,
  days_late   INT UNSIGNED NOT NULL,
  amount      DECIMAL(8,2) NOT NULL,
  status      VARCHAR(10) NOT NULL DEFAULT 'unpaid',
  created_at  DATETIME NOT NULL,
  settled_at  DATETIME NULL,
  settled_by  INT UNSIGNED NULL,
  note        VARCHAR(255) NULL,
  UNIQUE KEY uq_fines_loan (loan_id),
  INDEX idx_fines_user_status (user_id, status),
  INDEX idx_fines_status (status, created_at),
  CONSTRAINT fk_fines_loan FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE SET NULL,
  CONSTRAINT fk_fines_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_fines_settled_by FOREIGN KEY (settled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Two-step sign-in (authenticator app). Recovery codes are stored as SHA-256 hashes.
CREATE TABLE two_factor (
  user_id        INT UNSIGNED PRIMARY KEY,
  secret         VARCHAR(64) NOT NULL,
  last_step      BIGINT NOT NULL DEFAULT 0,
  recovery_codes TEXT NOT NULL,
  enabled_at     DATETIME NOT NULL,
  CONSTRAINT fk_two_factor_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Forgot password" links. Only a SHA-256 hash of each emailed token is kept.
-- Rows with no token count toward the per-connection rate limit.
CREATE TABLE password_resets (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NULL,
  token_hash  CHAR(64) NULL,
  ip          VARCHAR(45) NOT NULL,
  created_at  DATETIME NOT NULL,
  expires_at  DATETIME NULL,
  used_at     DATETIME NULL,
  UNIQUE KEY uq_password_resets_token (token_hash),
  INDEX idx_password_resets_user (user_id, created_at),
  INDEX idx_password_resets_ip (ip, created_at),
  CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  Demo data
-- ------------------------------------------------------------

-- admin / admin123, members: member123
INSERT INTO users (id, name, username, email, phone, password_hash, role, created_at) VALUES
(1, 'Library Admin', 'admin', 'admin@booktrack.local', NULL, '$2y$12$eb/gL3Fs1.a/zHtbc4TcvuyYM57ZLZpQYWPLpr7sgP/aTDb723Ppy', 'admin', DATE_SUB(NOW(), INTERVAL 200 DAY)),
(2, 'Alice Santos', 'alice', 'alice@example.com', '0917 555 0101', '$2y$12$8HEhIA.lu2Xh04/0A9TGiuZE4rCJPcq5YO07/43bXmEvinGqG8RGa', 'member', DATE_SUB(NOW(), INTERVAL 180 DAY)),
(3, 'Brian Cruz', 'brian', 'brian@example.com', '0917 555 0102', '$2y$12$8HEhIA.lu2Xh04/0A9TGiuZE4rCJPcq5YO07/43bXmEvinGqG8RGa', 'member', DATE_SUB(NOW(), INTERVAL 150 DAY)),
(4, 'Carla Reyes', 'carla', 'carla@example.com', '0917 555 0103', '$2y$12$8HEhIA.lu2Xh04/0A9TGiuZE4rCJPcq5YO07/43bXmEvinGqG8RGa', 'member', DATE_SUB(NOW(), INTERVAL 120 DAY)),
(5, 'Diego Navarro', 'diego', 'diego@example.com', NULL, '$2y$12$8HEhIA.lu2Xh04/0A9TGiuZE4rCJPcq5YO07/43bXmEvinGqG8RGa', 'member', DATE_SUB(NOW(), INTERVAL 60 DAY)),
(6, 'Ella Mendoza', 'ella', 'ella@example.com', NULL, '$2y$12$8HEhIA.lu2Xh04/0A9TGiuZE4rCJPcq5YO07/43bXmEvinGqG8RGa', 'member', DATE_SUB(NOW(), INTERVAL 20 DAY));

INSERT INTO books (id, title, author, category, published_year, copies, cover, description, created_at) VALUES
(1, 'The 48 Laws of Power', 'Robert Greene', 'Self-help', 1998, 2, 'uploads/covers/seed-48-laws.jpg', 'A study of power drawn from three thousand years of history, distilled into forty-eight laws with historical examples of people who followed or broke them.', DATE_SUB(NOW(), INTERVAL 190 DAY)),
(2, 'Can''t Hurt Me', 'David Goggins', 'Memoir', 2018, 1, 'uploads/covers/seed-cant-hurt-me.jpg', 'A memoir of turning a difficult childhood into a life of extreme endurance, with challenges for readers to push past their own limits.', DATE_SUB(NOW(), INTERVAL 185 DAY)),
(3, 'How to Talk to Anyone', 'Leil Lowndes', 'Communication', 2003, 1, 'uploads/covers/seed-how-to-talk.jpg', 'Practical techniques for making a strong first impression, starting conversations and building rapport in social and professional settings.', DATE_SUB(NOW(), INTERVAL 180 DAY)),
(4, 'Rich Dad Poor Dad', 'Robert Kiyosaki', 'Finance', 1997, 2, 'uploads/covers/seed-rich-dad.jpg', 'Lessons on money, assets and financial independence, told through the contrasting advice of two father figures.', DATE_SUB(NOW(), INTERVAL 175 DAY)),
(5, 'The Big Questions of Life', 'Various', 'Philosophy', NULL, 1, 'uploads/covers/seed-big-questions.jpg', 'An accessible collection exploring meaning, purpose, knowledge and what it means to live a good life.', DATE_SUB(NOW(), INTERVAL 170 DAY)),
(6, 'The Diary of a CEO', 'Steven Bartlett', 'Business', 2023, 2, 'uploads/covers/seed-diary-ceo.jpg', 'Principles for business and life gathered from the author''s career and hundreds of conversations with high performers.', DATE_SUB(NOW(), INTERVAL 30 DAY)),
(7, 'Atomic Habits', 'James Clear', 'Self-help', 2018, 3, 'uploads/covers/seed-atomic-habits.jpg', 'A practical framework for building good habits and breaking bad ones through small, compounding changes.', DATE_SUB(NOW(), INTERVAL 160 DAY)),
(8, 'Sapiens', 'Yuval Noah Harari', 'History', 2011, 2, 'uploads/covers/seed-sapiens.jpg', 'A sweeping history of humankind, from the first humans to the modern age, told through revolutions in cognition, agriculture and science.', DATE_SUB(NOW(), INTERVAL 150 DAY)),
(9, 'The Psychology of Money', 'Morgan Housel', 'Finance', 2020, 2, 'uploads/covers/seed-psychology-of-money.png', 'Short stories about how people think about money, and why behaviour matters more than knowledge when it comes to wealth.', DATE_SUB(NOW(), INTERVAL 140 DAY)),
(10, 'Deep Work', 'Cal Newport', 'Productivity', 2016, 1, 'uploads/covers/seed-deep-work.jpg', 'An argument for focused, distraction-free work, with rules for training your attention in a noisy world.', DATE_SUB(NOW(), INTERVAL 130 DAY)),
(11, 'Meditations', 'Marcus Aurelius', 'Philosophy', 180, 2, 'uploads/covers/seed-meditations.jpg', 'Private notes of a Roman emperor on duty, self-discipline and calm in the face of what we cannot control.', DATE_SUB(NOW(), INTERVAL 120 DAY)),
(12, 'Educated', 'Tara Westover', 'Memoir', 2018, 1, 'uploads/covers/seed-educated.jpg', 'A memoir of growing up in an isolated family and finding a way, through education, to a very different life.', DATE_SUB(NOW(), INTERVAL 110 DAY)),
(13, 'Thinking, Fast and Slow', 'Daniel Kahneman', 'Psychology', 2011, 1, 'uploads/covers/seed-thinking-fast-slow.jpg', 'A tour of the two systems that drive how we think, and the biases that shape our judgements and choices.', DATE_SUB(NOW(), INTERVAL 100 DAY)),
(14, 'The Alchemist', 'Paulo Coelho', 'Fiction', 1988, 2, 'uploads/covers/seed-alchemist.jpg', 'A shepherd boy travels from Spain to the Egyptian desert in search of treasure and discovers his personal legend.', DATE_SUB(NOW(), INTERVAL 90 DAY)),
(15, 'Noli Me Tángere', 'José Rizal', 'Fiction', 1887, 3, 'uploads/covers/seed-noli-me-tangere.jpg', 'The landmark Filipino novel that exposed the injustices of Spanish colonial rule and helped inspire a nation.', DATE_SUB(NOW(), INTERVAL 80 DAY)),
(16, 'Nineteen Eighty-Four', 'George Orwell', 'Fiction', 1949, 1, 'uploads/covers/seed-nineteen-eighty-four.jpg', 'A dystopian novel of surveillance, propaganda and one man''s quiet rebellion against an all-seeing state.', DATE_SUB(NOW(), INTERVAL 10 DAY));

INSERT INTO loans (user_id, book_id, borrowed_at, due_at, returned_at, renewals, status) VALUES
-- current loans
(2, 1,  DATE_SUB(CURDATE(), INTERVAL 7 DAY),   DATE_ADD(CURDATE(), INTERVAL 7 DAY),  NULL, 0, 'borrowed'),
(2, 3,  DATE_SUB(CURDATE(), INTERVAL 11 DAY),  DATE_ADD(CURDATE(), INTERVAL 3 DAY),  NULL, 0, 'borrowed'),
(3, 4,  DATE_SUB(CURDATE(), INTERVAL 20 DAY),  DATE_SUB(CURDATE(), INTERVAL 6 DAY),  NULL, 0, 'borrowed'),
(4, 8,  DATE_SUB(CURDATE(), INTERVAL 12 DAY),  DATE_ADD(CURDATE(), INTERVAL 2 DAY),  NULL, 0, 'return_requested'),
(5, 7,  DATE_SUB(CURDATE(), INTERVAL 3 DAY),   DATE_ADD(CURDATE(), INTERVAL 11 DAY), NULL, 0, 'borrowed'),
(6, 10, DATE_SUB(CURDATE(), INTERVAL 16 DAY),  DATE_SUB(CURDATE(), INTERVAL 2 DAY),  NULL, 0, 'borrowed'),
-- history
(2, 7,  DATE_SUB(CURDATE(), INTERVAL 150 DAY), DATE_SUB(CURDATE(), INTERVAL 136 DAY), DATE_SUB(CURDATE(), INTERVAL 140 DAY), 0, 'returned'),
(3, 9,  DATE_SUB(CURDATE(), INTERVAL 130 DAY), DATE_SUB(CURDATE(), INTERVAL 116 DAY), DATE_SUB(CURDATE(), INTERVAL 118 DAY), 0, 'returned'),
(4, 11, DATE_SUB(CURDATE(), INTERVAL 125 DAY), DATE_SUB(CURDATE(), INTERVAL 111 DAY), DATE_SUB(CURDATE(), INTERVAL 112 DAY), 0, 'returned'),
(2, 14, DATE_SUB(CURDATE(), INTERVAL 100 DAY), DATE_SUB(CURDATE(), INTERVAL 86 DAY),  DATE_SUB(CURDATE(), INTERVAL 90 DAY), 0, 'returned'),
(5, 2,  DATE_SUB(CURDATE(), INTERVAL 95 DAY),  DATE_SUB(CURDATE(), INTERVAL 81 DAY),  DATE_SUB(CURDATE(), INTERVAL 79 DAY), 0, 'returned'),
(3, 13, DATE_SUB(CURDATE(), INTERVAL 70 DAY),  DATE_SUB(CURDATE(), INTERVAL 56 DAY),  DATE_SUB(CURDATE(), INTERVAL 60 DAY), 0, 'returned'),
(4, 15, DATE_SUB(CURDATE(), INTERVAL 65 DAY),  DATE_SUB(CURDATE(), INTERVAL 51 DAY),  DATE_SUB(CURDATE(), INTERVAL 52 DAY), 0, 'returned'),
(6, 7,  DATE_SUB(CURDATE(), INTERVAL 45 DAY),  DATE_SUB(CURDATE(), INTERVAL 31 DAY),  DATE_SUB(CURDATE(), INTERVAL 35 DAY), 0, 'returned'),
(2, 9,  DATE_SUB(CURDATE(), INTERVAL 40 DAY),  DATE_SUB(CURDATE(), INTERVAL 26 DAY),  DATE_SUB(CURDATE(), INTERVAL 28 DAY), 0, 'returned'),
(5, 16, DATE_SUB(CURDATE(), INTERVAL 30 DAY),  DATE_SUB(CURDATE(), INTERVAL 16 DAY),  DATE_SUB(CURDATE(), INTERVAL 18 DAY), 0, 'returned'),
(3, 6,  DATE_SUB(CURDATE(), INTERVAL 25 DAY),  DATE_SUB(CURDATE(), INTERVAL 11 DAY),  DATE_SUB(CURDATE(), INTERVAL 12 DAY), 1, 'returned'),
(4, 1,  DATE_SUB(CURDATE(), INTERVAL 20 DAY),  DATE_SUB(CURDATE(), INTERVAL 6 DAY),   DATE_SUB(CURDATE(), INTERVAL 8 DAY), 0, 'returned'),
(6, 14, DATE_SUB(CURDATE(), INTERVAL 14 DAY),  DATE_ADD(CURDATE(), INTERVAL 0 DAY),   DATE_SUB(CURDATE(), INTERVAL 4 DAY), 0, 'returned'),
(4, 12, DATE_SUB(CURDATE(), INTERVAL 60 DAY),  DATE_SUB(CURDATE(), INTERVAL 46 DAY),  DATE_SUB(CURDATE(), INTERVAL 38 DAY), 0, 'returned');

-- late fees for the two books that came back late (loan 11 two days late, loan 20 eight days late)
INSERT INTO fines (loan_id, user_id, book_title, days_late, amount, status, created_at, settled_at, settled_by, note) VALUES
(11, 5, 'Can''t Hurt Me', 2, 10.00, 'paid', DATE_SUB(NOW(), INTERVAL 79 DAY), DATE_SUB(NOW(), INTERVAL 79 DAY), 1, NULL),
(20, 4, 'Educated', 8, 40.00, 'unpaid', DATE_SUB(NOW(), INTERVAL 38 DAY), NULL, NULL, NULL);

-- ratings from members who borrowed these books
INSERT INTO reviews (user_id, book_id, rating, body, created_at, updated_at) VALUES
(2, 7,  5, 'Small changes, explained so clearly that I started tracking one habit the same week.', DATE_SUB(NOW(), INTERVAL 138 DAY), DATE_SUB(NOW(), INTERVAL 138 DAY)),
(6, 7,  4, 'Practical and easy to dip back into. A little repetitive near the end.', DATE_SUB(NOW(), INTERVAL 34 DAY), DATE_SUB(NOW(), INTERVAL 34 DAY)),
(3, 9,  5, 'Short chapters, big ideas. Changed how I think about saving.', DATE_SUB(NOW(), INTERVAL 117 DAY), DATE_SUB(NOW(), INTERVAL 117 DAY)),
(2, 9,  4, NULL, DATE_SUB(NOW(), INTERVAL 27 DAY), DATE_SUB(NOW(), INTERVAL 27 DAY)),
(4, 11, 5, 'I read one entry each morning. Calm, and surprisingly modern.', DATE_SUB(NOW(), INTERVAL 110 DAY), DATE_SUB(NOW(), INTERVAL 110 DAY)),
(2, 14, 4, 'A quick, gentle read about following your own path.', DATE_SUB(NOW(), INTERVAL 89 DAY), DATE_SUB(NOW(), INTERVAL 89 DAY)),
(6, 14, 3, 'Nice message, but the story felt thin to me.', DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY)),
(3, 13, 4, 'Dense but rewarding. Take it slowly, a chapter at a time.', DATE_SUB(NOW(), INTERVAL 59 DAY), DATE_SUB(NOW(), INTERVAL 59 DAY)),
(4, 15, 5, 'Worth reading again outside of class. It hits differently.', DATE_SUB(NOW(), INTERVAL 51 DAY), DATE_SUB(NOW(), INTERVAL 51 DAY)),
(5, 16, 5, 'Still chilling. Hard to put down.', DATE_SUB(NOW(), INTERVAL 17 DAY), DATE_SUB(NOW(), INTERVAL 17 DAY)),
(5, 2,  4, 'Intense and motivating. Not for everyone, but it got me running again.', DATE_SUB(NOW(), INTERVAL 78 DAY), DATE_SUB(NOW(), INTERVAL 78 DAY)),
(4, 1,  3, NULL, DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_SUB(NOW(), INTERVAL 7 DAY));

-- members in line for books whose copies are all out
INSERT INTO holds (user_id, book_id, status, created_at) VALUES
(4, 10, 'waiting', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(5, 3,  'waiting', DATE_SUB(NOW(), INTERVAL 1 DAY));

INSERT INTO favorites (user_id, book_id) VALUES (2, 6), (2, 8), (2, 11), (2, 15), (3, 9), (4, 14);

INSERT INTO chat_messages (member_id, sender, sender_user_id, body, created_at, read_at) VALUES
(4, 'member',  4, 'Hi! Do you have any books by José Rizal?', DATE_SUB(NOW(), INTERVAL 30 DAY), DATE_SUB(NOW(), INTERVAL 30 DAY)),
(4, 'library', 1, 'Yes! Noli Me Tángere is in the Fiction section. We have 3 copies, so you can borrow one any time.', DATE_SUB(NOW(), INTERVAL 30 DAY), DATE_SUB(NOW(), INTERVAL 29 DAY)),
(4, 'member',  4, 'Thank you!', DATE_SUB(NOW(), INTERVAL 29 DAY), DATE_SUB(NOW(), INTERVAL 29 DAY)),
(2, 'member',  2, 'Hi! Atomic Habits always seems to be on loan. Could the library get another copy?', DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY)),
(2, 'library', 1, 'Thanks for the suggestion, Alice. We ordered a third copy and it is now on the shelf.', DATE_SUB(NOW(), INTERVAL 2 DAY), NULL),
(3, 'member',  3, 'Just a heads-up: pages 40 to 42 in my copy of Rich Dad Poor Dad are torn. It was like that when I borrowed it.', DATE_SUB(NOW(), INTERVAL 1 DAY), NULL),
(6, 'member',  6, 'Hi! Is it possible to keep Deep Work for one more week?', DATE_SUB(NOW(), INTERVAL 5 HOUR), NULL),
(6, 'member',  6, 'I am nearly done, just need a few more days for my school report.', DATE_SUB(NOW(), INTERVAL 299 MINUTE), NULL);

INSERT INTO activity_log (user_id, actor, action, details, created_at) VALUES
(1, 'admin', 'book.created', '“Nineteen Eighty-Four”', DATE_SUB(NOW(), INTERVAL 10 DAY)),
(5, 'diego', 'loan.created', 'Diego Navarro borrowed “Atomic Habits”', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(1, 'admin', 'message.replied', 'To Alice Santos: Thanks for the suggestion, Alice. We ordered a third copy…', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(4, 'carla', 'loan.return_requested', 'Carla Reyes asked to return “Sapiens”', DATE_SUB(NOW(), INTERVAL 6 HOUR));
