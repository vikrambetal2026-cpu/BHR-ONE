# Healthcare Job Portal - Deployment Guide

## 🏥 Overview
This is a **FREE Healthcare Job Portal** for healthcare professionals, doctors, nurses, medical staff, and academicians. The platform connects healthcare employers with qualified medical professionals.

## ✅ Fixes Applied (January 2026)

### 1. Configuration Fixed
- ✅ Renamed `config_updated.php` to `config.php`
- ✅ Updated all PHP files to reference correct config file:
  - `api/tokens.php`
  - `api/search_with_tokens.php`
  - `api/razorpay_webhook.php`
  - `api/user_auth.php`
  - `api/diag.php`

### 2. Runtime Issues Resolved
- ✅ Added missing session helper functions to `api/session.php`:
  - `isLoggedIn()`
  - `getCurrentUserId()`
  - `getCurrentUserRole()`
  - `getCurrentUserName()`
  - `getCurrentUserEmail()`
  - `setUserSession()`
  - `destroyUserSession()`
  - `requireRole()`
  - `getDbConnection()`

### 3. Healthcare Theme Applied
- ✅ Updated homepage (`index.html`) with healthcare branding:
  - New title: "Healthcare Job Portal - FREE Job Search for Healthcare Professionals & Doctors"
  - Healthcare-focused messaging
  - Medical emojis (🏥 🩺 👨‍⚕️)
  - Emphasis on FREE access
- ✅ Updated CSS (`assets/css/main.css`) with medical colors:
  - Primary: Medical Blue (#0369a1)
  - Secondary: Medical Green (#059669)
  - Healthcare-themed gradient

### 4. Free Tier Configuration
- ✅ Added free tier settings in `api/config.php`:
  - Daily search limit: 5 free searches
  - Daily unmask limit: 3 free contact reveals
  - Resets at midnight

## 📋 System Requirements

- **PHP Version:** 7.0 - 7.4 ✅
- **MySQL:** 5.5+
- **Extensions Required:**
  - PDO ✅
  - PDO_MySQL ✅
  - cURL ✅
  - JSON ✅
  - mbstring ✅

## 🚀 Deployment to hybrid.businesshr.in/bhrjp

### Step 1: Upload Files
Upload entire `/app/public_html/RemoteATS/bhrjp/` directory to your server at:
```
public_html/RemoteATS/bhrjp/
```

### Step 2: Set Permissions
```bash
chmod 755 api/
chmod 755 assets/
chmod 777 logs/  # Must be writable for webhook logs
```

### Step 3: Database Setup
1. Access phpMyAdmin
2. Select database: `RemoteATS-353032379971`
3. Import `sql/schema.sql` file
4. Verify tables are created:
   - users
   - employer_tokens
   - token_transactions
   - jobs
   - job_applications
   - search_keywords
   - payments
   - unmasked_contacts

### Step 4: Verify Configuration
Check `api/config.php` settings:
```php
'db' => [
    'host' => 'sdb-61.hosting.stackcp.net',
    'name' => 'RemoteATS-353032379971',
    'user' => 'admin-f90e',
    'pass' => 'ChangeMe@123',
],
```

### Step 5: Test Deployment

#### Test 1: API Connectivity
```bash
curl http://hybrid.businesshr.in/bhrjp/api/ping.php
```
Expected: `{"ok":true,"time":"..."}`

#### Test 2: System Diagnostics
```bash
curl http://hybrid.businesshr.in/bhrjp/api/diag.php
```
Should show:
- PHP version 7.x
- All required extensions loaded
- Database connection OK
- All required tables present

#### Test 3: User Registration
1. Visit: `http://hybrid.businesshr.in/bhrjp`
2. Click "Get Started FREE"
3. Register as Employer or Job Seeker
4. Verify token account created (for employers)

## 🔧 Configuration Details

### URL Configuration (CRITICAL)
**⚠️ HTTP ONLY - No HTTPS except for Razorpay**

All API endpoints use HTTP:
```
http://hybrid.businesshr.in/bhrjp/api/
```

Razorpay webhook requires HTTPS:
```
https://hybrid.businesshr.in/bhrjp/api/razorpay_webhook.php
```

### API Endpoints
- `/api/ping.php` - Health check
- `/api/diag.php` - System diagnostics
- `/api/user_auth.php` - Authentication (login/register/me/logout)
- `/api/jobs.php` - Job management
- `/api/applications.php` - Application management
- `/api/tokens.php` - Token balance & transactions
- `/api/search_with_tokens.php` - Candidate search with token deduction
- `/api/razorpay_webhook.php` - Payment webhook

### Token System
**Initial Tokens:** 1000 (for new employers)

**Token Costs:**
- Search: 150 tokens
- Unmask Email: 25 tokens
- Unmask Mobile: 50 tokens
- Download Profile: 100 tokens

**Free Tier (NEW):**
- 5 free searches per day
- 3 free contact unmasks per day
- Resets daily at midnight

## 🔐 Security Notes

1. **API Key:** Currently `CHANGE_ME_API_KEY` - **CHANGE THIS!**
2. **Database Password:** Update in production
3. **Razorpay Keys:** Live keys already configured
4. **HTTPS:** Enable for entire site (except noted otherwise)

## 🧪 Testing Checklist

- [ ] Homepage loads at http://hybrid.businesshr.in/bhrjp
- [ ] Healthcare branding visible (blue/green theme)
- [ ] User registration works
- [ ] Employer receives 1000 tokens on signup
- [ ] Job posting works
- [ ] Job seeker can browse jobs
- [ ] Job application works
- [ ] Candidate search works (costs tokens)
- [ ] Contact masking works
- [ ] Token deduction works correctly

## 📊 Database Schema

### Key Tables:
1. **users** - All user accounts (employers & job seekers)
2. **employer_tokens** - Token balances for employers
3. **token_transactions** - All token activity logs
4. **jobs** - Job postings
5. **job_applications** - Applications by job seekers
6. **payments** - Razorpay payment records
7. **unmasked_contacts** - Tracking of revealed contacts

## 🐛 Troubleshooting

### Issue: "Database connection failed"
**Solution:** Check database credentials in `api/config.php`

### Issue: "Call to undefined function getCurrentUserId"
**Solution:** Already fixed! Function added to `api/session.php`

### Issue: "config.php not found"
**Solution:** Already fixed! File renamed from config_updated.php

### Issue: "Session not working"
**Solution:** Check session permissions, logs directory must be writable

### Issue: "API returns 500 error"
**Solution:** 
1. Check `api/diag.php` for system status
2. Verify PHP version 7.0-7.4
3. Check error logs

## 📞 Support

For deployment issues:
1. Check PHP error logs on server
2. Run `api/diag.php` for diagnostics
3. Verify all file permissions
4. Check database connectivity

## 🎯 Healthcare Features

### For Employers (Hospitals, Clinics, Medical Institutions)
- ✅ Post unlimited healthcare job openings (FREE)
- ✅ Search database of healthcare professionals
- ✅ Find doctors, nurses, specialists, and medical staff
- ✅ AI-powered candidate matching
- ✅ Application tracking dashboard

### For Healthcare Professionals (Doctors, Nurses, Staff)
- ✅ 100% FREE access
- ✅ Browse unlimited healthcare job opportunities
- ✅ Apply to hospitals, clinics, and medical institutions
- ✅ Track all applications
- ✅ Direct contact with employers
- ✅ For doctors, nurses, healthcare staff & academicians

## 🌐 Live URL
Once deployed: **http://hybrid.businesshr.in/bhrjp**

---

**Last Updated:** January 1, 2026  
**Version:** 2.0 (Healthcare Edition)  
**PHP Compatibility:** 7.0 - 7.4  
**Status:** Production Ready ✅
