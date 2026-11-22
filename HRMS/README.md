# HRMS – Human Resource Management System (PHP + MySQL)

**Integrated with your PeopleFlow frontend design** (navy + purple sidebar, topbar, login screen, stat cards, panels and tables from your `style.css`). Your static HTML pages became PHP pages with the same look.

Admin and Staff panels: staff, attendance, leave, time permission, incentives, deductions, payroll and payslips, reports, settings.
Stack: core PHP 8+, MySQL/MariaDB (PDO, prepared statements). Bootstrap grid/utilities and Bootstrap Icons are bundled in `assets/vendor/`. Only the Google Fonts (DM Sans, Manrope) in your stylesheet need internet.

## 1. Install XAMPP
Install XAMPP (PHP 8.0 or newer) and open the **XAMPP Control Panel**.

## 2. Start Apache and MySQL
Click **Start** next to **Apache** and **MySQL**. Both should turn green.

## 3. Copy the project
Copy this whole folder to `C:\xampp\htdocs\HRMS\` so that `C:\xampp\htdocs\HRMS\login.php` exists.
(Another folder name also works, e.g. `htdocs\hr`. The web path is detected automatically.)

## 4. Import the database
1. Open http://localhost/phpmyadmin
2. Click the **Import** tab, choose `database/hrms_db.sql`, click **Import**.
   This creates the database `hrms_db` with all tables, 4 sample departments and 4 leave types. It creates **no users and no passwords**.

## 5. Open the project
http://localhost/HRMS/ (if your Apache uses port 8080: http://localhost:8080/HRMS/)

## 6. Database connection (`config/database.php`)
Default XAMPP is user `root` with an **empty** password, which is what the file already uses. If your MySQL differs, edit these lines:

```php
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');      // change if MySQL runs on another port
define('DB_NAME', 'hrms_db');
define('DB_USER', 'root');
define('DB_PASS', '');          // put your MySQL password here if you set one
```
Never share this file or commit your real password. The folders `config/`, `includes/`, `database/` and `uploads/` contain an `.htaccess` that blocks direct web access under Apache.

## 7. Create the first Admin (secure)
There is **no default password**.
1. Open http://localhost/HRMS/ (if your Apache uses port 8080: http://localhost:8080/HRMS/) – on a fresh database it opens `setup.php`.
2. Enter name, email and **your own password** (min 8 characters), then click **Create Admin account**.
3. `setup.php` works only while no Admin exists **and only from the same computer** (localhost). Afterwards it refuses to run; you may also delete the file.

## 8. Create staff accounts
Log in as Admin → **Staff → Add Staff**. Enter name, email, department (type any name, it is created automatically), salary and an initial password. Choose Role **Staff** (normal employee) or **Admin / HR**. Leave Staff ID blank to auto-generate it. To reset a password, edit the staff member and type a new one. Deactivating a staff member blocks login but keeps all attendance and payroll history.

## 9. Log in
- Admin: Admin email + password → Admin Panel.
- Staff: the email + password the Admin created → Staff Panel.

## Payroll rules
- `Gross Earnings = Base Salary + approved incentives of that month/year`
- `Net Salary = Gross Earnings − active deductions of that month/year`
- No PF, tax or other statutory deduction is applied automatically. Amounts are rounded half-up to 2 decimals.
- Generating payroll saves a **Draft** (one per staff per month; duplicates are impossible). Draft can be recalculated or deleted. **Approved** and **Paid** are locked, and incentives/deductions of that month can no longer change.
- Payslips are built from the saved payroll rows, so old payslips never change. Staff only see **Approved/Paid** payslips of their own.
- Attendance rules (Settings → Attendance): staff can work in different time slots, so there is **no Late status**. A check-in is Present; fewer worked hours than the half-day limit = Half day; weekly off days are not counted as absent.

## Security features
Hashed passwords (`password_hash`), session ID regenerated at login, session timeout, login lock after repeated failures, CSRF token on every POST, prepared statements everywhere, output escaping, role check on every page, staff limited to their own records, uploads limited to PDF/JPG/PNG (2 MB) and served only through `download.php` after a permission check.

## Troubleshooting
| Problem | Fix |
|---|---|
| "Database connection failed" | MySQL not started in XAMPP, or wrong `DB_USER`/`DB_PASS`/`DB_PORT` in `config/database.php`. |
| "database tables were not found" | Import `database/hrms_db.sql` (step 4). |
| Page not found / 404 | Folder must be inside `htdocs`, and the URL must match the folder name. |
| Page looks unstyled | No internet (CDN files blocked), or the folder was copied incompletely (`assets/css/style.css`). |
| Blank page | Set `APP_DEBUG` to `true` in `config/database.php` temporarily, or read `C:\xampp\php\logs\php_error_log` and `C:\xampp\apache\logs\error.log`. Set it back to `false` afterwards. |
| "Your form expired" | Session expired; refresh the page and try again. |
| `setup.php` says it is blocked | Open it from the XAMPP computer using `http://localhost/...`, and only before an Admin exists. |
| Uploads fail | Check the `uploads/documents/` folder exists and PHP `upload_max_filesize` is at least 2M. |
| Forgot Admin password | In phpMyAdmin run `UPDATE users SET password_hash = '<new hash>' WHERE email = '...'`, with a hash made by `php -r "echo password_hash('NewPass123', PASSWORD_DEFAULT);"` in the XAMPP shell. |

## Folder structure
```
HRMS/
  index.php  login.php  logout.php  setup.php  download.php
  config/database.php
  includes/  auth.php functions.php csrf.php header.php footer.php payroll_lib.php
  admin/     dashboard staff attendance leave timepermission incentives deductions payroll payslip reports settings
  staff/     dashboard profile attendance leave timepermission incentives deductions salary payslip settings
  assets/    css/style.css (your design + backend additions)  js/app.js  vendor/ (grid, utilities, icons)
  uploads/documents/
  database/hrms_db.sql
```

## Known limitations
- Differences from the demo frontend: the login page opens the panel that matches the account's real role (the Admin/Staff buttons are only a visual choice), a **My Deductions** page and an **Admin → Deductions** page were added to the sidebar, and demo-only things (sample data, localStorage, "Visit support" card) were removed.
- Automated checks were run on PHP 8.3 with MariaDB 10.11 using the PHP built-in server (not Apache/XAMPP itself); pages were screenshot-checked in headless Chromium at desktop width and the mobile menu was exercised. Please do a quick click-through on your XAMPP.
- Profile images are not supported. The audit log is stored in the database (`audit_logs`) but has no viewer page.
