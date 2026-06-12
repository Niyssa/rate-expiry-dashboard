-- ============================================================
-- Rate Expiry Dashboard — Full Database Schema + Seed Data
-- Run in phpMyAdmin: Import > choose this file > Go
-- ============================================================

CREATE DATABASE IF NOT EXISTS rate_expiry_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE rate_expiry_db;

-- ============================================================
-- TABLE: users
-- System users: admin, regular user, supervisor
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id          INT            AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100)   NOT NULL,
    email       VARCHAR(150)   NOT NULL UNIQUE,
    password    VARCHAR(255)   NOT NULL,
    role        ENUM('admin','user','supervisor') NOT NULL DEFAULT 'user',
    is_active   TINYINT(1)     NOT NULL DEFAULT 1,
    created_at  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- TABLE: customers_carriers
-- Both customers and carriers are stored here; distinguished by `type`
-- ============================================================
CREATE TABLE IF NOT EXISTS customers_carriers (
    id          INT            AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150)   NOT NULL,
    type        ENUM('Customer','Carrier') NOT NULL,
    email       VARCHAR(150)   DEFAULT NULL,
    phone       VARCHAR(30)    DEFAULT NULL,
    address     VARCHAR(255)   DEFAULT NULL,
    is_active   TINYINT(1)     NOT NULL DEFAULT 1,
    created_at  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- TABLE: rates
-- Core rate cards — status is recalculated daily from effective_through
--
-- Status thresholds (matching dashboard image):
--   Active        : effective_through >= TODAY + 6 months
--   Expiring Soon : effective_through in (TODAY+3mo, TODAY+6mo)
--   Critical Expiry: effective_through in (TODAY, TODAY+3mo)
--   Expired       : effective_through < TODAY
-- ============================================================
CREATE TABLE IF NOT EXISTS rates (
    id                   INT            AUTO_INCREMENT PRIMARY KEY,
    rate_code            VARCHAR(50)    NOT NULL UNIQUE,
    rate_description     VARCHAR(255)   NOT NULL,
    customer_carrier_id  INT            NOT NULL,
    type                 ENUM('Customer','Carrier') NOT NULL,
    effective_from       DATE           NOT NULL,
    effective_through    DATE           NOT NULL,
    status               ENUM('Active','Expiring Soon','Critical Expiry','Expired')
                                        NOT NULL DEFAULT 'Active',
    notes                TEXT           DEFAULT NULL,
    created_by           INT            DEFAULT NULL,
    created_at           DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rates_cc   FOREIGN KEY (customer_carrier_id)
        REFERENCES customers_carriers(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_rates_user FOREIGN KEY (created_by)
        REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
);

-- ============================================================
-- TABLE: notification_schedule
-- Defines the four reminder stages shown in the Notification Design image
-- ============================================================
CREATE TABLE IF NOT EXISTS notification_schedule (
    id              INT            AUTO_INCREMENT PRIMARY KEY,
    stage           TINYINT(1)     NOT NULL UNIQUE COMMENT '1=First, 2=Second, 3=Third, 4=Final',
    label           VARCHAR(50)    NOT NULL,
    days_before     INT            NOT NULL COMMENT 'Days before expiry to trigger reminder',
    description     VARCHAR(255)   DEFAULT NULL
);

-- ============================================================
-- TABLE: notifications
-- Log of every notification sent per rate per stage per channel
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications (
    id               INT            AUTO_INCREMENT PRIMARY KEY,
    rate_id          INT            NOT NULL,
    schedule_id      INT            NOT NULL,
    channel          ENUM('email','dashboard','teams','escalation') NOT NULL,
    recipient_email  VARCHAR(150)   DEFAULT NULL,
    message          TEXT           DEFAULT NULL,
    status           ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    sent_at          DATETIME       DEFAULT NULL,
    created_at       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notif_rate     FOREIGN KEY (rate_id)     REFERENCES rates(id)                 ON DELETE CASCADE,
    CONSTRAINT fk_notif_schedule FOREIGN KEY (schedule_id) REFERENCES notification_schedule(id) ON DELETE RESTRICT
);

-- ============================================================
-- TABLE: rate_acknowledgements
-- Audit trail — tracks who acknowledged an expiring/expired rate
-- ============================================================
CREATE TABLE IF NOT EXISTS rate_acknowledgements (
    id               INT            AUTO_INCREMENT PRIMARY KEY,
    rate_id          INT            NOT NULL,
    user_id          INT            NOT NULL,
    action           ENUM('acknowledged','renewed','escalated') NOT NULL DEFAULT 'acknowledged',
    notes            TEXT           DEFAULT NULL,
    acknowledged_at  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ack_rate FOREIGN KEY (rate_id)  REFERENCES rates(id)  ON DELETE CASCADE,
    CONSTRAINT fk_ack_user FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE
);

-- ============================================================
-- INDEXES
-- ============================================================
CREATE INDEX idx_rates_status            ON rates (status);
CREATE INDEX idx_rates_effective_through ON rates (effective_through);
CREATE INDEX idx_rates_type              ON rates (type);
CREATE INDEX idx_rates_cc_id             ON rates (customer_carrier_id);
CREATE INDEX idx_notif_rate_schedule     ON notifications (rate_id, schedule_id);
CREATE INDEX idx_notif_status            ON notifications (status);

-- ============================================================
-- SEED DATA — users
-- NOTE: passwords here are plain bcrypt placeholders.
--       Replace with real hashed passwords before use.
-- ============================================================
INSERT INTO users (name, email, password, role) VALUES
('Admin User',       'admin@company.com',      '$2y$10$abcdefghijklmnopqrstuuVwXyZ0123456789ABCDE', 'admin'),
('John Supervisor',  'supervisor@company.com',  '$2y$10$abcdefghijklmnopqrstuuVwXyZ0123456789ABCDE', 'supervisor'),
('Jane User',        'jane@company.com',        '$2y$10$abcdefghijklmnopqrstuuVwXyZ0123456789ABCDE', 'user');

-- ============================================================
-- SEED DATA — customers_carriers
-- Exactly the names visible in the dashboard image
-- ============================================================
INSERT INTO customers_carriers (name, type, email, phone) VALUES
('ABC Logistics',       'Customer', 'billing@abclogistics.com',     '+1-555-0101'),
('FastShip Inc',        'Carrier',  'ops@fastship.com',             '+1-555-0102'),
('Global Transport Co', 'Customer', 'rates@globaltransport.com',    '+1-555-0103'),
('SafeTransport LLC',   'Carrier',  'rates@safetransport.com',      '+1-555-0104'),
('Regional Express',    'Customer', 'billing@regionalexpress.com',  '+1-555-0105'),
('Pacific Freight Co',  'Carrier',  'ops@pacificfreight.com',       '+1-555-0106');

-- ============================================================
-- SEED DATA — notification_schedule
-- Four-Stage Reminder Schedule (from Notification Design image)
-- ============================================================
INSERT INTO notification_schedule (stage, label, days_before, description) VALUES
(1, 'First Reminder',  120, '4 months before expiry — initial early warning'),
(2, 'Second Reminder',  60, '2 months before expiry — follow-up alert'),
(3, 'Third Reminder',   30, '1 month before expiry — urgent reminder'),
(4, 'Final Reminder',    7, '7 days before expiry — final escalation notice');

-- ============================================================
-- SEED DATA — rates
-- All rows exactly as shown in the dashboard image.
-- Statuses are set to match the image; syncRateStatuses() will
-- recalculate them automatically on first page load.
--
-- Image data (screenshot taken ~Jun 9, 2026; today = Jun 12, 2026):
--   RC-2024-001 | Standard Freight - West Coast    | ABC Logistics       | Customer | Jan 01,2024 | Jan 15,2027 | 220 days    | Active
--   RC-2024-002 | Express Delivery - East Coast    | FastShip Inc        | Carrier  | Mar 01,2024 | Oct 30,2026 | 143 days    | Expiring Soon
--   RC-2024-003 | International Shipping - Europe  | Global Transport Co | Customer | Feb 15,2024 | Aug 20,2026 | 72 days     | Critical Expiry
--   RC-2024-004 | Hazmat Transport - Nationwide    | SafeTransport LLC   | Carrier  | Apr 01,2024 | Jul 15,2026 | 36 days     | Critical Expiry
--   RC-2023-015 | LTL Standard - Regional          | Regional Express    | Customer | Jun 01,2023 | Jun 03,2026 | 9 days ago  | Expired
--   RC-2022-009 | Ocean Freight - Pacific Route    | Pacific Freight Co  | Carrier  | Feb 01,2022 | Apr 30,2026 | 43 days ago | Expired
--   (RC-2022-009 is the 6th row partially cut off in the image — Expired count = 2)
-- ============================================================
INSERT INTO rates (rate_code, rate_description, customer_carrier_id, type, effective_from, effective_through, status, created_by) VALUES
('RC-2024-001', 'Standard Freight - West Coast',   1, 'Customer', '2024-01-01', '2027-01-15', 'Active',          1),
('RC-2024-002', 'Express Delivery - East Coast',   2, 'Carrier',  '2024-03-01', '2026-10-30', 'Expiring Soon',   1),
('RC-2024-003', 'International Shipping - Europe', 3, 'Customer', '2024-02-15', '2026-08-20', 'Critical Expiry', 1),
('RC-2024-004', 'Hazmat Transport - Nationwide',   4, 'Carrier',  '2024-04-01', '2026-07-15', 'Critical Expiry', 1),
('RC-2023-015', 'LTL Standard - Regional',         5, 'Customer', '2023-06-01', '2026-06-03', 'Expired',         1),
('RC-2022-009', 'Ocean Freight - Pacific Route',   6, 'Carrier',  '2022-02-01', '2026-04-30', 'Expired',         1);

-- ============================================================
-- SEED DATA — sample notification logs
-- Shows notifications already sent for the critical/expired rates
-- ============================================================
INSERT INTO notifications (rate_id, schedule_id, channel, recipient_email, message, status, sent_at) VALUES
-- RC-2024-003 (Critical Expiry - Aug 20, 2026) — stages 1 and 2 already sent
(3, 1, 'email',     'rates@globaltransport.com', 'Rate RC-2024-003 expires in ~4 months on Aug 20, 2026. Please initiate renewal.', 'sent', '2026-04-20 08:00:00'),
(3, 1, 'dashboard', NULL,                        'Rate RC-2024-003 is approaching expiry.',                                         'sent', '2026-04-20 08:00:00'),
(3, 2, 'email',     'rates@globaltransport.com', 'Rate RC-2024-003 expires in ~2 months on Aug 20, 2026. Renewal required.',         'sent', '2026-06-20 08:00:00'),
-- RC-2024-004 (Critical Expiry - Jul 15, 2026) — stages 1, 2, 3 already sent
(4, 1, 'email',     'rates@safetransport.com',   'Rate RC-2024-004 expires in ~4 months on Jul 15, 2026. Please initiate renewal.', 'sent', '2026-03-15 08:00:00'),
(4, 2, 'email',     'rates@safetransport.com',   'Rate RC-2024-004 expires in ~2 months on Jul 15, 2026. Renewal required.',        'sent', '2026-05-15 08:00:00'),
(4, 3, 'email',     'rates@safetransport.com',   'Rate RC-2024-004 expires in ~1 month on Jul 15, 2026. URGENT: renew now.',        'sent', '2026-06-15 08:00:00'),
(4, 3, 'teams',     NULL,                        'URGENT: Rate RC-2024-004 expires Jun 15, 2026 — action required.',                'sent', '2026-06-15 08:00:00'),
-- RC-2023-015 (Expired - Jun 03, 2026) — all 4 stages sent + escalation
(5, 1, 'email',     'billing@regionalexpress.com','Rate RC-2023-015 expires in ~4 months. Please initiate renewal.',                'sent', '2026-02-03 08:00:00'),
(5, 2, 'email',     'billing@regionalexpress.com','Rate RC-2023-015 expires in ~2 months. Renewal required.',                       'sent', '2026-04-03 08:00:00'),
(5, 3, 'email',     'billing@regionalexpress.com','Rate RC-2023-015 expires in ~1 month. URGENT: renew now.',                       'sent', '2026-05-03 08:00:00'),
(5, 4, 'email',     'billing@regionalexpress.com','FINAL NOTICE: Rate RC-2023-015 expires in 7 days on Jun 03, 2026.',              'sent', '2026-05-27 08:00:00'),
(5, 4, 'escalation','supervisor@company.com',    'AUTO-ESCALATION: Rate RC-2023-015 was not renewed. Expired Jun 03, 2026.',        'sent', '2026-06-04 08:00:00'),
-- RC-2022-009 (Expired - Apr 30, 2026) — all stages sent
(6, 1, 'email',     'ops@pacificfreight.com',    'Rate RC-2022-009 expires in ~4 months. Please initiate renewal.',                 'sent', '2025-12-30 08:00:00'),
(6, 2, 'email',     'ops@pacificfreight.com',    'Rate RC-2022-009 expires in ~2 months. Renewal required.',                        'sent', '2026-02-28 08:00:00'),
(6, 3, 'email',     'ops@pacificfreight.com',    'Rate RC-2022-009 expires in ~1 month. URGENT: renew now.',                        'sent', '2026-03-30 08:00:00'),
(6, 4, 'email',     'ops@pacificfreight.com',    'FINAL NOTICE: Rate RC-2022-009 expires in 7 days on Apr 30, 2026.',               'sent', '2026-04-23 08:00:00'),
(6, 4, 'escalation','supervisor@company.com',    'AUTO-ESCALATION: Rate RC-2022-009 was not renewed. Expired Apr 30, 2026.',        'sent', '2026-05-01 08:00:00');

-- ============================================================
-- SEED DATA — acknowledgements (audit trail)
-- ============================================================
INSERT INTO rate_acknowledgements (rate_id, user_id, action, notes) VALUES
(5, 2, 'acknowledged', 'Reviewed expired rate. Pending customer approval for renewal.'),
(6, 2, 'escalated',    'No response after Final Reminder. Escalated to management.');
