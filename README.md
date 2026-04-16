# Digital Union - LGDHAKA

> **Local Government Digital Union Management System**
>
> A comprehensive web application for managing municipal certificates and digital services in Dhaka, Bangladesh.

[![Repository](https://img.shields.io/badge/Repository-GitHub-blue)](https://github.com/habibbrox2/digital-union)
[![License](https://img.shields.io/badge/License-MIT-green)](#license)
[![Language](https://img.shields.io/badge/Language-PHP%208.2-purple)](https://www.php.net/)
[![Database](https://img.shields.io/badge/Database-MariaDB%2010.4-orange)](https://mariadb.org/)

## 📋 Features

### Core Functionality
- ✅ **Application Management** - Submit and track certificates (trade licenses, birth certificates, etc.)
- ✅ **User Authentication** - Secure login with roles and permissions
- ✅ **Admin Dashboard** - Monitor applications and generate reports
- ✅ **Multi-language Support** - English and Bengali interfaces
- ✅ **Document Management** - Upload and verify supporting documents

### Recent Enhancements (April 2026)
- 🆕 **Trade License Expiry Detection** - Automatic watermarking of expired certificates
- 🆕 **Auto-populate Forms** - Name fields automatically sync with summary section
- 🆕 **Database Integrity** - Foreign key constraints and data validation
- 🆕 **Migration System** - Safe database updates with rollback capability

## 🔧 Technology Stack

| Component | Version | Details |
|-----------|---------|----------|
| **PHP** | 8.2.12+ | Server-side logic |
| **Database** | MariaDB 10.4.32 | Data persistence |
| **Templating** | Twig 3.x | Template engine |
| **Framework** | Custom MVC | Lightweight architecture |
| **Frontend** | Bootstrap 5.x | Responsive UI |
| **Authentication** | Role-Based Access Control | Admin/User/Super Admin levels |

## 📋 Requirements

### Minimum Requirements
- **PHP** 8.0 or higher (tested on 8.2.12)
- **MariaDB** 10.4+ or **MySQL** 5.7+
- **Composer** for dependency management
- **Web Server**: Apache 2.4+ or Nginx 1.18+
- **PHP Extensions**: mysqli, gd, json, xml, mbstring

### System Requirements
- 512MB RAM (minimum)
- 100MB disk space
- OpenSSL support

## ⚡ Quick Start

### 1. Clone Repository
```bash
git clone https://github.com/habibbrox2/digital-union.git
cd digital-union
git checkout main
```

### 2. Install Dependencies
```bash
composer install
```

### 3. Environment Setup
```bash
cp .env.example .env
# Edit .env with your database credentials
```

### 4. Database Configuration
```bash
# Create database and import schema
mysql -u root -p < tdhuedhn_lgdhaka.sql

# Or run migrations
php migrate.php
```

### 5. Configure Web Server

**Apache (.htaccess included):**
```apache
<VirtualHost *:80>
    ServerName yourdomain.com
    DocumentRoot /path/to/public
    <Directory /path/to/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

**Nginx:**
```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /path/to/public;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
}
```

### 6. Set Permissions
```bash
chmod -R 755 public/
chmod -R 775 storage/
chmod 644 public/index.php
```

## 📚 Documentation

- **[QUICK_START.md](QUICK_START.md)** - Detailed setup instructions
- **[AUDIT_AND_REPAIR_REPORT.md](AUDIT_AND_REPAIR_REPORT.md)** - Database audit findings
- **[TRADE_LICENSE_EXPIRY_FEATURE.md](TRADE_LICENSE_EXPIRY_FEATURE.md)** - Trade license validation

## 🗄️ Database Structure

### Core Tables
- `users` - User accounts (29 users in demo)
- `applications` - Certificate applications (225+ records)
- `business_meta` - Trade license details
- `address` - Address information
- `application_approvals` - Approval workflow
- `roles` - User role definitions
- `permissions` - Permission mappings

### Database Statistics (Current)
- **Total Tables**: 29
- **Application Records**: 225
- **Database Size**: ~2.5 MB

## 🚀 Deployment

### Production Checklist
- [ ] Set `APP_ENV=production` in `.env`
- [ ] Set `APP_DEBUG=false` in `.env`
- [ ] Enable HTTPS/SSL
- [ ] Configure backups (daily)
- [ ] Set up error logging
- [ ] Configure email service
- [ ] Review security headers
- [ ] Test database migrations

### Backup Strategy
```bash
# Automated daily backup
mysqldump -u root -p tdhuedhn_lgdhaka > backups/db_$(date +%Y%m%d_%H%M%S).sql

# Or use the built-in migration system
php migrate.php
```

## 🔐 Security Features

- ✅ CSRF token protection
- ✅ SQL injection prevention (prepared statements)
- ✅ Password hashing (bcrypt)
- ✅ Role-based access control
- ✅ Input validation and sanitization
- ✅ XSS protection
- ✅ Foreign key constraints

## 🛠️ Development

### File Structure
```
├── classes/          # Business logic classes
├── config/           # Configuration files
├── controllers/      # Request handlers
├── helpers/          # Utility functions
├── public/           # Web root (index.php)
│   └── assets/       # CSS, JS, images
├── storage/          # Cache, logs, uploads
├── templates/        # Twig templates
└── vendor/           # Composer packages
```

### Running Tests
```bash
# Database integrity check
php migrate.php

# Check error logs
tail -f storage/logs/migrations.log
```

## 📊 Features Per Application Type

| Type | Fields | Documents | Workflow |
|------|--------|-----------|----------|
| Trade License | 15+ | 3-5 | Submission → Verification → Approval |
| Birth Certificate | 10+ | 2-3 | Submission → Verification → Issuance |
| Character Certificate | 8+ | 2-3 | Submission → Review → Approval |
| Citizenship | 12+ | 4-5 | Submission → Investigation → Approval |

## 🤝 Contributing

Contributions are welcome! Please follow these steps:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/improvement`)
3. Commit changes (`git commit -am 'Add feature'`)
4. Push to branch (`git push origin feature/improvement`)
5. Create a Pull Request

## 📝 Changelog

### Version 1.0.0 (April 2026)
- ✅ Initial release with core features
- ✅ Trade license expiry watermarking
- ✅ Auto-populate form fields
- ✅ Database integrity constraints
- ✅ Safety migration system

## 📧 Support

For issues and questions:
- Email: hrhabib.hrs@gmail.com
- GitHub Issues: [Create Issue](https://github.com/habibbrox2/digital-union/issues)

## 📄 License

This project is licensed under the MIT License. See LICENSE file for details.

## 🙏 Acknowledgments

- MariaDB for reliable database
- Twig for template engine
- Bootstrap for responsive framework
- Community contributors

---

**Last Updated**: April 16, 2026  
**Maintained By**: Hr Habib Brox  
**Repository**: https://github.com/habibbrox2/digital-union

## Contributing

Please read the audit reports and feature docs for more details.