# TimePoint Changelog

## Release 1.0.4 - In Progress

- [ ] Weekly, Monthly, Quarterly, Yearly calendar views inside the dashboard.
- [ ] Browser extension bug fixes and first public release.
- [ ] Mobile app API development for iOS and Android.
  - [ ] Push notifications when a user has not clocked in or has exceeded the target working time without clocking out.
  - [ ] Push notifications when clocking in and out.
  - [ ] Geofencing: clocking in and out only within the company area. Supervisors can enable this, for example when an employee does not have a home-office agreement.

## Release 1.0.3

- [x] Demo Mode implemented.
- [x] Language fixes: some variables are not defined yet.
- [x] Database optimization: SQLite was removed. PostgreSQL and MariaDB are now the supported database options.
- [x] Admin migration from SQLite to the new databases.
- [x] Database setup via `setup.php` and integration of the former registration flow.
- [x] Imprint and privacy policy pages can now be edited directly in the browser.
- [x] Structural improvements.
  - [x] Folder structure adjusted and optimized.
  - [x] Old files removed.

## Release 1.0.2

- [x] SMTP implementation in the settings.
- [x] Password reset feature.
- [x] Complete Docker implementation.
- [x] PDF and PDF/A email delivery to one or more employees from the supervisor export view.
- [x] Audit log: tracks who changed what and prevents later manipulation without a log entry, including hash-chain verification.
- [x] Audit verification implemented.
- [x] Audit log export feature: CSV and JSON.
- [x] `manage.sh` script for Docker container management and backup functions for the database and audit log.
  - [x] `backup.sh` script for creating SQL backups of the database and audit log.
  - [x] Start, restart, and stop scripts for simple Docker container management.
- [x] Functional verification with Docker and native environments.

## Release 1.0.1

- [x] Employees can change their password.
- [x] Administrators can change employee names.
- [x] Administrators can require new employees to change their password on first login.
- [x] PDF/A export for long-term archiving added.
- [x] Developer information with to-dos and changelog information.
- [x] Imprint and privacy policy links can now be shown or hidden separately in the admin settings.
- [x] Database export and import moved from settings to the admin area.

## Release 1.0.0

- [x] First public release.
- [x] Numerous bug fixes, improvements, and core revisions.
- [x] Automatic break deduction.
- [x] PDF generation with color highlighting for vacation, holidays, and sick leave.
- [x] Dark mode loading and display issues fixed.
- [x] UI improvements.
  - [x] Dashboard now shows used vacation, remaining vacation, and sick days.
  - [x] Each employee is now addressed by name.
  - [x] The timer now shows the label "Current working time:" before the clocked time.
  - [x] Dropdown menu integrated into the employee name.

## Planned Features

- [ ] Backup strategy.
  - [ ] Automatic backup job: daily, weekly, monthly.
  - [ ] Backup storage locations: cloud storage such as AWS S3, Google Cloud Storage, Azure Blob Storage, or NAS.
  - [ ] Backup versioning.
- [ ] Vacation planner.
- [ ] LDAP function test.
- [ ] Bug tracker implementation.

## Roadmap

- [ ] 2FA support: authenticator app, email, or similar.
- [ ] Shift planning.
  - [ ] Supervisors can create predefined shift schedules.
  - [ ] Employees can apply for open shifts.
  - [ ] Employees can decline shifts with a reason, for example a doctor's appointment, and supervisors can adjust the schedule.
  - [ ] Employees can mark themselves unavailable for shifts in advance, for example because of a doctor's appointment.
  - [ ] Automatic shift schedule creation based on employee availability.
- [ ] Project time tracking.
- [ ] Users can add images.
