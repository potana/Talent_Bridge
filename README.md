# TalentBridge – A Job Seeking Platform

A job-board platform connecting job seekers, employers, and administrators implemented with the LAMP stack.

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8+ (no framework) |
| Database | MySQL 8.0, utf8mb4 charset |
| Web server | Apache 2.4 with `.htaccess` |
| CSS framework | Bootstrap 5.3.3 (CDN) |
| JavaScript | Vanilla ES6+ |
| Deployment | GitHub Actions CI/CD + rsync |
| Testing | PHPUnit 11 (via Composer) |
| Package manager | Composer 2 |

---

## Local Development Prerequisites

The following tools must be installed on your local machine before running tests or contributing to the project.

| Tool | Purpose |
|---|---|
| [Composer 2](https://getcomposer.org) | PHP package manager — installs PHPUnit and other dev dependencies |
| PHP 8+ | Required to run Composer and the test suite |

**Install dev dependencies:**

```bash
composer install
```

**Run the test suite:**

```bash
vendor/bin/phpunit
```

> `composer install` reads `composer.json` and downloads PHPUnit 11 (and any other dev dependencies) into the `vendor/` directory. The `vendor/` directory is gitignored and must be generated locally.

---

## Project Structure

```
assignment-01/
│   # public entry points
├── index.php           landing page / hero
├── login.php           login form (all roles)
├── register.php        registration (seeker or employer)
├── logout.php          session destroy + redirect
├── jobs.php            public job listings with filters
├── job_detail.php      single job view + apply CTA
├── contact.php         public contact form
├── about.php           about page with team section
├── download.php        secure CV download (multi-role)
│
│   # admin panel
├── admin/
│   ├── dashboard.php   site-wide stats overview
│   ├── users.php       user management (activate/deactivate)
│   ├── listings.php    all job listings management
│   └── messages.php    contact message inbox
│
│   # employer portal
├── employer/
│   ├── company_profile.php   create/edit company details
│   ├── post_job.php          new job listing form
│   ├── manage_listings.php   edit/close own listings
│   └── view_applicants.php   review applications per listing
│
│   # seeker portal
├── seeker/
│   ├── profile.php       edit profile, headline, skills, CV upload
│   ├── applications.php  view submitted applications + statuses
│   └── saved_jobs.php    bookmarked job listings
│
│   # shared includes (not web-accessible)
├── includes/
│   ├── db.php            PDO singleton
│   ├── auth.php          session helpers + role guards
│   ├── csrf.php          CSRF token generation + validation
│   ├── helpers.php       sanitise, redirect, flash message utils
│   └── nav.php           shared navigation bar
│
│   # front-end assets
├── assets/
│   ├── css/style.css     custom theme on top of Bootstrap
│   └── js/
│       ├── validation.js    reusable client-side form validation
│       ├── filter.js        live job card filtering
│       ├── application.js   apply form toggle + smooth scroll
│       ├── charcount.js     textarea character counter
│       └── admin_stats.js   animated stat counters
│
│   # database
├── sql/schema.sql        full schema (7 tables, InnoDB, utf8mb4)
│
│   # configuration (gitignored)
├── config.php            ← create from config.example.php (never commit)
└── config.example.php    safe template with placeholder values
```

---

## Server Provisioning

These steps set up the LAMP server from a fresh GCP VM. Run them once before deploying the application.

### 1. Connect to the VM

From the **VM Instances** page in the Google Cloud Console, click the **SSH** button for a browser-based terminal, or connect from a local terminal:

```bash
gcloud compute ssh talentbridge-server --zone=asia-southeast1-a
```

### 2. Install the LAMP stack

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install apache2 mysql-server php php-mysql php-mbstring php-xml libapache2-mod-php rsync -y
```

### 3. Create the deployment user

```bash
sudo adduser <deploy-user>        # leave all fields blank
sudo usermod -aG sudo <deploy-user>
```

### 4. Enable password-based SSH authentication

```bash
sudo nano /etc/ssh/sshd_config
```

Find and set these two lines (they may be commented out):

```
PasswordAuthentication yes
KbdInteractiveAuthentication yes
```

Save, then restart SSH:

```bash
sudo service ssh restart
```

You can now connect directly from any terminal:

```bash
ssh <deploy-user>@<server-ip>
```

### 5. Set web root permissions

Add the deployment user to the `www-data` group and set ownership so files uploaded via SFTP are readable by Apache. The `chown` step is also required for the GitHub Actions deployment — rsync's archive mode (`-a`) preserves timestamps, and the deploy user must own the web root directories to do so without errors.

```bash
sudo usermod -a -G www-data <deploy-user>
sudo chown -R <deploy-user>:www-data /var/www/html
sudo chmod 2775 /var/www/html
find /var/www/html -type d -exec sudo chmod 2775 {} \;
find /var/www/html -type f -exec sudo chmod 0664 {} \;
```

### 6. Set the MySQL user password

```bash
sudo mysql
```

Inside the MySQL shell:

```sql
ALTER USER '<db-user>'@'localhost' IDENTIFIED WITH mysql_native_password BY '<db-password>';
```

### 7. Verify services are running

```bash
sudo systemctl status apache2
sudo systemctl status mysql
```

Navigating to `http://<server-ip>` in a browser should display the Apache2 default page, confirming the server is live.

---

## Local / Server Setup

### 1. Clone the repository

```bash
git clone <repo-url>
cd assignment-01
```

### 2. Create `config.php`

```bash
cp config.example.php config.php
```

Open `config.php` and fill in your database host, name, username, and password.

### 3. Create the database and import the schema

```bash
mysql -u USER -p DB_NAME < sql/schema.sql
```

### 4. Create the CV upload directory (outside web root)

```bash
sudo mkdir -p /var/uploads/cvs
sudo chown -R www-data:www-data /var/uploads
sudo chmod 750 /var/uploads /var/uploads/cvs
```

### 5. Create the initial admin account

Run this once from the project root (replace the placeholder values):

```php
php -r "
require 'config.php';
require 'includes/db.php';
\$pdo = getConnection();
\$hash = password_hash('YOUR_ADMIN_PASSWORD', PASSWORD_BCRYPT);
\$stmt = \$pdo->prepare(
    'INSERT INTO users (email, password_hash, first_name, last_name, role)
     VALUES (?, ?, ?, ?, \"admin\")'
);
\$stmt->execute(['admin@example.com', \$hash, 'Admin', 'User']);
echo 'Admin created.' . PHP_EOL;
"
```

### 6. Verify

Visit `http://SERVER_IP/` — the landing page should load without errors.

---

## Deployment (SFTP)

| Setting | Value |
|---|---|
| Server IP | `<server-ip>` |
| Remote web root | `/var/www/html` |
| SSH username | `<deploy-user>` |
| Tool | VS Code SFTP extension |
| Config file | `.vscode/sftp.json` (already committed) |

**Do NOT deploy:**
- `config.php` — contains credentials
- `.git/` — version control internals
- `docs/` — project documentation

**After deploying**, ensure the CV upload directory is writable:

```bash
sudo chown -R www-data:www-data /var/uploads/cvs
sudo chmod 750 /var/uploads/cvs
```

---

## HTTPS Setup

The server runs on a bare IP address (`<server-ip>`). Let's Encrypt does not issue certificates for IP addresses, so a domain name is required first.

### 1. Get a free subdomain

[DuckDNS](https://www.duckdns.org) is the simplest option — create a free subdomain (e.g. `talentbridge.duckdns.org`) and point it at `<server-ip>`. DNS propagates within minutes.

### 2. Open port 443 in GCP

In the Google Cloud Console go to **VPC Network → Firewall → Create firewall rule**:

| Field | Value |
|---|---|
| Name | `allow-https` |
| Source IP ranges | `0.0.0.0/0` |
| Protocols / ports | `tcp:443` |

Or via the CLI:

```bash
gcloud compute firewall-rules create allow-https \
  --allow tcp:443 \
  --source-ranges 0.0.0.0/0
```

### 3. Install Certbot and obtain the certificate

SSH into the VM, then:

```bash
sudo apt update
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d your-subdomain.duckdns.org
```

Certbot automatically configures Apache's SSL virtual host and sets up auto-renewal. Verify renewal works:

```bash
sudo certbot renew --dry-run
```

### 4. Enable the HTTPS redirect

Uncomment the three lines in `.htaccess` (the redirect block under `mod_rewrite`):

```apache
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

### 5. Enable the Secure cookie flag

Once HTTPS is confirmed working, add one line to the session cookie block in `.htaccess`:

```apache
<IfModule mod_php.c>
    php_flag session.cookie_httponly On
    php_value session.cookie_samesite Lax
    php_flag session.cookie_secure On
</IfModule>
```

This prevents the session cookie from ever being sent over plain HTTP.

---

## Database Schema

All tables use the InnoDB engine, utf8mb4 charset, and CASCADE on delete.

| Table | Key Columns | Notes |
|---|---|---|
| `users` | `id`, `email`, `password_hash`, `role` ENUM(`seeker`/`employer`/`admin`), `is_active` | Base account for all roles |
| `seeker_profiles` | `user_id`, `headline`, `skills`, `cv_path`, `location` | 1:1 with `users` |
| `companies` | `user_id`, `company_name`, `industry`, `description` | 1:1 with employer `users` |
| `job_listings` | `company_id`, `title`, `type` ENUM, `salary_min`, `salary_max`, `salary_period` ENUM, `status` ENUM(`active`/`closed`/`draft`) | Belongs to `companies` |
| `applications` | `job_id`, `user_id`, `status` ENUM, UNIQUE(`job_id`, `user_id`) | Seeker applies to job |
| `saved_jobs` | `user_id`, `job_id`, UNIQUE(`user_id`, `job_id`) | Seeker bookmarks |
| `contact_messages` | `name`, `email`, `message`, `is_read` | Public contact form submissions |

---

## User Roles & Access

| Role | Redirects to after login | Key pages |
|---|---|---|
| `seeker` | `/seeker/profile.php` | profile, applications, saved_jobs |
| `employer` | `/employer/company_profile.php` | company_profile, post_job, manage_listings, view_applicants |
| `admin` | `/admin/dashboard.php` | dashboard, users, listings, messages |

---

## Page Reference

### Public pages (no login required)

| Page | Description |
|---|---|
| `index.php` | Landing page with hero section, up to six live featured job listings from the database, and CTA |
| `login.php` | Login form; redirects based on role after success |
| `register.php` | Account registration for seekers or employers |
| `jobs.php` | Browsable/filterable list of active job listings |
| `job_detail.php` | Full details for a single listing; apply button for seekers |
| `about.php` | Company mission, values, and team members |
| `contact.php` | Public contact form that saves to `contact_messages` |

### Protected pages

| Page | Role(s) | Description |
|---|---|---|
| `seeker/profile.php` | seeker | Edit personal details, headline, skills, and upload CV |
| `seeker/applications.php` | seeker | View all submitted applications and their current status |
| `seeker/saved_jobs.php` | seeker | View and manage bookmarked job listings |
| `employer/company_profile.php` | employer | Create or edit the employer's company profile |
| `employer/post_job.php` | employer | Post a new job listing |
| `employer/manage_listings.php` | employer | Edit, close, or delete own job listings |
| `employer/view_applicants.php` | employer | Review applicants for a specific listing |
| `admin/dashboard.php` | admin | Site-wide statistics overview |
| `admin/users.php` | admin | Activate or deactivate user accounts |
| `admin/listings.php` | admin | View and moderate all job listings |
| `admin/messages.php` | admin | Read and manage contact form submissions |
| `download.php` | seeker, employer, admin | Secure CV file download with path-traversal protection |

---

## JavaScript Modules

| File | Key exports / behaviour |
|---|---|
| `validation.js` | `validateForm()`, `showError()`, `clearErrors()` — reusable client-side form validation |
| `filter.js` | Live job card filtering using `data-` attributes; no page reload required |
| `application.js` | Apply form toggle with `aria-expanded` management and smooth scroll |
| `charcount.js` | Real-time character counter bound via `data-charcount` and `data-maxlength` attributes |
| `admin_stats.js` | Animated number counter using `requestAnimationFrame` triggered by `IntersectionObserver` |

---

## Core Includes Reference

| File | Key function(s) |
|---|---|
| `includes/db.php` | `getConnection(): PDO` — returns a shared PDO singleton |
| `includes/auth.php` | `isLoggedIn()`, `getUserRole()`, `requireRole(array $roles)` — session checks and role guards |
| `includes/helpers.php` | `sanitise()`, `redirect()`, `setFlash()`, `getFlash()` — output escaping and flash messaging |
| `includes/csrf.php` | `generateCsrfToken()`, `validateCsrfToken()` — synchroniser-token CSRF protection |

---

## Security Features

| Threat | Mitigation |
|---|---|
| CSRF | Synchroniser tokens via `random_bytes(32)`; all POST forms include a hidden token validated with `hash_equals()` |
| Password storage | bcrypt via `PASSWORD_BCRYPT`; plain text, MD5, and SHA-1 are never used |
| Session fixation | `session_regenerate_id(true)` called immediately on successful login |
| XSS | All output passed through `sanitise()` → `htmlspecialchars(ENT_QUOTES, 'UTF-8')` |
| SQL injection | PDO prepared statements throughout; no string concatenation in queries |
| File upload abuse | MIME type checked with `finfo`, extension whitelist enforced, 2 MB cap, random filename generated, stored outside web root |
| Path traversal | `realpath()` used in `download.php` with a directory whitelist check before serving any file |
| HTTP security headers | CSP, `X-Frame-Options`, `X-Content-Type-Options`, HSTS, and `Referrer-Policy` set via `.htaccess` |
| Directory listing | `/includes/` and `/sql/` blocked at the HTTP level via `.htaccess` |

---

## Known Configuration Notes

- **`config.php` is gitignored** — every developer must create their own copy from `config.example.php`. Never commit real credentials.
- **HTTPS redirect** — the redirect rule in `.htaccess` is commented out by default. Follow the [HTTPS Setup](#https-setup) section to obtain a certificate via Certbot, then uncomment it.
- **Session cookie hardening** — `HttpOnly` and `SameSite=Lax` flags are applied via `php_flag` directives in `.htaccess`.
