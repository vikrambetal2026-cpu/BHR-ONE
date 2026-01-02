# Quick Start Guide

## 🚀 5-Minute Setup

### Step 1: Upload Files (2 minutes)

1. Download all files from `/app/job-portal/` directory
2. Upload to your shared hosting `public_html` folder via:
   - FTP (FileZilla/WinSCP)
   - cPanel File Manager
   - SSH (if available)

### Step 2: Database Setup (2 minutes)

1. Login to phpMyAdmin
2. Select database: `RemoteATS-353032379971`
3. Click "Import" tab
4. Choose file: `sql/schema.sql`
5. Click "Go"

### Step 3: Test (1 minute)

1. Visit: `http://your-domain.com`
2. Click "Get Started"
3. Register as Employer
4. Check if you receive 1000 tokens

## ✅ Verification Checklist

- [ ] All files uploaded
- [ ] Database tables created (8 new tables)
- [ ] Config file updated with correct credentials
- [ ] Logs directory is writable (chmod 777)
- [ ] Can access: `your-domain.com/api/ping.php`
- [ ] User registration works
- [ ] Tokens are awarded on registration

## 🧪 Quick Test Commands

### Test Database Connection
```bash
curl http://your-domain.com/api/ping.php
# Should return: {"ok":true,"time":"..."}
```

### Check PHP Info
```bash
curl http://your-domain.com/api/diag.php
# Should show PHP version, extensions, file status
```

### Register Test User (via browser)
1. Go to homepage
2. Click "I'm Hiring"
3. Fill form:
   - Name: Test User
   - Company: Test Co
   - Email: test@test.com
   - Password: test123
4. Submit
5. Should redirect to dashboard

### Verify Token Account (SQL)
```sql
SELECT 
    u.email,
    u.role,
    et.token_balance,
    et.total_earned
FROM users u
LEFT JOIN employer_tokens et ON u.id = et.user_id
WHERE u.email = 'test@test.com';
```

**Expected Result:**
- email: test@test.com
- role: employer
- token_balance: 1000
- total_earned: 1000

## 🐛 Common Issues

### "Database connection failed"
→ Check `api/config.php` credentials

### "500 Internal Server Error"
→ Check PHP version (must be 5.6-7.3)
→ Enable error display in `api/bootstrap.php`

### "Session not working"
→ Check session permissions
→ Add `session_save_path('/tmp');` in `api/session.php`

### "API returns 404"
→ Check if `.htaccess` is uploaded
→ Contact host to enable mod_rewrite

## 📊 Monitor Performance

### Check Recent Registrations
```sql
SELECT email, role, created_at 
FROM users 
ORDER BY created_at DESC 
LIMIT 10;
```

### Check Token Usage
```sql
SELECT 
    DATE(created_at) as date,
    transaction_type,
    COUNT(*) as count,
    SUM(ABS(tokens)) as total_tokens
FROM token_transactions
GROUP BY DATE(created_at), transaction_type
ORDER BY date DESC;
```

### Check Job Postings
```sql
SELECT 
    COUNT(*) as total_jobs,
    SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active_jobs,
    SUM(CASE WHEN status='closed' THEN 1 ELSE 0 END) as closed_jobs
FROM jobs;
```

## 🎯 Next Actions

Once basic setup works:

1. **Configure Razorpay Webhook**
   - Login to Razorpay Dashboard
   - Add webhook: `https://your-domain.com/api/razorpay_webhook.php`
   - Secret: `xxxxxxxxxxxxxxxx`

2. **Test Token System**
   - Register employer
   - Try candidate search
   - Verify token deduction

3. **Test Contact Masking**
   - Search candidates
   - Check masked email/phone
   - Try unmasking (costs tokens)

4. **Complete Frontend**
   - Employer dashboard
   - Job seeker dashboard
   - All UI features

## 📞 Need Help?

If stuck, provide:
1. Error message (exact text)
2. URL where error occurs
3. PHP version (from diag.php)
4. Hosting provider name

---

**Ready to continue?** Once this is working, let me know and I'll complete the frontend dashboards!
