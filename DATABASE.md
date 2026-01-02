# Database Schema Reference

## Tables Overview

### 1. users
Stores all user accounts (employers and job seekers)

```sql
CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(255) UNIQUE NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(255) NOT NULL,
  `role` ENUM('employer', 'jobseeker') NOT NULL,
  `company_name` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

**Sample Data:**
```sql
INSERT INTO users (email, password, full_name, role, company_name) VALUES
('employer@test.com', '$2y$10$...', 'John Employer', 'employer', 'TechCorp'),
('jobseeker@test.com', '$2y$10$...', 'Jane Seeker', 'jobseeker', NULL);
```

### 2. employer_tokens
Tracks token balance for each employer

```sql
CREATE TABLE `employer_tokens` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `token_balance` INT NOT NULL DEFAULT 1000,
  `total_earned` INT NOT NULL DEFAULT 1000,
  `total_spent` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

**Token Costs:**
- Search: 150 tokens
- Unmask Email: 25 tokens
- Unmask Mobile: 50 tokens
- Download Profile: 100 tokens

### 3. token_transactions
Log of all token activities

```sql
CREATE TABLE `token_transactions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `transaction_type` ENUM('search', 'unmask_email', 'unmask_mobile', 'download_profile', 'topup', 'initial') NOT NULL,
  `tokens` INT NOT NULL,
  `candidate_id` VARCHAR(100) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### 4. jobs
Job postings by employers

```sql
CREATE TABLE `jobs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `employer_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `skills` TEXT NOT NULL,
  `location` VARCHAR(255) NOT NULL,
  `experience_min` INT DEFAULT 0,
  `experience_max` INT DEFAULT NULL,
  `salary_min` DECIMAL(10,2) DEFAULT NULL,
  `salary_max` DECIMAL(10,2) DEFAULT NULL,
  `job_type` ENUM('full-time', 'part-time', 'contract', 'internship') DEFAULT 'full-time',
  `status` ENUM('active', 'closed', 'draft') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employer_id) REFERENCES users(id) ON DELETE CASCADE,
  FULLTEXT KEY ft_title_skills (title, skills)
);
```

### 5. job_applications
Applications submitted by job seekers

```sql
CREATE TABLE `job_applications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `job_id` INT NOT NULL,
  `jobseeker_id` INT NOT NULL,
  `resume_text` TEXT DEFAULT NULL,
  `cover_letter` TEXT DEFAULT NULL,
  `status` ENUM('applied', 'shortlisted', 'rejected', 'hired') DEFAULT 'applied',
  `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
  FOREIGN KEY (jobseeker_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY unique_application (job_id, jobseeker_id)
);
```

### 6. search_keywords
Learning system for keyword optimization

```sql
CREATE TABLE `search_keywords` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `keyword` VARCHAR(100) NOT NULL,
  `frequency` INT NOT NULL DEFAULT 1,
  `source` ENUM('search', 'resume', 'job_posting') NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_keyword_source (keyword, source)
);
```

### 7. payments
Razorpay payment records

```sql
CREATE TABLE `payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `razorpay_payment_id` VARCHAR(100) DEFAULT NULL,
  `razorpay_order_id` VARCHAR(100) DEFAULT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `tokens` INT NOT NULL,
  `status` ENUM('pending', 'success', 'failed') DEFAULT 'pending',
  `payment_method` VARCHAR(50) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### 8. unmasked_contacts
Tracks which contacts employer has unmasked

```sql
CREATE TABLE `unmasked_contacts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `candidate_id` VARCHAR(100) NOT NULL,
  `email_unmasked` TINYINT(1) DEFAULT 0,
  `mobile_unmasked` TINYINT(1) DEFAULT 0,
  `profile_downloaded` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY unique_user_candidate (user_id, candidate_id)
);
```

## Indexes

### Performance Indexes
```sql
-- Users
CREATE INDEX idx_email ON users(email);
CREATE INDEX idx_role ON users(role);

-- Jobs
CREATE INDEX idx_employer ON jobs(employer_id);
CREATE INDEX idx_status ON jobs(status);
CREATE INDEX idx_location ON jobs(location);
CREATE FULLTEXT INDEX ft_title_skills ON jobs(title, skills);

-- Applications
CREATE INDEX idx_job ON job_applications(job_id);
CREATE INDEX idx_jobseeker ON job_applications(jobseeker_id);
CREATE INDEX idx_status ON job_applications(status);

-- Transactions
CREATE INDEX idx_user_id ON token_transactions(user_id);
CREATE INDEX idx_type ON token_transactions(transaction_type);
CREATE INDEX idx_created ON token_transactions(created_at);
```

## Sample Queries

### Get employer with token balance
```sql
SELECT 
    u.id,
    u.email,
    u.full_name,
    u.company_name,
    et.token_balance,
    et.total_earned,
    et.total_spent
FROM users u
LEFT JOIN employer_tokens et ON u.id = et.user_id
WHERE u.role = 'employer' AND u.id = 1;
```

### Get jobs with application count
```sql
SELECT 
    j.*,
    u.company_name,
    COUNT(DISTINCT ja.id) as application_count
FROM jobs j
LEFT JOIN users u ON j.employer_id = u.id
LEFT JOIN job_applications ja ON j.id = ja.job_id
WHERE j.status = 'active'
GROUP BY j.id
ORDER BY j.created_at DESC;
```

### Get token transaction summary
```sql
SELECT 
    transaction_type,
    COUNT(*) as transaction_count,
    SUM(ABS(tokens)) as total_tokens
FROM token_transactions
WHERE user_id = 1
GROUP BY transaction_type;
```

### Check unmasked contacts for employer
```sql
SELECT 
    candidate_id,
    email_unmasked,
    mobile_unmasked,
    profile_downloaded,
    created_at
FROM unmasked_contacts
WHERE user_id = 1
ORDER BY created_at DESC;
```

## Data Flow Examples

### Employer Registration Flow
```
1. INSERT into users (role='employer')
2. Get new user_id
3. INSERT into employer_tokens (user_id, token_balance=1000)
4. INSERT into token_transactions (type='initial', tokens=1000)
```

### Candidate Search Flow
```
1. Check employer_tokens.token_balance >= 150
2. UPDATE employer_tokens SET token_balance = token_balance - 150
3. INSERT into token_transactions (type='search', tokens=-150)
4. Perform search in candidate_freetext
5. Return masked results
```

### Unmask Email Flow
```
1. Check if already unmasked in unmasked_contacts
2. If not: Check token_balance >= 25
3. UPDATE employer_tokens (deduct 25)
4. INSERT into token_transactions (type='unmask_email', tokens=-25)
5. INSERT/UPDATE unmasked_contacts (email_unmasked=1)
6. Return unmasked email
```

---

**Note:** All monetary amounts in `payments` table are in INR (Indian Rupees)
