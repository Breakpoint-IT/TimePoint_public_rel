# 📌 TimePoint Changelog

All notable changes to this project will be documented in this file.  
This project follows a structured versioning approach.

---

## 🚧 [1.0.3] - In Progress

### ✨ Improvements
- Browser extension bug fixes and preparation for first public release  
- Legal notice (Imprint) and privacy policy can now be edited directly in the browser  

### 🏗️ Structural Changes
- Folder structure optimization and cleanup  
- Removed legacy files  

---

## ✅ [1.0.2]

### ✨ Features
- SMTP configuration added to settings  
- "Forgot Password" functionality  
- Full Docker implementation  
- PDF / PDF-A email export to one or multiple employees via supervisor interface  

### 🔐 Security
- Audit log system (tamper-proof with hash chain validation)  
- Audit validation feature  

### 📤 Export & Backup
- Audit log export (CSV, JSON)  
- `manage.sh` script for Docker management and backups  
  - `backup.sh` for SQL backups (database + audit log)  
  - Start / Restart / Stop scripts for container control  

### 🧪 Testing
- Verified functionality in Docker and native environments  

---

## ✅ [1.0.1]

### ✨ Features
- Employees can change their passwords  
- Admins can edit employee names  
- Enforced password change on first login (optional)  
- PDF/A export for long-term archiving  

### ⚙️ Improvements
- Developer info section (To-Do + Changelog)  
- Legal notice and privacy policy toggle in admin settings  
- Database import/export moved to admin section  

---

## 🎉 [1.0.0] - Initial Release

### ✨ Features
- Initial public release  
- Automatic break deduction  
- PDF generation with color highlighting:
  - Vacation  
  - Public holidays  
  - Sick leave  

### 🐛 Fixes
- Fixed Dark Mode loading and display issues  

### 🎨 UI Improvements
- Dashboard now shows:
  - Used vacation days  
  - Remaining vacation days  
  - Sick days  
- Employees are displayed by name  
- Added label: **"Current working time:"**  
- Dropdown menu integrated into employee name  

---

# 🚀 Planned Features

## 💾 Backup Strategy
- Automated backups (daily, weekly, monthly)  
- Storage options:
  - AWS S3  
  - Google Cloud Storage  
  - Azure Blob Storage  
  - NAS  
- Backup versioning  

## 📱 Mobile App (iOS & Android API)
- Push notifications:
  - Not clocked in  
  - Working time exceeded without clock-out  
  - Clock-in / Clock-out events  
- Geofencing (optional, configurable by supervisors)  

## 📅 Core Features
- Vacation planner  
- Database structure optimization  
- LDAP functionality testing  
- User profile images  
- Language fixes (missing variables)  
- Bug tracker implementation  

---

# 🛣️ Roadmap

## 🔐 Security
- Two-factor authentication (2FA)  
  - Authenticator apps  
  - Email  

## 📊 Shift Planning
- Supervisors can create schedules  
- Employees can apply for shifts  
- Employees can decline shifts with a reason (e.g., doctor’s appointment)  
- Employees can mark themselves as unavailable  
- Automatic scheduling based on availability  

## ⏱️ Time Tracking
- Project-based time tracking  

---
