-- ============================================================
--  Aram's Tattoo — MySQL schema
--
--  Create the database, then run this file:
--
--    mysql -u root -p -e "CREATE DATABASE arams_tattoo
--      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
--    mysql -u root -p arams_tattoo < database/schema.sql
--    php database/seed.php          # creates your admin login
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 1;


-- ── who can log into the admin panel ────────────────────────
CREATE TABLE IF NOT EXISTS admins (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  name          VARCHAR(120) NOT NULL,
  last_login_at DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_admin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── the portfolio you manage from the admin panel ───────────
CREATE TABLE IF NOT EXISTS designs (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  title        VARCHAR(160) NOT NULL,
  slug         VARCHAR(180) NOT NULL,
  style        VARCHAR(40)  NOT NULL DEFAULT 'Other',
  description  TEXT NULL,
  image_path   VARCHAR(255) NOT NULL,
  price_from   DECIMAL(10,2) NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  sort_order   INT NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_design_slug (slug),
  KEY idx_published (is_published, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── booking requests from the form ──────────────────────────
CREATE TABLE IF NOT EXISTS bookings (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  reference      CHAR(9) NOT NULL,          -- e.g. AT-7K3F9Q, safe to quote to a client
  name           VARCHAR(120) NOT NULL,
  email          VARCHAR(255) NOT NULL,
  phone          VARCHAR(40) NULL,
  preferred_date DATE NULL,
  style          VARCHAR(40) NOT NULL DEFAULT 'Not sure yet',
  placement      VARCHAR(60) NULL,          -- body part picked on the body map
  idea           TEXT NOT NULL,
  status         ENUM('new','confirmed','declined','done') NOT NULL DEFAULT 'new',
  admin_notes    TEXT NULL,
  ip             VARBINARY(16) NULL,        -- inet_pton, works for IPv4 and IPv6
  user_agent     VARCHAR(255) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_reference (reference),
  KEY idx_status_created (status, created_at),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── snapshots taken in the Virtual Camera ───────────────────
-- Uploaded before the booking exists, so booking_id is filled in
-- afterwards when the client submits the form carrying the token.
CREATE TABLE IF NOT EXISTS previews (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  token      CHAR(32) NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  size_cm    DECIMAL(6,2) NULL,             -- if the client calibrated with a card
  booking_id INT NULL,
  ip         VARBINARY(16) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_preview_token (token),
  KEY idx_preview_booking (booking_id),
  KEY idx_preview_created (created_at),
  CONSTRAINT fk_preview_booking FOREIGN KEY (booking_id)
    REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── newsletter / aftercare list ─────────────────────────────
CREATE TABLE IF NOT EXISTS subscribers (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  email           VARCHAR(255) NOT NULL,
  token           CHAR(32) NOT NULL,        -- one-click unsubscribe
  ip              VARBINARY(16) NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unsubscribed_at DATETIME NULL,
  UNIQUE KEY uniq_subscriber_email (email),
  UNIQUE KEY uniq_subscriber_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── login throttling ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  ip           VARBINARY(16) NULL,
  email        VARCHAR(255) NULL,
  succeeded    TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
