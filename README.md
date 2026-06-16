# CUEA Final Year Project Management System (CUEA FYPM)

>>
> A web-based platform designed to streamline the full lifecycle of final year capstone projects — from proposal submission and supervisor allocation through milestone tracking to final submission.

---

## Table of Contents

- [Project Overview](#project-overview)
- [Key Features](#key-features)
- [Technology Stack](#technology-stack)
- [System Roles](#system-roles)
- [Directory Structure](#directory-structure)
- [Prerequisites](#prerequisites)
- [Installation & Setup](#installation--setup)
- [Environment Configuration](#environment-configuration)
- [Database Setup](#database-setup)
- [Default Credentials](#default-credentials)
- [API Endpoint Reference](#api-endpoint-reference)
- [Security Notes](#security-notes)
- [Known Limitations & Future Work](#known-limitations--future-work)
- [Author](#author)

---

## Project Overview

The CUEA FYPM replaces the department's fragmented manual process — Google Forms, WhatsApp threads, and physical logbooks — with a centralized, role-aware web application. It is built on a **vanilla technology stack** (HTML5 / CSS3 / JavaScript + PHP 8.x / MySQL) in compliance with CUEA's Final Year Project institutional guidelines.

The system is **cohort-based**: the Coordinator defines an academic cycle (e.g., *Class of 2026*), creates student and supervisor accounts, and sets global milestones. Students then browse real-time supervisor availability, submit ranked preferences, and track their project progress through an interactive dashboard. Supervisors review submissions, leave feedback, and create sub-milestones tailored to their supervisees.

---

## Key Features

| # | Feature | Description |
|---|---------|-------------|
| i | **Role-Based Access Control** | Separate authenticated portals for Students, Supervisors, and the Coordinator |
| ii | **Online Proposal Submission** | Students upload PDF/Word documents or submit typed text via a standardized form with MIME and size validation |
| iii | **Automated Proposal Workflow** | Sequential status transitions: `Draft → Supervisor Review → Coordinator Review → Approved / Revision Required` |
| iv | **Supervisor Selection Module** | Students rank up to 5 preferred supervisors; real-time capacity bars show availability; final assignment is made manually by the Coordinator |
| v | **Personalized Dashboards** | Each role sees contextually relevant data: pending actions, milestones, supervisee lists, or system-wide reports |
| vi | **Milestone & Deliverables Engine** | Coordinator-defined cohort milestones plus supervisor-created sub-milestones; 7-day and 1-day automated reminders |
| vii | **Versioned Submissions** | Every upload is timestamped and versioned — complete audit trail replacing the physical logbook |
| viii | **Centralized Document Repository** | Role-restricted file downloads; files stored outside the webroot |
| ix | **Dual-Channel Notifications** | On-screen sidebar badge + email via PHPMailer (SMTP) |
| x | **Coordinator Reporting** | Workload distribution, submission rates, and per-cohort progress overviews |
| xi | **Responsive Design** | Fully functional on desktop, tablet, and mobile |

---

## Technology Stack

| Layer | Technology |
|-------|-----------|
| **Frontend** | HTML5, CSS3, Vanilla JavaScript (ES6+) |
| **Backend** | PHP 8.x (RESTful API endpoints) |
| **Database** | MySQL 8.x — normalized to 3NF |
| **Local Dev Server** | XAMPP (Apache + MySQL + PHP) |
| **Email** | PHPMailer via SMTP |
| **File Storage** | Server filesystem (`/uploads/`) — served through guarded PHP endpoints |
| **Design / Wireframing** | Figma |

---

## System Roles

```
Student       → Submit proposals, rank supervisors, upload milestone deliverables, view feedback
Supervisor    → Review submissions, grade deliverables, create sub-milestones for supervisees
Coordinator   → Create cohorts & accounts, define milestones, finalize allocations, view reports
```

---

## Directory Structure

```
cuea-fypm/
│
├── css/                            # Stylesheets
│   ├── auth.css                    # Login & registration page styles
│   ├── dashboard.css               # Dashboard layout and component styles
│   ├── style.css                   # Global stylesheet — CSS variables, resets, shared components
│   └── supervisor.css              # Supervisor selection page styles
│
├── cuea_logo.png/
│   └── screen.png                  # CUEA logo asset
│
├── includes/                       # PHP backend utilities (not publicly accessible)
│   ├── audit.php                   # Audit logging helpers
│   ├── auth_check.php              # API middleware — validates session role on every request
│   ├── config.php                  # Application configuration loader
│   ├── db.php                      # PDO singleton database connection
│   ├── env.php                     # .env file parser
│   ├── helpers.php                 # Shared functions: jsonResponse(), sanitize()
│   ├── mailer.php                  # PHPMailer SMTP wrapper for notifications
│   ├── page_guard.php              # Server-side guard for PHP view pages
│   ├── schema.php                  # Schema validation helpers
│   └── sidebar.php                 # Role-aware sidebar renderer (PHP include)
│
├── js/                             # Client-side JavaScript modules
│   ├── auth.js                     # Login form handler, session redirects
│   ├── carousel.js                 # UI carousel component
│   ├── communications.js           # Messages & feedback page logic
│   ├── coordinator.js              # Coordinator portal — tabs, forms, allocation logic
│   ├── dashboard-interactive.js    # Enhanced dashboard interactions
│   ├── dashboard.js                # Student dashboard — data fetching and rendering
│   ├── deliverables.js             # Milestone submission forms and status rendering
│   ├── edit-profile.js             # Profile update form handler
│   ├── global.js                   # Inactivity tracker, fetch interceptor, logout handler
│   ├── my-project.js               # Project info panel logic
│   ├── schedule.js                 # Schedule page — list view + FullCalendar integration
│   ├── session-guard.js            # Cross-tab session consistency guard
│   ├── sidebar.js                  # Logout button listener
│   ├── student-common.js           # Shared session bootstrap for student pages
│   ├── supervisor-selection.js     # Supervisor browsing, filtering, and preference submission
│   └── supervisor.js               # Supervisor portal — tabs, submissions, sub-milestones
│
├── php/
│   └── api/                        # RESTful PHP API endpoints
│       ├── auth.php                # POST login | POST logout | GET session | POST update_profile
│       ├── coordinator.php         # Coordinator-only: users, cohorts, milestones, allocations
│       ├── dashboard.php           # Student dashboard data aggregation
│       ├── notifications.php       # Lazy overdue check; notification reads
│       ├── profile.php             # GET/POST profile for all roles
│       ├── student_supervisors.php # Supervisor availability, preference submission, change requests
│       ├── submissions.php         # Student milestone submission (file upload + text)
│       └── supervisor_api.php      # Supervisor: supervisees, grading, sub-milestones
│
├── uploads/                        # User-uploaded files (served via guarded endpoints)
│   └── ...                         # submission_{id}_{timestamp}_{filename}
│
├── .env                            # Environment variables (⚠️ never commit to version control)
│
│   — PHP View Pages —
├── index.html                      # Login / landing page
├── dashboard.php                   # Student dashboard (requires supervisor assigned)
├── deliverables.php                # Milestone submissions page (student)
├── communications.php              # Feedback & messages page (student)
├── schedule.php                    # Calendar & deadline view (student)
├── supervisor-selection.html       # Supervisor browsing & preference ranking (student)
├── pending-supervisor.php          # Holding page while awaiting coordinator allocation
├── supervisor-dashboard.php        # Supervisor portal (tab-based)
├── coordinator.php                 # Coordinator admin hub (tab-based)
├── edit-profile.php                # Profile editor (all roles)
├── reset-password.php              # Password reset flow
│
│   — Database & Utility Scripts —
├── schema.sql                      # Full MySQL schema with seed data
├── alter_db.php                    # One-time migration: adds supervisor_id to milestones
├── alter_schema.php                # One-time migration: nullable file_path, student_text, etc.
├── reset_admin.php                 # ⚠️ Dev utility: resets coordinator password
├── test_db.php                     # ⚠️ Dev utility: verifies DB connection and lists tables
└── 
```

---

## Prerequisites

- **PHP** 8.x or higher
- **MySQL** 8.x or higher
- **Apache** HTTP Server (XAMPP recommended for local development)
- **Composer** (optional — only if you add Composer-managed dependencies)
- An SMTP account for email notifications (Gmail App Password recommended)

---

## Installation & Setup

### 1. Clone or copy the project

```bash
git clone https://github.com/your-username/cuea-fypm.git
# or extract the project archive into:
# Windows: C:/xampp/htdocs/cuea-fypm/
# Linux:   /opt/lampp/htdocs/cuea-fypm/
```

### 2. Start your local server

Open the **XAMPP Control Panel** and start:
- ✅ Apache
- ✅ MySQL

### 3. Create the database

Open **phpMyAdmin** (`http://localhost/phpmyadmin`) and:

```sql
CREATE DATABASE cuea_fypm
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

Then import the schema:

```
phpMyAdmin → cuea_fypm → Import → select schema.sql → Go
```

Or via CLI:

```bash
mysql -u root -p cuea_fypm < schema.sql
```

### 4. Configure the environment

Copy the example env file and fill in your values:

```bash
cp .env.example .env
```

See [Environment Configuration](#environment-configuration) below for all required keys.

### 5. Set up the uploads directory

The `uploads/` folder must be writable by the web server:

```bash
# Linux
chmod 775 uploads/
chown www-data:www-data uploads/

# Windows (XAMPP) — no action needed; XAMPP runs as the current user
```

### 6. Access the application

Open your browser and navigate to:

```
http://localhost/cuea-fypm/
```

Log in with the default coordinator account (see [Default Credentials](#default-credentials)).

---

## Environment Configuration

Create a `.env` file in the project root. **Never commit this file to version control.**

```ini
# ─── Database ───────────────────────────────────────────────
DB_HOST=localhost
DB_NAME=cuea_fypm
DB_USER=root
DB_PASS=your_mysql_password
DB_CHARSET=utf8mb4

# ─── Application ────────────────────────────────────────────
APP_ENV=development          # development | production
APP_URL=http://localhost/cuea-fypm

# ─── Email (PHPMailer / SMTP) ───────────────────────────────
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=your_email@gmail.com
MAIL_PASSWORD=your_gmail_app_password
MAIL_FROM_ADDRESS=noreply@cuea.edu
MAIL_FROM_NAME="CUEA FYPM"

# ─── Session ────────────────────────────────────────────────
SESSION_LIFETIME=900         # seconds (15 minutes inactivity timeout)
```

> **Gmail App Password:** Go to Google Account → Security → 2-Step Verification → App Passwords. Generate a password for "Mail".

---

## Database Setup

The `schema.sql` file creates all eight tables and inserts seed data:

| Table | Purpose |
|-------|---------|
| `cohorts` | Academic intake groups with capacity limits |
| `users` | All actors (students, supervisors, coordinator) via role discriminator |
| `projects` | One project per student; anchor for all related data |
| `supervisor_preferences` | Ranked preference lists submitted by students |
| `milestones` | Cohort-wide and supervisor-specific deliverable checkpoints |
| `milestone_submissions` | Versioned student uploads and text submissions |
| `notifications` | Append-only alert log per user |
| `supervisor_change_requests` | Formal supervisor reassignment requests |

### One-time migrations (if upgrading from an older schema)

```bash
# Run in browser or via CLI after importing schema.sql
http://localhost/cuea-fypm/alter_db.php
http://localhost/cuea-fypm/alter_schema.php
```

> ⚠️ Delete or restrict access to `alter_db.php`, `alter_schema.php`, `reset_admin.php`, and `test_db.php` before deploying to production.

---

## Default Credentials

The schema seeds one default Coordinator account:

| Field | Value |
|-------|-------|
| **Email** | `admin@cuea.edu` |
| **Password** | `Admin@CUEA2024` |
| **Role** | Coordinator |

> 🔒 **Change this password immediately** after your first login via the Edit Profile page.

All other accounts (students and supervisors) must be created by the Coordinator through the **User Management** tab in the Coordinator portal.

---

## API Endpoint Reference

All API endpoints live under `php/api/` and return JSON.

| Endpoint | Method | Action | Role |
|----------|--------|--------|------|
| `auth.php` | POST | `login` | Public |
| `auth.php` | POST | `logout` | Any |
| `auth.php` | GET | `session` | Any |
| `auth.php` | POST | `update_profile` | Any |
| `coordinator.php` | GET | `get_cohorts` | Coordinator |
| `coordinator.php` | POST | `create_user` | Coordinator |
| `coordinator.php` | POST | `create_cohort` | Coordinator |
| `coordinator.php` | POST | `create_milestone` | Coordinator |
| `coordinator.php` | GET | `get_allocations` | Coordinator |
| `coordinator.php` | GET | `get_unassigned_projects` | Coordinator |
| `coordinator.php` | POST | `assign_supervisor` | Coordinator |
| `coordinator.php` | GET | `get_overview_data` | Coordinator |
| `dashboard.php` | GET | *(aggregate)* | Student |
| `dashboard.php` | POST | `update_project_details` | Student |
| `student_supervisors.php` | GET | `get_available` | Student |
| `student_supervisors.php` | POST | `submit_preferences` | Student |
| `student_supervisors.php` | POST | `request_change` | Student |
| `student_supervisors.php` | GET | `change_request_status` | Student |
| `submissions.php` | POST | `submit_milestone` | Student |
| `submissions.php` | GET | `get_submissions` | Student / Supervisor |
| `supervisor_api.php` | GET | `get_supervisees` | Supervisor |
| `supervisor_api.php` | GET | `get_submissions` | Supervisor |
| `supervisor_api.php` | POST | `grade_submission` | Supervisor |
| `supervisor_api.php` | GET | `get_cohorts` | Supervisor |
| `supervisor_api.php` | GET | `get_parent_milestones` | Supervisor |
| `supervisor_api.php` | POST | `create_sub_milestone` | Supervisor |
| `profile.php` | GET | `get_profile` | Any |
| `profile.php` | POST | `update_profile` | Any |
| `notifications.php` | POST | `lazy_overdue_check` | Any |

---

## Security Notes

| Threat | Mitigation |
|--------|-----------|
| SQL Injection | PDO prepared statements on all database queries |
| XSS | `htmlspecialchars()` + `strip_tags()` on all user input |
| Unauthorized File Access | Uploads stored outside webroot; served via role-checked PHP stream |
| CSRF | `X-Requested-With` header validation on POST endpoints |
| Session Hijacking | `session_regenerate_id(true)` on login; `Secure` + `HttpOnly` cookie flags |
| Role Escalation | `auth_check.php` validates `$_SESSION['role']` on every API request |
| File Upload Attacks | MIME-type whitelist (PDF, DOCX); 10 MB size cap; timestamped filename on server |
| Inactivity | 15-minute client-side inactivity timer triggers auto-logout |

### Production Checklist

- [ ] Set `APP_ENV=production` in `.env`
- [ ] Set `display_errors = Off` in `php.ini`; log errors to file
- [ ] Enable HTTPS (required for `Secure` cookie flag)
- [ ] Change default coordinator password
- [ ] Set `/uploads/` permissions to `700`
- [ ] Delete or block access to: `reset_admin.php`, `test_db.php`, `alter_db.php`, `alter_schema.php`, `context.py`
- [ ] Configure PHPMailer SMTP credentials in `.env`
- [ ] Set up a daily cron job for milestone email reminders

---

## Known Limitations & Future Work

- **Manual allocation step:** The Coordinator still makes the final supervisor assignment; a fully automated matching algorithm is deferred to a future release.
- **No self-registration:** All accounts are provisioned by the Coordinator. A CSV bulk-import utility is planned.
- **Email reminders require cron:** The notification engine fires lazily on page load; a scheduled cron job is needed for reliable email delivery.
- **Planned enhancements:**
  - Bulk user import via CSV
  - OTP/two-factor authentication
  - AI-assisted plagiarism detection on submissions
  - Native iOS / Android mobile app
  - API bridge with the CUEA student portal for automated onboarding

---

## Author

**Jesse Chomba** 