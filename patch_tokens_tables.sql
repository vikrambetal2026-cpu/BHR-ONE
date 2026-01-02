-- Patch: Token system tables (safe to run on existing DB)
-- Run this inside your existing database (phpMyAdmin -> select DB -> SQL tab -> paste/run)

CREATE TABLE IF NOT EXISTS employer_tokens (
  user_id INT NOT NULL,
  token_balance INT NOT NULL DEFAULT 0,
  total_earned INT NOT NULL DEFAULT 0,
  total_spent INT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS token_transactions (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  transaction_type VARCHAR(50) NOT NULL,
  tokens INT NOT NULL,
  candidate_id VARCHAR(64) NULL,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_created (user_id, created_at),
  KEY idx_user_type (user_id, transaction_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS unmasked_contacts (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  candidate_id VARCHAR(64) NOT NULL,
  email_unmasked TINYINT(1) NOT NULL DEFAULT 0,
  mobile_unmasked TINYINT(1) NOT NULL DEFAULT 0,
  profile_downloaded TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_user_candidate (user_id, candidate_id),
  KEY idx_candidate (candidate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional: grant initial tokens to existing employers who don't have a wallet row
-- (Change 500 if you want a different default)
INSERT INTO employer_tokens (user_id, token_balance, total_earned, total_spent)
SELECT u.id, 500, 500, 0
FROM users u
LEFT JOIN employer_tokens t ON t.user_id = u.id
WHERE (LOWER(u.role) IN ('employer','company','recruiter','hr'))
  AND t.user_id IS NULL;
