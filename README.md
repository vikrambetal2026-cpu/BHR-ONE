# Job Portal - Complete Deployment Guide

## 🚀 Overview

This is a complete job portal application with employer and job seeker functionality, featuring:
- AI-powered candidate search
- Token-based access system
- Razorpay payment integration
- Contact information masking
- Job posting and application tracking

## 📋 System Requirements

- **PHP Version:** 5.6 to 7.3 (tested on shared hosting)
- **MySQL:** 5.5+
- **Extensions Required:**
  - PDO
  - PDO_MySQL
  - cURL
  - JSON
  - mbstring

## 📁 Project Structure

```
public_html/
├── index.html              # Landing page with login/register
├── employer-dashboard.html # Employer dashboard (to be completed)
├── jobseeker-dashboard.html# Job seeker dashboard (to be completed)
├── .htaccess              # URL rewriting rules
├── api/                   # Backend PHP APIs
│   ├── config_updated.php # Main configuration
│   ├── user_auth.php      # Authentication
│   ├── jobs.php           # Job management
│   ├── applications.php   # Application management
│   ├── tokens.php         # Token management
│   ├── search_with_tokens.php # Search with token deduction
│   ├── razorpay_webhook.php   # Payment webhook
│   ├── [existing AI files]    # AI candidate search files
│   └── ...
├── assets/
│   ├── css/
│   │   └── main.css       # Main stylesheet
│   └── js/
│       └── main.js        # API helpers and utilities
├── logs/                  # Webhook logs
└── sql/
    └── schema.sql         # Database schema
```

## 🗄️ Database Setup

### Step 1: Import Database Schema

1. Access your cPanel/phpMyAdmin
2. Select your database: `RemoteATS-353032379971`
3. Go to "Import" tab
4. Upload `sql/schema.sql` file
5. Click "Go" to execute

**OR** manually run the SQL commands in `sql/schema.sql`

### Step 2: Verify Tables Created

The following tables should be created:
- `users` - User accounts (employers & job seekers)
- `employer_tokens` - Token balances
- `token_transactions` - Token usage history
- `jobs` - Job postings
- `job_applications` - Applications
- `search_keywords` - Learning keywords
- `payments` - Razorpay payments
- `unmasked_contacts` - Unmask tracking
- `candidate_freetext` - (Already exists from old system)

### Step 3: Verify Existing Data

Check if `candidate_freetext` table has data:
```sql
SELECT COUNT(*) FROM candidate_freetext;
```

This table contains candidate profiles for AI search.

## ⚙️ Configuration

### Update Config File

Edit `api/config_updated.php` and verify:

```php
'db' => [
    'host' => 'sdb-61.hosting.stackcp.net',  # Your DB host
    'name' => 'RemoteATS-353032379971',      # Your DB name
    'user' => 'admin-f90e',                   # Your DB user
    'pass' => 'ChangeMe@123',                    # Your DB password
],

'razorpay' => [
    'key_id' => 'rzp_live_xxxxxxxxxxxx',
    'key_secret' => 'xxxxxxxxxxxxxxxx',
    'webhook_secret' => 'xxxxxxxxxxxxxxxx',
],

'token_costs' => [
    'search' => 150,
    'unmask_email' => 25,
    'unmask_mobile' => 50,
    'download_profile' => 100,
],
```

### Rename Config File

After verification:
```bash
mv api/config_updated.php api/config.php
```

**OR** update all PHP files to use `config_updated.php` instead of `config.php`

## 📤 Upload to Shared Hosting

### Method 1: FTP/SFTP

1. Connect to your hosting via FTP (FileZilla, WinSCP)
2. Navigate to `public_html` or `www` directory
3. Upload entire `job-portal` folder contents
4. Set permissions:
   - `logs/` folder: 755 or 777
   - `api/` folder: 755

### Method 2: cPanel File Manager

1. Login to cPanel
2. Open File Manager
3. Navigate to `public_html`
4. Upload files via "Upload" button
5. Extract if uploaded as ZIP

### Important: File Permissions

```bash
chmod 755 api/
chmod 755 assets/
chmod 777 logs/  # Must be writable for webhook logs
```

## 🔧 Post-Deployment Configuration

### 1. Update API Base URL

If your domain is different from `hybrid.businesshr.in`, update:

**In `api/search_with_tokens.php` (line 86):**
```php
$ch = curl_init('http://YOUR-DOMAIN.com/api/ai_candidate_search.php');
```

### 2. Test API Endpoints

Access these URLs to verify setup:

```
http://your-domain.com/api/ping.php
# Should return: {"ok":true,"time":"..."}

http://your-domain.com/api/diag.php
# Should show PHP version and extensions
```

### 3. Verify .htaccess

Ensure `.htaccess` is working:
- If you get 404 errors, contact hosting to enable `mod_rewrite`
- Some hosts require `RewriteBase /` in `.htaccess`

## 🎯 Razorpay Setup

### 1. Configure Webhook

Login to Razorpay Dashboard:
1. Go to Settings → Webhooks
2. Add new webhook URL:
   ```
   https://your-domain.com/api/razorpay_webhook.php
   ```
3. Secret: `xxxxxxxxxxxxxxxx`
4. Select events: `payment.captured`
5. Save

### 2. Test Webhook

Razorpay provides webhook testing in dashboard.

Check logs at: `logs/razorpay_webhook.log`

## 🧪 Testing the Application

### 1. Test User Registration

1. Open `http://your-domain.com`
2. Click "Get Started" or "I'm Hiring"
3. Register as **Employer**:
   - Email: `test@example.com`
   - Password: `test123`
   - Company: `Test Company`
4. Verify:
   - User created in `users` table
   - Token account created in `employer_tokens` with 1000 tokens
   - Transaction logged in `token_transactions`

### 2. Test Job Posting

**API Test (using curl or Postman):**
```bash
curl -X POST http://your-domain.com/api/jobs.php?action=create \
  -H "Content-Type: application/json" \
  -d '{
    "title": "PHP Developer",
    "description": "Looking for experienced PHP developer",
    "skills": "PHP, MySQL, JavaScript",
    "location": "Mumbai",
    "experience_min": 2,
    "experience_max": 5,
    "salary_min": 50000,
    "salary_max": 80000,
    "job_type": "full-time"
  }'
```

### 3. Test Candidate Search (IMPORTANT)

**Prerequisites:**
- Employer must be logged in (session active)
- Employer must have tokens (check `employer_tokens` table)
- `candidate_freetext` table must have data

**API Test:**
```bash
curl -X POST http://your-domain.com/api/search_with_tokens.php?action=search \
  -H "Content-Type: application/json" \
  -H "Cookie: PHPSESSID=your-session-id" \
  -d '{"query": "PHP developer with 3 years experience"}'
```

**Expected Response:**
```json
{
  "original_query": "PHP developer with 3 years experience",
  "optimized_query": "...",
  "keywords": [...],
  "results": [
    {
      "candidate_id": "123",
      "profile": "...",
      "email": "te***@example.com",
      "email_masked": true,
      "mobile": "98****4321",
      "mobile_masked": true,
      ...
    }
  ],
  "tokens_deducted": 150,
  "new_balance": 850
}
```

### 4. Check Token Deduction

After search, verify:
```sql
SELECT * FROM employer_tokens WHERE user_id = 1;
# token_balance should be 850 (1000 - 150)

SELECT * FROM token_transactions WHERE user_id = 1 ORDER BY created_at DESC LIMIT 5;
# Should show search transaction
```

## 🐛 Troubleshooting

### Issue: "Database connection failed"

**Solution:**
1. Verify database credentials in `api/config.php`
2. Check if database exists
3. Verify user has permissions
4. Test connection:
   ```php
   <?php
   $pdo = new PDO("mysql:host=HOST;dbname=DB", "USER", "PASS");
   echo "Connected!";
   ?>
   ```

### Issue: "Call to undefined function curl_init"

**Solution:**
- Contact hosting to enable cURL extension
- Or add to `.htaccess`:
  ```
  php_value extension curl.so
  ```

### Issue: "Session not working"

**Solution:**
1. Check if session directory is writable
2. Add to `api/session.php` (line 2):
   ```php
   session_save_path('/tmp');
   session_start();
   ```

### Issue: "API returns 500 error"

**Solution:**
1. Enable error display temporarily:
   ```php
   ini_set('display_errors', '1');
   error_reporting(E_ALL);
   ```
2. Check PHP error logs
3. Verify all required files exist

### Issue: "Razorpay webhook not working"

**Solution:**
1. Check `logs/razorpay_webhook.log` for errors
2. Verify webhook URL is accessible publicly
3. Test signature verification
4. Ensure `logs/` directory is writable (777)

### Issue: "Search returns no results"

**Solution:**
1. Check if `candidate_freetext` table has data
2. Verify FULLTEXT index exists:
   ```sql
   SHOW INDEX FROM candidate_freetext;
   ```
3. Test simple query:
   ```sql
   SELECT * FROM candidate_freetext LIMIT 1;
   ```

## 📊 Database Queries for Testing

### Check User Accounts
```sql
SELECT u.id, u.email, u.role, et.token_balance 
FROM users u 
LEFT JOIN employer_tokens et ON u.id = et.user_id 
ORDER BY u.created_at DESC;
```

### Check Token Transactions
```sql
SELECT 
    tt.*,
    u.email as user_email
FROM token_transactions tt
LEFT JOIN users u ON tt.user_id = u.id
ORDER BY tt.created_at DESC
LIMIT 20;
```

### Check Jobs Posted
```sql
SELECT 
    j.*,
    u.company_name,
    COUNT(ja.id) as application_count
FROM jobs j
LEFT JOIN users u ON j.employer_id = u.id
LEFT JOIN job_applications ja ON j.id = ja.job_id
GROUP BY j.id
ORDER BY j.created_at DESC;
```

### Check Applications
```sql
SELECT 
    ja.*,
    j.title as job_title,
    u.full_name as applicant_name
FROM job_applications ja
LEFT JOIN jobs j ON ja.job_id = j.id
LEFT JOIN users u ON ja.jobseeker_id = u.id
ORDER BY ja.applied_at DESC;
```

## 🔐 Security Considerations

1. **Change API Key:**
   - Update `api_key` in `config.php`
   - Default is `CHANGE_ME_API_KEY` - change it!

2. **Database Credentials:**
   - Never commit `config.php` to public repos
   - Use environment variables if possible

3. **File Permissions:**
   - Config files: 644
   - Directories: 755
   - Logs directory: 755 (or 777 if needed)

4. **HTTPS:**
   - Enable SSL certificate
   - Update Razorpay webhook to HTTPS
   - Force HTTPS in `.htaccess`:
     ```
     RewriteCond %{HTTPS} off
     RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
     ```

## 📝 Next Steps

### Immediate (You can test now):
1. ✅ Import database schema
2. ✅ Upload files to hosting
3. ✅ Update configuration
4. ✅ Test user registration
5. ✅ Test API endpoints

### Pending (Need to complete):
1. ⏳ Employer dashboard HTML
2. ⏳ Job seeker dashboard HTML
3. ⏳ Complete UI for all features
4. ⏳ Testing agent integration

## 🆘 Support

If you encounter issues:

1. Check PHP error logs
2. Check `logs/razorpay_webhook.log`
3. Test API endpoints individually
4. Verify database schema is complete
5. Check file permissions

## 📞 Contact Information

For hosting-specific issues, contact your hosting provider for:
- PHP version compatibility
- Extension availability
- `.htaccess` support
- Session configuration
- Log file access

---

**Last Updated:** January 2026
**Version:** 1.0
**Compatible with:** PHP 5.6 - 7.3, MySQL 5.5+
