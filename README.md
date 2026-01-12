BigSQL Importer (PHP)
BigSQL Importer is a lightweight, AJAX-based PHP utility designed to safely import very large MySQL and MariaDB .sql or .sql.gz dump files without running into PHP execution timeouts, memory limitations, or syntax errors from incomplete query splits. This tool is especially useful on shared hosting, XAMPP, cPanel, or any PHP environment where phpMyAdmin or similar tools fail to handle massive database imports (up to 10GB tested).
The system processes SQL dumps in intelligent chunks, ensuring "atomic" query execution (no mid-query splits), continues on errors to insert all valid data, and provides real-time monitoring with a professional dashboard.
Features

Secure Database Authentication: Built-in login screen to securely enter and verify database credentials before starting an import session.
Large File Support: Handles uploads and imports up to 10GB, with support for .sql and compressed .sql.gz files (CSV mentioned in UI but not fully processed as SQL).
Chunked Processing: Reads and executes SQL in batches (~3000 lines per AJAX request) to avoid timeouts, with atomic breaking to prevent syntax errors on multi-line queries.
Real-Time Progress Dashboard: AJAX-driven UI with progress bar, live stats (queries executed, errors, lines processed, completion %), and a terminal-style console log for batch updates.
Error Handling & Logging: Implements "continue on error" logic to skip invalid queries without halting the import. Detailed server-side logging (including table context, line numbers, error codes, and query snippets) in a session-specific .txt file. Browser console shows summarized errors.
Post-Import Statistics: Displays final database table stats (name, row count, size in MB) after successful completion.
Fault Tolerance: Handles delimiter changes (e.g., for stored procedures), skips comments, detects table contexts for better logging, and resumes from exact file offsets.
Professional UI: Modern, responsive design with card layouts, stats grid, and a contribution link. Includes logout/disconnect functionality.
No Dependencies: Pure PHP with mysqli; no external frameworks required.
Compatible Environments: Works on shared hosting, localhost (XAMPP/WAMP), and production servers.

Project Structure
textbigsql-importer/
│
├── bigsql-importer.php
│   Frontend GUI: Handles database login, file upload, file selection, and import dashboard.
│
├── bigsql-importer-process-ajax.php
│   Backend AJAX processor: Core logic for chunked SQL execution, error handling, and state management.
│
├── README.md
│   This documentation file.
│
└── LICENSE
    MIT License text.
Note: Uploaded SQL files and log files are stored in the same directory as the scripts.
Requirements

PHP 7.0 or higher (PHP 8.x fully supported; tested up to PHP 8.3).
MySQL 5.7+ or MariaDB 10.0+.
mysqli PHP extension enabled (standard in most installations).
Apache, Nginx, or any web server with PHP support.
Writable directory for uploads and logs (chmod 755 or 777 recommended).
Browser with JavaScript enabled for AJAX functionality.

Installation and Setup
Step 1: Copy Files
Place the project files in your web root or a subdirectory, e.g.:

Localhost: /xampp/htdocs/bigsql-importer/
Shared Hosting: /public_html/bigsql-importer/

Step 2: Configure PHP Settings
For handling large files, update your php.ini (or via .htaccess/user.ini on shared hosting):
textupload_max_filesize = 10G
post_max_size = 10G
max_execution_time = 0    ; Unlimited, or set high (e.g., 3600)
memory_limit = 1024M      ; Or higher for massive files
max_input_time = 3600
Restart your web server (Apache/Nginx) after changes. On shared hosting, confirm limits via phpinfo().
Step 3: Access the Tool
Open in your browser:
texthttp://localhost/bigsql-importer/bigsql-importer.php
(or your domain equivalent).

Enter database credentials on the login screen.
Upload your .sql or .sql.gz file.
Select the file from the list and start the import.

No additional database configuration is needed in the code—credentials are handled dynamically via the UI.
How It Works

Database Login: Enter host, database, user, and password. The tool verifies the connection and starts a secure session.
File Upload: Upload your SQL dump (supports .sql, .sql.gz; up to 10GB). Files are stored temporarily in the script directory.
File Selection: View available files with details (size, modified date, type) and start import with confirmation.
Import Process:
The backend reads the file line-by-line in chunks.
Builds complete queries (handling multi-line inserts, delimiters, comments).
Executes via mysqli, catching errors but continuing to process valid data.
AJAX polls update the dashboard in real-time: progress bar, stats, console logs.
Pauses/resumes batches to stay under time limits (25s per request).

Completion: Shows success screen with error count, log file name, and table statistics. Download the log for details.
Logout: Disconnects the session and clears credentials.

The tool uses session-based state for resumability within the same browser session but does not support pausing across sessions.
Security Recommendations
This tool provides direct database write access—use only in trusted, non-public environments.

Protect Access: Use .htaccess with basic auth or IP whitelisting:textAuthType Basic
AuthName "Restricted Area"
AuthUserFile /path/to/.htpasswd
Require valid-user
Restrict Directory: Deny public listing with .htaccess: Options -Indexes.
Remove After Use: Delete the scripts and uploaded files from production servers.
Session Security: Sessions are used for auth and state; ensure PHP session settings are secure (e.g., session.cookie_httponly=1).
Input Sanitization: Filenames are sanitized; credentials are used directly in mysqli (prepared internally).
Avoid Public Exposure: Do not host publicly without authentication layers.

Tested Environments

XAMPP/WAMP on Windows/Linux/macOS.
Shared hosting with cPanel/DirectAdmin (e.g., HostGator, Bluehost).
Apache 2.4+ and Nginx 1.18+.
PHP 7.4–8.3.
MySQL 5.7–8.0 and MariaDB 10.4–11.0.
Browsers: Chrome, Firefox, Edge (modern versions).

Common Use Cases

Importing massive production database dumps (e.g., eCommerce sites, forums).
Migrating databases between servers or from localhost to live.
Restoring backups that exceed phpMyAdmin limits (e.g., 50MB+).
Handling extended inserts, stored procedures, or views in large SQL files.
Debugging imports with detailed, table-specific error logs.

Troubleshooting

Upload Fails: Check php.ini limits and directory permissions. Error codes are shown in UI.
Connection Errors: Verify credentials; ensure mysqli extension is loaded.
Timeout Issues: Increase max_execution_time; tool already mitigates with chunking.
Syntax Errors: Atomic processing prevents splits—logs will show exact issues.
GZIP Issues: Ensure file is valid .sql.gz; tool uses gzopen/gzseek.
No Stats: If "SHOW TABLE STATUS" fails, check DB privileges.
CSV Support: UI allows upload, but processing treats as SQL—use for SQL-like CSV imports only.

For bugs, check browser console and server logs.
License
This project is licensed under the MIT License. See the LICENSE file for details.
Author
Shudhanshu Kumar Pandey

Year: 2026
Version: 2.1.0 Pro

Contributions
Welcome! Fork the repository, make improvements (e.g., CSV parsing, multi-file support, better compression handling), and submit a pull request. For donations: Contribute via Razorpay.
Disclaimer
This software is provided "AS IS" without warranty. Always backup your database before importing. Use at your own risk. The author is not responsible for data loss or corruption.
