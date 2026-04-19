-- ============================================================
--  GRIP Gear Tracker  —  MySQL Schema  (v2 — multi-user)
-- ============================================================
CREATE DATABASE IF NOT EXISTS grip CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE grip;

CREATE TABLE IF NOT EXISTS users (
  id         VARCHAR(20)  PRIMARY KEY,
  username   VARCHAR(80)  NOT NULL UNIQUE,
  pw_hash    VARCHAR(64)  NOT NULL,
  role       VARCHAR(20)  NOT NULL DEFAULT 'viewer',
  first_name VARCHAR(100) DEFAULT '',
  last_name  VARCHAR(100) DEFAULT '',
  email      VARCHAR(255) DEFAULT '',
  phone      VARCHAR(80)  DEFAULT '',
  org        VARCHAR(255) DEFAULT '',
  bio        TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS borrow_requests (
  id            VARCHAR(20)  PRIMARY KEY,
  user_id       VARCHAR(20)  NOT NULL,
  start_date    DATE         NOT NULL,
  end_date      DATE         NOT NULL,
  items         JSON         NOT NULL,
  contact_name  VARCHAR(255) DEFAULT '',
  contact_email VARCHAR(255) DEFAULT '',
  contact_phone VARCHAR(80)  DEFAULT '',
  contact_org   VARCHAR(255) DEFAULT '',
  notes         TEXT         DEFAULT '',
  budget        DECIMAL(10,2) DEFAULT 0.00,
  status        VARCHAR(20)  NOT NULL DEFAULT 'pending',
  reason        TEXT         DEFAULT '',
  resolved_by   VARCHAR(20)  DEFAULT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS jobs (
  id         VARCHAR(20)  PRIMARY KEY,
  name       VARCHAR(255) NOT NULL,
  director   VARCHAR(255) DEFAULT '',
  co         VARCHAR(255) DEFAULT '',
  notes      TEXT         DEFAULT '',
  sort_order INT UNSIGNED DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS days (
  id         VARCHAR(20)  PRIMARY KEY,
  job_id     VARCHAR(20)  NOT NULL,
  label      VARCHAR(255) NOT NULL,
  shoot_date DATE         DEFAULT NULL,
  location   VARCHAR(255) DEFAULT '',
  sort_order INT UNSIGNED DEFAULT 0,
  FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS gear (
  id         VARCHAR(20)  PRIMARY KEY,
  day_id     VARCHAR(20)  NOT NULL,
  name       VARCHAR(255) NOT NULL,
  cat        VARCHAR(50)  DEFAULT 'Other',
  asset_id   VARCHAR(100) DEFAULT '',
  qty        INT UNSIGNED DEFAULT 1,
  value      DECIMAL(10,2) DEFAULT 0.00,
  notes      TEXT         DEFAULT '',
  status     ENUM('in','out') DEFAULT 'in',
  condition  VARCHAR(20)  DEFAULT 'Good',
  sort_order INT UNSIGNED DEFAULT 0,
  FOREIGN KEY (day_id) REFERENCES days(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory (
  id         VARCHAR(20)  PRIMARY KEY,
  name       VARCHAR(255) NOT NULL,
  cat        VARCHAR(50)  DEFAULT 'Other',
  asset_id   VARCHAR(100) DEFAULT '',
  qty        INT UNSIGNED DEFAULT 1,
  value      DECIMAL(10,2) DEFAULT 0.00,
  notes      TEXT         DEFAULT '',
  condition  VARCHAR(20)  DEFAULT 'Good',
  sort_order INT UNSIGNED DEFAULT 0
) ENGINE=InnoDB;

-- Operators request gear; admins approve/reject
-- Bookings span a date range (start_date → end_date, inclusive)
CREATE TABLE IF NOT EXISTS bookings (
  id          VARCHAR(20)  PRIMARY KEY,
  user_id     VARCHAR(20)  NOT NULL,
  job_id      VARCHAR(20)  DEFAULT NULL COMMENT 'optional job association',
  item_name   VARCHAR(255) NOT NULL,
  cat         VARCHAR(50)  DEFAULT 'Other',
  qty         INT UNSIGNED DEFAULT 1,
  start_date  DATE         NOT NULL,
  end_date    DATE         NOT NULL,
  location    VARCHAR(255) DEFAULT '',
  notes       TEXT         DEFAULT '',
  status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  resolved_by VARCHAR(20)  DEFAULT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (job_id)  REFERENCES jobs(id)  ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contacts (
  id         VARCHAR(20)  PRIMARY KEY,
  name       VARCHAR(255) NOT NULL,
  email      VARCHAR(255) DEFAULT '',
  phone      VARCHAR(80)  DEFAULT '',
  company    VARCHAR(255) DEFAULT '',
  role       VARCHAR(100) DEFAULT '',   -- default role (e.g. Director, Gaffer)
  notes      TEXT         DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Contacts assigned to a specific job with a role override
CREATE TABLE IF NOT EXISTS job_contacts (
  id         VARCHAR(20)  PRIMARY KEY,
  job_id     VARCHAR(20)  NOT NULL,
  contact_id VARCHAR(20)  NOT NULL,
  role       VARCHAR(100) DEFAULT '',   -- role on this job (overrides contact default)
  email_include TINYINT(1) NOT NULL DEFAULT 1,  -- default: include in job emails
  UNIQUE KEY uq_job_contact (job_id, contact_id),
  FOREIGN KEY (job_id)     REFERENCES jobs(id)     ON DELETE CASCADE,
  FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS activity_log (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  log_time   VARCHAR(30)  NOT NULL,
  html       TEXT         NOT NULL,
  type       VARCHAR(20)  DEFAULT 'add',
  user_id    VARCHAR(20)  DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
