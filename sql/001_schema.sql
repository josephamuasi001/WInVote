CREATE DATABASE IF NOT EXISTS winvote_dev
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE winvote_dev;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(254) NOT NULL,
  password_hash VARCHAR(255) NULL,
  full_name VARCHAR(160) NOT NULL,
  phone VARCHAR(32) NULL,
  account_status ENUM('invited', 'active', 'suspended', 'closed') NOT NULL DEFAULT 'invited',
  email_verified_at DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_status_created (account_status, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS roles (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(40) NOT NULL,
  description VARCHAR(200) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_roles (
  user_id BIGINT UNSIGNED NOT NULL,
  role_id SMALLINT UNSIGNED NOT NULL,
  assigned_by BIGINT UNSIGNED NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role_id),
  KEY idx_user_roles_role (role_id),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_user_roles_assigner FOREIGN KEY (assigned_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS organizer_profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  organization_name VARCHAR(180) NOT NULL,
  organization_type VARCHAR(80) NULL,
  support_email VARCHAR(254) NULL,
  support_phone VARCHAR(32) NULL,
  approval_status ENUM('pending', 'approved', 'suspended', 'rejected') NOT NULL DEFAULT 'pending',
  approved_by BIGINT UNSIGNED NULL,
  approved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_organizer_owner (owner_user_id),
  KEY idx_organizer_status (approval_status, created_at),
  CONSTRAINT fk_organizer_owner FOREIGN KEY (owner_user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_organizer_approver FOREIGN KEY (approved_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organizer_id BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  name VARCHAR(180) NOT NULL,
  slug VARCHAR(200) NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  description TEXT NOT NULL,
  event_status ENUM('draft', 'pending_review', 'published', 'paused', 'closed', 'finalized', 'rejected') NOT NULL DEFAULT 'draft',
  voting_mode ENUM('free_multiple', 'paid_multiple', 'restricted_single') NOT NULL DEFAULT 'free_multiple',
  result_visibility ENUM('hidden', 'live', 'after_close', 'organizer_only') NOT NULL DEFAULT 'after_close',
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  max_votes_per_voter SMALLINT UNSIGNED NULL,
  price_per_vote DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'GHS',
  nomination_opens_at DATETIME NULL,
  nomination_closes_at DATETIME NULL,
  cover_image_key VARCHAR(40) NULL,
  approved_by BIGINT UNSIGNED NULL,
  approved_at DATETIME NULL,
  finalized_by BIGINT UNSIGNED NULL,
  finalized_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_event_slug (slug),
  UNIQUE KEY uq_event_id_organizer (id, organizer_id),
  KEY idx_events_public (event_status, starts_at, ends_at),
  KEY idx_events_organizer_status (organizer_id, event_status, created_at),
  CONSTRAINT fk_events_organizer FOREIGN KEY (organizer_id) REFERENCES organizer_profiles (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_events_creator FOREIGN KEY (created_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_events_approver FOREIGN KEY (approved_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_events_finalizer FOREIGN KEY (finalized_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS event_admins (
  event_id BIGINT UNSIGNED NOT NULL,
  organizer_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  access_role ENUM('manager', 'editor', 'viewer') NOT NULL DEFAULT 'editor',
  assigned_by BIGINT UNSIGNED NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id, user_id),
  KEY idx_event_admins_user (user_id),
  CONSTRAINT fk_event_admins_event_owner FOREIGN KEY (event_id, organizer_id)
    REFERENCES events (id, organizer_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_event_admins_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_event_admins_assigner FOREIGN KEY (assigned_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(140) NOT NULL,
  description TEXT NULL,
  image_key VARCHAR(40) NULL,
  category_status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_event_name (event_id, name),
  UNIQUE KEY uq_categories_id_event (id, event_id),
  KEY idx_categories_event_status (event_id, category_status),
  CONSTRAINT fk_categories_event FOREIGN KEY (event_id) REFERENCES events (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS nominations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  submitted_by BIGINT UNSIGNED NULL,
  applicant_name VARCHAR(160) NOT NULL,
  applicant_email VARCHAR(254) NOT NULL,
  nominee_name VARCHAR(160) NOT NULL,
  nominee_email VARCHAR(254) NULL,
  biography TEXT NULL,
  profile_image_key VARCHAR(40) NULL,
  nomination_status ENUM('pending', 'needs_information', 'approved', 'rejected', 'withdrawn') NOT NULL DEFAULT 'pending',
  review_notes TEXT NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nomination_id_event (id, event_id),
  KEY idx_nominations_event_status (event_id, nomination_status, created_at),
  KEY idx_nominations_category (category_id, nomination_status),
  CONSTRAINT fk_nominations_event FOREIGN KEY (event_id) REFERENCES events (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_nominations_category_event FOREIGN KEY (category_id, event_id)
    REFERENCES categories (id, event_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_nominations_submitter FOREIGN KEY (submitted_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_nominations_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS nomination_documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nomination_id BIGINT UNSIGNED NOT NULL,
  storage_key VARCHAR(500) NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  mime_type VARCHAR(127) NOT NULL,
  file_size_bytes BIGINT UNSIGNED NOT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_nomination_documents_nomination (nomination_id),
  CONSTRAINT fk_nomination_documents_nomination FOREIGN KEY (nomination_id) REFERENCES nominations (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contestants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id BIGINT UNSIGNED NOT NULL,
  nomination_id BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  display_name VARCHAR(160) NOT NULL,
  biography TEXT NULL,
  profile_image_key VARCHAR(500) NULL,
  contestant_status ENUM('pending', 'active', 'inactive', 'disqualified') NOT NULL DEFAULT 'pending',
  public_vote_counts TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_contestant_event_nomination (event_id, nomination_id),
  UNIQUE KEY uq_contestant_id_event (id, event_id),
  KEY idx_contestants_event_status (event_id, contestant_status),
  CONSTRAINT fk_contestants_event FOREIGN KEY (event_id) REFERENCES events (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_contestants_nomination_event FOREIGN KEY (nomination_id, event_id)
    REFERENCES nominations (id, event_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_contestants_creator FOREIGN KEY (created_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contestant_categories (
  event_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  contestant_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (category_id, contestant_id),
  UNIQUE KEY uq_contestant_category_event (event_id, category_id, contestant_id),
  KEY idx_contestant_categories_contestant (contestant_id, event_id),
  CONSTRAINT fk_contestant_categories_category_event FOREIGN KEY (category_id, event_id)
    REFERENCES categories (id, event_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_contestant_categories_contestant_event FOREIGN KEY (contestant_id, event_id)
    REFERENCES contestants (id, event_id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS voter_eligibility (
  event_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  eligibility_status ENUM('pending', 'eligible', 'revoked') NOT NULL DEFAULT 'pending',
  verified_by BIGINT UNSIGNED NULL,
  verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id, user_id),
  KEY idx_voter_eligibility_user (user_id, eligibility_status),
  CONSTRAINT fk_voter_eligibility_event FOREIGN KEY (event_id) REFERENCES events (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_voter_eligibility_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_voter_eligibility_verifier FOREIGN KEY (verified_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS election_ballots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  voter_user_id BIGINT UNSIGNED NOT NULL,
  ballot_status ENUM('cast', 'voided') NOT NULL DEFAULT 'cast',
  cast_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_election_ballot_voter (event_id, category_id, voter_user_id),
  UNIQUE KEY uq_election_ballot_id_event_category (id, event_id, category_id),
  CONSTRAINT fk_election_ballots_category_event FOREIGN KEY (category_id, event_id)
    REFERENCES categories (id, event_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_election_ballots_voter_eligibility FOREIGN KEY (event_id, voter_user_id)
    REFERENCES voter_eligibility (event_id, user_id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  contestant_id BIGINT UNSIGNED NOT NULL,
  voter_user_id BIGINT UNSIGNED NULL,
  merchant_reference VARCHAR(80) NOT NULL,
  vote_quantity SMALLINT UNSIGNED NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'GHS',
  transaction_status ENUM('pending', 'successful', 'failed', 'refunded', 'cancelled') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_transactions_merchant_reference (merchant_reference),
  UNIQUE KEY uq_transactions_id_event_category_contestant (id, event_id, category_id, contestant_id),
  KEY idx_transactions_event_status_created (event_id, transaction_status, created_at),
  KEY idx_transactions_voter_created (voter_user_id, created_at),
  CONSTRAINT fk_transactions_contestant_category FOREIGN KEY (event_id, category_id, contestant_id)
    REFERENCES contestant_categories (event_id, category_id, contestant_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_transactions_voter FOREIGN KEY (voter_user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payment_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transaction_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(40) NOT NULL,
  provider_reference VARCHAR(120) NULL,
  attempt_status ENUM('pending', 'successful', 'failed', 'unknown') NOT NULL DEFAULT 'pending',
  request_idempotency_key VARCHAR(100) NOT NULL,
  response_code VARCHAR(60) NULL,
  response_summary VARCHAR(500) NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  verified_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_attempt_idempotency (provider, request_idempotency_key),
  UNIQUE KEY uq_payment_attempt_provider_reference (provider, provider_reference),
  KEY idx_payment_attempts_transaction (transaction_id, attempted_at),
  CONSTRAINT fk_payment_attempts_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS votes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  contestant_id BIGINT UNSIGNED NOT NULL,
  voter_user_id BIGINT UNSIGNED NULL,
  ballot_id BIGINT UNSIGNED NULL,
  transaction_id BIGINT UNSIGNED NULL,
  payment_attempt_id BIGINT UNSIGNED NULL,
  idempotency_key VARCHAR(100) NOT NULL,
  vote_quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  vote_status ENUM('credited', 'reversed') NOT NULL DEFAULT 'credited',
  cast_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_votes_idempotency (idempotency_key),
  UNIQUE KEY uq_votes_ballot (ballot_id),
  KEY idx_votes_event_category_contestant (event_id, category_id, contestant_id, vote_status),
  KEY idx_votes_voter_event (voter_user_id, event_id, cast_at),
  KEY idx_votes_transaction (transaction_id),
  KEY idx_votes_payment_attempt (payment_attempt_id),
  CONSTRAINT fk_votes_contestant_category FOREIGN KEY (event_id, category_id, contestant_id)
    REFERENCES contestant_categories (event_id, category_id, contestant_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_votes_voter FOREIGN KEY (voter_user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_votes_ballot FOREIGN KEY (ballot_id, event_id, category_id)
    REFERENCES election_ballots (id, event_id, category_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_votes_transaction_target FOREIGN KEY (transaction_id, event_id, category_id, contestant_id)
    REFERENCES transactions (id, event_id, category_id, contestant_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_votes_payment_attempt FOREIGN KEY (payment_attempt_id) REFERENCES payment_attempts (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS organizer_wallets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organizer_id BIGINT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'GHS',
  wallet_status ENUM('active', 'suspended', 'closed') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wallet_organizer_currency (organizer_id, currency),
  CONSTRAINT fk_wallets_organizer FOREIGN KEY (organizer_id) REFERENCES organizer_profiles (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS wallet_transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  wallet_id BIGINT UNSIGNED NOT NULL,
  source_transaction_id BIGINT UNSIGNED NULL,
  entry_type ENUM('credit', 'debit', 'reversal', 'adjustment') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'GHS',
  entry_reference VARCHAR(100) NOT NULL,
  entry_note VARCHAR(500) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wallet_entry_reference (entry_reference),
  KEY idx_wallet_transactions_wallet_created (wallet_id, created_at),
  CONSTRAINT fk_wallet_transactions_wallet FOREIGN KEY (wallet_id) REFERENCES organizer_wallets (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_wallet_transactions_source FOREIGN KEY (source_transaction_id) REFERENCES transactions (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_wallet_transactions_creator FOREIGN KEY (created_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS withdrawals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  wallet_id BIGINT UNSIGNED NOT NULL,
  requested_by BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'GHS',
  destination_reference VARCHAR(255) NOT NULL,
  withdrawal_status ENUM('pending', 'approved', 'rejected', 'processing', 'paid', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  provider_reference VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_withdrawals_wallet_status (wallet_id, withdrawal_status, created_at),
  CONSTRAINT fk_withdrawals_wallet FOREIGN KEY (wallet_id) REFERENCES organizer_wallets (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_withdrawals_requester FOREIGN KEY (requested_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_withdrawals_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  notification_type VARCHAR(60) NOT NULL,
  title VARCHAR(180) NOT NULL,
  message TEXT NOT NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_user_unread (user_id, read_at, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS support_tickets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_reference VARCHAR(30) NOT NULL,
  requester_user_id BIGINT UNSIGNED NULL,
  requester_name VARCHAR(160) NOT NULL,
  requester_email VARCHAR(254) NOT NULL,
  topic VARCHAR(80) NOT NULL,
  subject VARCHAR(180) NOT NULL,
  message TEXT NOT NULL,
  ticket_status ENUM('open', 'in_progress', 'waiting_for_requester', 'resolved', 'closed') NOT NULL DEFAULT 'open',
  assigned_to BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_support_ticket_reference (ticket_reference),
  KEY idx_support_tickets_status_created (ticket_status, created_at),
  CONSTRAINT fk_support_requester FOREIGN KEY (requester_user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_support_assignee FOREIGN KEY (assigned_to) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  resource_type VARCHAR(80) NOT NULL,
  resource_id BIGINT UNSIGNED NULL,
  outcome ENUM('success', 'failure') NOT NULL,
  details JSON NULL,
  ip_address VARBINARY(16) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_logs_actor_created (actor_user_id, created_at),
  KEY idx_audit_logs_resource (resource_type, resource_id, created_at),
  CONSTRAINT fk_audit_logs_actor FOREIGN KEY (actor_user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS platform_settings (
  setting_key VARCHAR(100) NOT NULL,
  setting_value TEXT NOT NULL,
  value_type ENUM('string', 'integer', 'decimal', 'boolean', 'json') NOT NULL DEFAULT 'string',
  is_secret TINYINT(1) NOT NULL DEFAULT 0,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key),
  CONSTRAINT fk_platform_settings_updater FOREIGN KEY (updated_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;
