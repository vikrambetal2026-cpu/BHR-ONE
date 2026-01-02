# Healthcare Job Portal - Complete Setup Guide

## 🎯 Project Overview
A modern PHP-based job portal specifically designed for healthcare professionals and medical institutions. Features AI-powered and Normal candidate search with a token-based system.

## ✨ New Features Implemented

### 1. Dual Search Modes
- **AI Search** (150 tokens) - Advanced AI-powered candidate matching
- **Normal Search** (50 tokens) - Basic keyword-based search
- Toggle switch in employer dashboard to select mode
- Real-time token cost display

### 2. Super Admin Panel
- Secure admin authentication
- Token cost configuration for both search modes
- System statistics dashboard
- Database and payment settings management
- **Access:** `/admin-dashboard.html`
- **Default Credentials:** 
  - Username: `bhradmin`
  - Password: `ChangeMe@123` (must change on first login)

### 3. Modern UI Design
- Healthcare-themed color scheme (Medical Blue & Green)
- Clean, compact, responsive layout
- Smooth animations and transitions
- Card-based design
- Mobile-friendly interface

## 📋 Requirements
- PHP 7.0 - 7.4
- MySQL 5.5+
- Extensions: PDO, PDO_MySQL, cURL, JSON, mbstring
- Web server (Apache/Nginx)

## 🚀 Installation

### Step 1: Upload Files
Upload the entire `bhrjp` folder to your web server:
```
public_html/bhrjp/
```

### Step 2: Database Setup
1. Import the database schema from `sql/schema.sql`
2. Verify these tables exist:
   - users
   - employer_tokens
   - token_transactions
   - jobs
   - job_applications
   - candidate_freetext
   - payments
   - unmasked_contacts

### Step 3: Configuration
1. Update database credentials in `/api/config.php`:
```php
'db' => [
    'host' => 'your-host',
    'name' => 'your-database',
    'user' => 'your-username',
    'pass' => 'your-password',
],
```

2. Admin settings are auto-generated in `/api/admin_settings.json`

### Step 4: File Permissions
```bash
chmod 755 api/
chmod 755 assets/
chmod 666 api/admin_settings.json  # If it exists
```

### Step 5: Test Installation
1. Visit: `http://your-domain.com/bhrjp/`
2. You should see the healthcare-themed landing page
3. Test registration and login

## 🎛️ Admin Panel Usage

### Access Admin Panel
1. Navigate to: `http://your-domain.com/bhrjp/admin-dashboard.html`
2. Login with:
   - Username: `bhradmin`
   - Password: `ChangeMe@123`
3. Change password immediately when prompted

### Configure Token Costs
1. Go to "Token Settings" tab
2. Set costs for AI Search:
   - Search: 150 tokens (default)
   - Unmask Email: 25 tokens
   - Unmask Mobile: 50 tokens
   - Download Profile: 100 tokens

3. Set costs for Normal Search:
   - Search: 50 tokens (default)
   - Unmask Email: 15 tokens
   - Unmask Mobile: 30 tokens
   - Download Profile: 75 tokens

4. Click "Save Token Settings"

### Other Admin Features
- **Payment Settings:** Configure Razorpay credentials
- **Database Settings:** Update database connection (use with caution)
- **Site Settings:** Update site name, enable/disable AI search globally

## 👥 User Guide

### For Employers

#### 1. Register
1. Click "I'm Hiring" on homepage
2. Fill in company details
3. Receive 1000 free tokens

#### 2. Search Candidates
1. Login to employer dashboard
2. See toggle switch above search form
3. Choose search mode:
   - **AI Search ON** (150 tokens) - Smart AI matching
   - **AI Search OFF** (50 tokens) - Basic keyword search
4. Enter search query
5. Click "Search"
6. View results with masked contacts

#### 3. Post Jobs
1. Scroll to "Post a Job" section
2. Fill in job details
3. Click "Create Job"
4. Job appears in "My Jobs" section

#### 4. Manage Applications
1. In "My Jobs", click "View Applications"
2. See all applications for that job
3. Review candidate details

### For Job Seekers
1. Register as "Job Seeker"
2. Browse available jobs
3. Apply to jobs
4. Track application status

## 🔧 Advanced Configuration

### Token Costs
Edit via Admin Panel or directly in `/api/config.php`:
```php
'token_costs_ai' => [
    'search' => 150,
    'unmask_email' => 25,
    'unmask_mobile' => 50,
    'download_profile' => 100,
],

'token_costs_normal' => [
    'search' => 50,
    'unmask_email' => 15,
    'unmask_mobile' => 30,
    'download_profile' => 75,
],
```

### Initial Tokens for New Users
```php
'initial_tokens' => 1000,  // Tokens given on registration
```

### Free Tier Settings
```php
'free_tier' => [
    'enabled' => true,
    'daily_searches' => 5,
    'daily_unmasks' => 3,
    'reset_hour' => 0,  // Midnight
],
```

## 🐛 Troubleshooting

### Issue: Admin login not working
**Solution:** Check if `admin_settings.json` has write permissions
```bash
chmod 666 /api/admin_settings.json
```

### Issue: Search returns no results
**Solution:** 
1. Check if `candidate_freetext` table has data
2. Verify FULLTEXT index exists
3. Check database connection in config

### Issue: Toggle switch not working
**Solution:**
1. Clear browser cache
2. Check browser console for errors
3. Verify `search_unified.php` exists in `/api/`

### Issue: Token costs not updating
**Solution:**
1. Login to admin panel
2. Update costs in Token Settings tab
3. Click Save
4. Refresh employer dashboard

## 📱 Browser Compatibility
- Chrome/Edge: ✅ Fully supported
- Firefox: ✅ Fully supported
- Safari: ✅ Fully supported
- Mobile browsers: ✅ Responsive design

## 🔒 Security Best Practices
1. Change admin password immediately after first login
2. Change `api_key` in config.php from default `CHANGE_ME_API_KEY`
3. Use HTTPS in production
4. Keep PHP and database updated
5. Regularly backup database
6. Monitor admin access logs

## 📊 File Structure
```
/bhrjp/
├── index.html                     # Landing page
├── employer-dashboard.html        # Employer dashboard with toggle
├── admin-dashboard.html           # Super admin panel
├── jobseeker-dashboard.html       # Job seeker dashboard
├── api/
│   ├── config.php                 # Main configuration
│   ├── admin_auth.php             # Admin authentication
│   ├── admin_config.php           # Config management
│   ├── admin_stats.php            # Statistics API
│   ├── search_unified.php         # Unified search (AI + Normal)
│   ├── normal_search.php          # Normal search backend
│   ├── ai_candidate_search.php    # AI search backend
│   ├── user_auth.php              # User authentication
│   ├── jobs.php                   # Job management
│   ├── applications.php           # Applications
│   ├── tokens.php                 # Token management
│   └── ...
├── assets/
│   ├── css/
│   │   └── main.css               # Stylesheet with healthcare theme
│   └── js/
│       ├── main.js                # API helpers & UI utilities
│       └── employer-dashboard.js  # Dashboard logic
└── sql/
    └── schema.sql                 # Database schema
```

## 🎨 Customization

### Change Color Theme
Edit `/assets/css/main.css`:
```css
:root {
    --primary-color: #0369a1;      /* Your primary color */
    --secondary-color: #059669;    /* Your secondary color */
}
```

### Change Site Name
1. Login to admin panel
2. Go to "Site Settings" tab
3. Update "Site Name" and "Tagline"
4. Click Save

### Modify Token Costs
Use admin panel for easy modification without code changes.

## 📞 Support

### Common URLs
- **Homepage:** `/bhrjp/`
- **Employer Dashboard:** `/bhrjp/employer-dashboard.html`
- **Admin Panel:** `/bhrjp/admin-dashboard.html`
- **API Docs:** See `/api/` folder for endpoints

### Debug Mode
Enable errors in PHP for debugging:
```php
ini_set('display_errors', '1');
error_reporting(E_ALL);
```

## ✅ Post-Installation Checklist
- [ ] Database imported successfully
- [ ] Config.php updated with correct credentials
- [ ] Admin panel accessible
- [ ] Admin password changed
- [ ] Test user registration (employer)
- [ ] Test user registration (job seeker)
- [ ] Test job posting
- [ ] Test search toggle (AI/Normal)
- [ ] Test token deduction
- [ ] Verify token costs display correctly
- [ ] Check mobile responsiveness
- [ ] Configure Razorpay for payments (if needed)

## 🎯 Key Features Summary
✅ Dual search modes (AI & Normal) with toggle  
✅ Different token costs for each mode  
✅ Super admin panel for configuration  
✅ Clean, modern healthcare-themed UI  
✅ Responsive design for all devices  
✅ Secure authentication system  
✅ Token-based access control  
✅ Contact masking system  
✅ Job posting and application management  

## 📈 Future Enhancements
- Email notifications
- Search history
- Bulk operations
- Analytics dashboard
- PDF export for candidates
- Interview scheduling

---

**Version:** 1.0  
**Last Updated:** January 1, 2026  
**PHP Compatibility:** 7.0 - 7.4  
**Status:** Production Ready ✅
