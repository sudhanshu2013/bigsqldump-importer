BigSQL Importer (PHP)
BigSQL Importer is a lightweight, AJAX-based PHP utility designed to safely import very large MySQL and MariaDB .sql files without running into PHP execution timeouts or memory limitations.
This tool is especially useful on shared hosting, XAMPP, and cPanel environments where phpMyAdmin fails to handle large database imports.

Features

Imports large SQL files safely
AJAX-based chunk processing
Avoids PHP execution timeouts
Avoids memory exhaustion
MySQL and MariaDB compatible
No framework dependency
Works on shared hosting and localhost environments
Simple interface with background processing

Project Structure
bigsql-importer/
│
├── bigsql-importer.php
│   Main interface for uploading SQL files
│
├── bigsql-importer-process-ajax.php
│   AJAX processor that executes SQL in chunks
│
├── README.md
└── LICENSE

Requirements

PHP 7.0 or higher (PHP 8.x supported)
MySQL or MariaDB
mysqli extension enabled
Apache or Nginx web server
Writable upload directory

Installation and Setup
Step 1: Copy Files

Place the project in your web root directory:
/xampp/htdocs/bigsql-importer/

or

/public_html/bigsql-importer/

Step 2: Configure Database Connection
Edit database credentials in the following file:

bigsql-importer-process-ajax.php

Example:

$host = "localhost";
$user = "db_user";
$pass = "db_password";
$db   = "database_name";

Step 3: PHP Configuration

For large SQL files, adjust the following PHP settings in php.ini:

upload_max_filesize = 512M
post_max_size = 512M
max_execution_time = 0
memory_limit = 1024M

Restart your web server after making changes.

Step 4: Access the Tool
Open the following URL in your browser:
http://localhost/bigsql-importer/bigsql-importer.php

How It Works

A .sql file is uploaded through the interface.
The file is stored temporarily on the server.
The SQL file is read in small chunks.
Each chunk is executed using AJAX requests.
Processing continues until the entire file is imported.
This approach prevents server overload and timeout errors.

Security Recommendations
This tool provides direct database access and should be used only in trusted environments.

Recommended precautions:
Protect access using .htaccess or basic authentication
Restrict access by IP address where possible
Remove the tool after use on production servers
Do not expose this tool publicly without proper protection.

Tested Environments
XAMPP on Windows and Linux
Shared hosting with cPanel
Apache and Nginx servers
PHP 7.x and PHP 8.x
MySQL 5.7+ and MariaDB

Common Use Cases
Importing large production databases
Database migration between servers
Restoring large database backups
Handling SQL files that phpMyAdmin cannot process
Localhost to live server migration

License
This project is licensed under the MIT License.
See the LICENSE file for complete license text.

Author
Shudhanshu Pandey

Contributions
Contributions are welcome in the form of bug fixes, performance improvements, and security enhancements.
Fork the repository and submit a pull request.

Disclaimer
This software is provided "AS IS", without warranty of any kind.
Use it at your own risk.
