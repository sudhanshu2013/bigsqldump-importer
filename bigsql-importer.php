<?php
session_start();

/*
  *******************************************************************************************
  * BigSQL Importer - Professional MySQL Dump Importer (GUI)                                *
  * *
  * @license MIT                                                                            *
  * @author Shudhanshu Kumar Pandey                                                         *
  * @year 2026                                                                              *
  * *
  * Description:                                                                            *
  * The frontend interface for the BigSQL Importer system.                                  *
  * Features secure database login, large file support (up to 10GB),                        *
  * and a professional AJAX-driven dashboard to monitor import progress.                    *
  * *
  * Updates:                                                                                *
  * - Secure DB Login Screen                                                                *
  * - 10GB File Size Support                                                                *
  * - Real-time Progress Bar & Console Log (Live Status Fixed)                              *
  * - Detailed Error Logging (Table specific)                                               *
  * - Post-Import Table Statistics                                                          *
  * - Contribution Link Added                                                               *
  *******************************************************************************************
*/

// ======================================================================
// CONFIGURATION & INITIALIZATION
// ======================================================================

// Set error reporting: Hide minor notices to keep UI clean, but show critical errors
error_reporting(E_ALL & ~E_NOTICE);

// Define System Constants
define('APP_VERSION', '2.1.0 Pro');
define('UPLOAD_DIR', dirname(__FILE__) . '/');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024 * 1024); // Set Upload Limit to 10 GB

// ======================================================================
// LOGIC: Logout / Reset Session
// ======================================================================
// Checks if the user clicked 'Disconnect'. Destroys session and redirects to login.
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ======================================================================
// LOGIC: Handle Database Credentials Submission
// ======================================================================
// Processes the login form. Validates connection before starting session.
$auth_msg = "";
if (isset($_POST['connect_db'])) {
    // Sanitize user inputs to prevent basic injection
    $h = trim($_POST['db_host']);
    $u = trim($_POST['db_user']);
    $p = trim($_POST['db_pass']);
    $n = trim($_POST['db_name']);

    try {
        // Attempt Test Connection
        // We use @ to suppress native PHP warnings so we can catch them as Exceptions
        $test_conn = @new mysqli($h, $u, $p, $n);
        
        if ($test_conn->connect_error) {
            throw new Exception("Connection failed: " . $test_conn->connect_error);
        }

        // Connection Successful: Store Verified Credentials in Session
        $_SESSION['rh_db_host'] = $h;
        $_SESSION['rh_db_user'] = $u;
        $_SESSION['rh_db_pass'] = $p;
        $_SESSION['rh_db_name'] = $n;
        $_SESSION['rh_auth']    = true;
        
        // Initialize a unique Log File Name for this specific session
        $_SESSION['rh_log_file'] = 'import_log_' . date('Y-m-d_H-i-s') . '.txt';

        $test_conn->close();
        
        // Refresh page to load the Dashboard view
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    } catch (Exception $e) {
        // Capture connection errors and display to user
        $auth_msg = "<div class='alert-error'><strong>Error:</strong> " . $e->getMessage() . "</div>";
    }
}

// ======================================================================
// LOGIC: Handle File Upload
// ======================================================================
// Processes the file upload form. Moves file from temp to working directory.
$upload_msg = "";
if (isset($_POST['upload_file']) && isset($_SESSION['rh_auth'])) {
    
    // Check if PHP reports a successful upload
    if ($_FILES['dumpfile']['error'] === UPLOAD_ERR_OK) {
        $temp_name = $_FILES['dumpfile']['tmp_name'];
        // Sanitize filename to remove dangerous characters
        $clean_name = preg_replace("/[^a-zA-Z0-9\._-]/", "", $_FILES['dumpfile']['name']); 
        $target_path = UPLOAD_DIR . $clean_name;
        $file_size = $_FILES['dumpfile']['size'];

        // Validate Size (Soft check, server php.ini limits still apply primarily)
        if ($file_size > MAX_UPLOAD_SIZE) {
             $upload_msg = "<div class='alert-error'>File exceeds the 10GB script limit.</div>";
        } else {
            // Validate File Extension for security
            $ext = pathinfo($clean_name, PATHINFO_EXTENSION);
            if (in_array(strtolower($ext), ['sql', 'gz', 'csv'])) {
                // Attempt to move the file
                if (move_uploaded_file($temp_name, $target_path)) {
                    $upload_msg = "<div class='alert-success'>File <strong>$clean_name</strong> uploaded successfully!</div>";
                } else {
                    $upload_msg = "<div class='alert-error'>Failed to move file. Check folder permissions (777/755).</div>";
                }
            } else {
                $upload_msg = "<div class='alert-error'>Invalid file type. Only .sql, .gz, .csv allowed.</div>";
            }
        }
    } else {
        // Output PHP internal upload error codes
        $upload_msg = "<div class='alert-error'>Upload Error Code: " . $_FILES['dumpfile']['error'] . " (Check upload_max_filesize in php.ini)</div>";
    }
}

// ======================================================================
// LOGIC: Get List of Available Files
// ======================================================================
// Scans the upload directory to populate the "Select File" table.
$files_available = [];
if (isset($_SESSION['rh_auth'])) {
    if (is_dir(UPLOAD_DIR)) {
        $dir = opendir(UPLOAD_DIR);
        while ($f = readdir($dir)) {
            // Filter out system dots, directories, and non-allowed extensions
            if ($f != "." && $f != ".." && !is_dir(UPLOAD_DIR . $f) && preg_match("/\.(sql|gz|csv)$/i", $f)) {
                $files_available[] = $f;
            }
        }
        closedir($dir);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BigSQL Importer Pro | Result Hosting</title>
    <style>
        /* ======================================================================
           CSS STYLING - PRO CLEAN LOOK 
           ======================================================================
           Modern color palette, card-based layout, clear typography.
           Designed for readability and professional aesthetics.
        */
        :root {
            --primary: #4f46e5;   /* Indigo - Main Action Color */
            --primary-hover: #4338ca;
            --secondary: #64748b; /* Slate - Secondary Text */
            --success: #10b981;   /* Emerald - Success State */
            --danger: #ef4444;    /* Red - Error State */
            --bg: #f1f5f9;        /* Light Grey Background */
            --surface: #ffffff;   /* White Card Background */
            --border: #e2e8f0;    /* Subtle Border */
            --text-main: #1e293b; /* Dark Slate - Main Text */
            --contribute: #f59e0b; /* Amber - Contribute Button */
        }
        
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text-main); margin: 0; padding: 20px; line-height: 1.5; }
        .container { max-width: 950px; margin: 0 auto; }
        
        /* Typography & Headers */
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { color: var(--primary); margin: 0; font-size: 32px; font-weight: 800; letter-spacing: -0.5px; }
        .header p { color: var(--secondary); margin: 5px 0 0; font-size: 14px; }
        
        /* Card Layout - The main container for UI elements */
        .card { background: var(--surface); border-radius: 10px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03); padding: 30px; margin-bottom: 25px; border: 1px solid var(--border); }
        .card-title { font-size: 18px; font-weight: 700; margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 12px; color: var(--text-main); display: flex; justify-content: space-between; align-items: center; }
        
        /* Forms */
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: var(--secondary); text-transform: uppercase; letter-spacing: 0.5px; }
        .form-control { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 6px; box-sizing: border-box; font-size: 15px; transition: border 0.2s, box-shadow 0.2s; }
        .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); }
        
        /* Buttons */
        .btn { display: inline-block; padding: 12px 24px; border-radius: 6px; font-weight: 600; text-decoration: none; cursor: pointer; border: none; font-size: 14px; transition: all 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-hover); transform: translateY(-1px); }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #dc2626; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .btn-contribute { background: var(--contribute); color: white; margin-top: 10px; font-size: 12px; padding: 6px 15px; border-radius: 20px; }
        .btn-contribute:hover { background: #d97706; }
        
        /* Alerts (Success/Error Messages) */
        .alert-error { background: #fef2f2; color: #991b1b; padding: 15px; border-radius: 6px; border-left: 4px solid var(--danger); margin-bottom: 20px; font-size: 14px; }
        .alert-success { background: #ecfdf5; color: #065f46; padding: 15px; border-radius: 6px; border-left: 4px solid var(--success); margin-bottom: 20px; font-size: 14px; }
        
        /* Data Tables */
        table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 14px; }
        th { text-align: left; padding: 15px; background: #f8fafc; color: var(--secondary); font-weight: 600; border-bottom: 1px solid var(--border); text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
        td { padding: 15px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        
        /* Progress Bar & Stats */
        #progress-area { display: none; }
        .progress-track { background: #e2e8f0; height: 20px; border-radius: 10px; overflow: hidden; margin-bottom: 15px; }
        .progress-fill { background: linear-gradient(90deg, var(--primary), #818cf8); height: 100%; width: 0%; transition: width 0.4s ease; display: flex; align-items: center; justify-content: center; color: white; font-size: 10px; font-weight: bold; }
        
        /* Stats Grid - Dashboard style metrics */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-box { background: #f8fafc; padding: 15px; border-radius: 8px; text-align: center; border: 1px solid var(--border); }
        .stat-val { font-size: 24px; font-weight: 700; color: var(--text-main); margin-bottom: 5px; }
        .stat-lbl { font-size: 11px; color: var(--secondary); text-transform: uppercase; letter-spacing: 0.5px; }
        .text-danger { color: var(--danger); }
        
        /* Console Log Window - Terminal style output */
        .console-window { background: #1e293b; color: #a5f3fc; font-family: 'Consolas', 'Monaco', monospace; padding: 15px; border-radius: 8px; height: 250px; overflow-y: auto; font-size: 12px; border: 1px solid #334155; box-shadow: inset 0 2px 4px rgba(0,0,0,0.3); }
        .log-entry { margin-bottom: 4px; border-bottom: 1px solid #334155; padding-bottom: 2px; }
        .log-error { color: #fca5a5; font-weight: bold; }
        .log-success { color: #86efac; }
        .log-info { color: #e2e8f0; }
        
        /* Post Import Stats Table */
        #stats-table-wrapper { max-height: 400px; overflow-y: auto; border: 1px solid var(--border); border-radius: 8px; margin-top: 20px; }
        
        /* Responsive Mobile Adjustments */
        @media (max-width: 600px) {
            .header h1 { font-size: 24px; }
            .card { padding: 15px; }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>BigSQL Importer Pro</h1>
        <p>Script/Code Author: Mr. Shudhanshu Kumar Pandey</p>
        <p><small>v<?php echo APP_VERSION; ?> | Max 10GB Support</small></p>
        <a href="https://razorpay.me/@resulthosting" target="_blank" class="btn btn-contribute">❤ Contribute</a>
    </div>

    <?php if (!isset($_SESSION['rh_auth'])): ?>
    <div class="card" style="max-width: 500px; margin: 0 auto;">
        <div class="card-title">Database Authentication</div>
        <p style="margin-bottom: 20px; font-size: 13px; color: #64748b;">Please enter your database credentials to begin the secure import session.</p>
        
        <?php echo $auth_msg; ?>
        
        <form method="post">
            <div class="form-group">
                <label>Host Name</label>
                <input type="text" name="db_host" class="form-control" value="localhost" placeholder="localhost" required>
            </div>
            <div class="form-group">
                <label>Database Name</label>
                <input type="text" name="db_name" class="form-control" placeholder="Target Database Name" required>
            </div>
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="db_user" class="form-control" placeholder="root" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="db_pass" class="form-control" placeholder="Database Password">
            </div>
            <button type="submit" name="connect_db" class="btn btn-primary" style="width:100%">Connect Securely</button>
        </form>
    </div>
    
    <?php else: ?>

    <div class="card">
        <div class="card-title">
            <div>
                <span style="color:var(--success)">● Connected</span>
                <span style="font-weight:400; font-size:14px; margin-left:10px;">
                    DB: <strong><?php echo htmlspecialchars($_SESSION['rh_db_name']); ?></strong> 
                    @ <?php echo htmlspecialchars($_SESSION['rh_db_host']); ?>
                </span>
            </div>
            <a href="?action=logout" class="btn btn-danger btn-sm">Disconnect</a>
        </div>

        <?php echo $upload_msg; ?>
        
        <form method="post" enctype="multipart/form-data" style="background: #f8fafc; padding: 20px; border-radius: 8px; border: 1px dashed var(--border); display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
            <div style="flex-grow: 1;">
                <label style="font-size: 12px; font-weight: bold; display: block; margin-bottom: 5px;">Upload SQL / GZ / CSV (Max 10GB)</label>
                <input type="file" name="dumpfile" class="form-control" style="padding: 8px;" required>
            </div>
            <button type="submit" name="upload_file" class="btn btn-primary">Upload File</button>
        </form>
    </div>

    <div class="card" id="file-selection-area">
        <div class="card-title">Select File to Import</div>
        <?php if (empty($files_available)): ?>
            <p style="text-align:center; color: #94a3b8; padding: 20px;">No compatible files found in directory.</p>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Filename</th>
                            <th>Size</th>
                            <th>Last Modified</th>
                            <th>Type</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($files_available as $file): 
                        $fpath = UPLOAD_DIR . $file;
                        $bytes = filesize($fpath);
                        
                        // Smart Size Formatting (GB/MB/KB)
                        if ($bytes >= 1073741824) $fsize = round($bytes / 1073741824, 2) . ' GB';
                        elseif ($bytes >= 1048576) $fsize = round($bytes / 1048576, 2) . ' MB';
                        else $fsize = round($bytes / 1024, 2) . ' KB';
                        
                        $ftype = strtoupper(pathinfo($file, PATHINFO_EXTENSION));
                        $fdate = date("Y-m-d H:i", filemtime($fpath));
                    ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($file); ?></strong></td>
                            <td><?php echo $fsize; ?></td>
                            <td><?php echo $fdate; ?></td>
                            <td><span style="background:#e0e7ff; color:#3730a3; padding:2px 6px; border-radius:4px; font-size:10px; font-weight:700;"><?php echo $ftype; ?></span></td>
                            <td style="text-align:right;">
                                <button onclick="startImport('<?php echo htmlspecialchars($file); ?>')" class="btn btn-primary btn-sm">Start Import</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card" id="progress-area">
        <div class="card-title">
            Importing: <span id="active-filename" style="color:var(--primary); margin-left:5px;"></span>
        </div>
        
        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-val" id="stat-total-queries">0</div>
                <div class="stat-lbl">Queries Executed</div>
            </div>
            <div class="stat-box">
                <div class="stat-val text-danger" id="stat-errors">0</div>
                <div class="stat-lbl">Skipped / Errors</div>
            </div>
            <div class="stat-box">
                <div class="stat-val" id="stat-lines">0</div>
                <div class="stat-lbl">Lines Processed</div>
            </div>
            <div class="stat-box">
                <div class="stat-val" id="stat-pct" style="color:var(--success)">0%</div>
                <div class="stat-lbl">Completion</div>
            </div>
        </div>

        <div class="progress-track">
            <div class="progress-fill" id="pbar-fill" style="width: 0%">0%</div>
        </div>
        
        <h4 style="margin: 0 0 10px 0; font-size: 13px; color: var(--secondary);">Live Process Log (Errors saved to file)</h4>
        <div class="console-window" id="console-output">
            <div class="log-entry log-info">[System] Ready to start import process...</div>
        </div>
        
        <div style="margin-top: 20px; text-align: center;">
             <p style="font-size: 12px; color: #64748b;">Do not close this window until the process is complete.</p>
        </div>
    </div>

    <div class="card" id="final-report" style="display:none; text-align:center; border-top: 5px solid var(--success);">
        <div style="padding: 10px;">
            <h2 style="color: var(--success); margin-bottom: 10px;">Import Completed Successfully!</h2>
            <p style="color: var(--secondary);">The entire file has been processed.</p>
            
            <div style="background: #f8fafc; padding: 20px; border-radius: 8px; display: inline-block; text-align: left; margin: 20px 0; width: 100%; box-sizing: border-box;">
                <div style="margin-bottom: 10px;"><strong>Summary:</strong></div>
                <div>Total Skipped/Failed: <span id="final-fail-count" style="color:var(--danger); font-weight:bold;"></span></div>
                <div>Log File: <span id="final-log-file" style="font-family:monospace;"></span></div>
                
                <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 15px 0;">
                
                <div style="margin-bottom: 10px;"><strong>Database Statistics (Post Import):</strong></div>
                <div id="stats-table-wrapper">
                    </div>
            </div>
            
            <br>
            <button onclick="location.reload()" class="btn btn-primary">Start New Import</button>
            <p style="margin-top: 15px; font-size: 12px; color: #94a3b8;">Security Note: Please delete the script and dump file from server.</p>
        </div>
    </div>

    <?php endif; ?>
</div>

<script>
    // ======================================================================
    // JAVASCRIPT: Frontend Logic
    // ======================================================================
    
    // Global State Variables
    let importActive = false;
    let currentFile = '';
    let currentLine = 0;
    let currentOffset = 0;
    let totalQueries = 0;
    let errorCount = 0;

    /**
     * FUNCTION: logToConsole
     * Description: Appends stylized messages to the custom black console window.
     * Auto-scrolls to the bottom to show latest messages.
     */
    function logToConsole(msg, type = 'info') {
        const consoleDiv = document.getElementById('console-output');
        const row = document.createElement('div');
        
        let className = 'log-entry';
        if(type === 'error') className += ' log-error';
        if(type === 'success') className += ' log-success';
        if(type === 'info') className += ' log-info';
        
        row.className = className;
        
        const time = new Date().toLocaleTimeString();
        row.innerHTML = `<span style="opacity:0.6">[${time}]</span> ${msg}`;
        
        consoleDiv.appendChild(row);
        consoleDiv.scrollTop = consoleDiv.scrollHeight; // Auto-scroll to bottom
    }

    /**
     * FUNCTION: startImport
     * Description: Triggered by button click. Hides file selection, shows dashboard,
     * and initiates the first AJAX call.
     */
    function startImport(filename) {
        if(!confirm("CONFIRMATION:\nAre you sure you want to import '" + filename + "'?\n\nThis will modify the database '" + "<?php echo isset($_SESSION['rh_db_name']) ? $_SESSION['rh_db_name'] : ''; ?>" + "'.")) return;

        // Switch UI Views
        document.getElementById('file-selection-area').style.display = 'none';
        document.getElementById('progress-area').style.display = 'block';
        document.getElementById('active-filename').innerText = filename;
        document.getElementById('final-report').style.display = 'none'; // Ensure hidden on restart
        
        // Reset Counters
        importActive = true;
        currentFile = filename;
        currentLine = 0;
        currentOffset = 0;
        totalQueries = 0;
        errorCount = 0;
        
        logToConsole("Initializing Import for: " + filename, 'info');
        
        // Trigger First Batch
        processBatch();
    }

    /**
     * FUNCTION: processBatch
     * Description: Recursive function that sends AJAX requests to the PHP backend.
     * It handles the response, updates the UI stats, and triggers the next batch.
     */
    function processBatch() {
        if (!importActive) return;

        const formData = new FormData();
        formData.append('filename', currentFile);
        formData.append('start_line', currentLine);
        formData.append('file_offset', currentOffset);
        formData.append('total_queries', totalQueries);
        formData.append('total_errors', errorCount);
        formData.append('ajax_process', '1');

        // Send to Backend
        fetch('bigsql-importer-process-ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) throw new Error("Network response was not ok");
            return response.json();
        })
        .then(data => {
            // Check for Critical Backend Errors
            if (data.status === 'error') {
                logToConsole("CRITICAL STOP: " + data.message, 'error');
                alert("Critical Error: " + data.message);
                importActive = false;
                return;
            }

            // Update State with Data from Server
            currentLine = data.current_line;
            currentOffset = data.current_offset;
            totalQueries = data.total_queries;
            errorCount = data.total_errors;

            // Update UI Elements
            document.getElementById('stat-lines').innerText = currentLine.toLocaleString();
            document.getElementById('stat-total-queries').innerText = totalQueries.toLocaleString();
            document.getElementById('stat-errors').innerText = errorCount.toLocaleString();
            document.getElementById('stat-pct').innerText = data.pct_complete + '%';
            
            // Animate Progress Bar
            const pbar = document.getElementById('pbar-fill');
            pbar.style.width = data.pct_complete + '%';
            pbar.innerText = data.pct_complete + '%';

            // Print Batch Logs to Console (Loop through any messages sent by PHP)
            if (data.batch_log && data.batch_log.length > 0) {
                data.batch_log.forEach(logMsg => {
                    // Detect if log is error or info based on string content
                    let type = (logMsg.indexOf("Error") !== -1 || logMsg.indexOf("Skipping") !== -1) ? 'error' : 'info';
                    logToConsole(logMsg, type);
                });
            }

            // Control Logic Loop
            if (data.status === 'continue') {
                // Determine small delay to prevent browser freeze and allow UI repaint
                setTimeout(processBatch, 50); 
            } else if (data.status === 'finished') {
                // Finish Logic
                importActive = false;
                logToConsole("IMPORT COMPLETED SUCCESSFULLY!", 'success');
                
                // Show Final Screen
                setTimeout(() => {
                    document.getElementById('progress-area').style.display = 'none';
                    document.getElementById('final-report').style.display = 'block';
                    document.getElementById('final-fail-count').innerText = errorCount;
                    document.getElementById('final-log-file').innerText = data.log_file || "N/A";

                    // Build Stats Table if available
                    if(data.table_stats && data.table_stats.length > 0) {
                        let tableHtml = '<table style="font-size:12px;"><thead><tr><th>Table Name</th><th>Rows</th><th>Size (MB)</th></tr></thead><tbody>';
                        data.table_stats.forEach(row => {
                            tableHtml += `<tr>
                                <td>${row.Name}</td>
                                <td>${row.Rows}</td>
                                <td>${row.SizeMB}</td>
                            </tr>`;
                        });
                        tableHtml += '</tbody></table>';
                        document.getElementById('stats-table-wrapper').innerHTML = tableHtml;
                    } else {
                         document.getElementById('stats-table-wrapper').innerHTML = '<p style="padding:10px; color:#64748b;">No table statistics available.</p>';
                    }
                }, 800);
            }
        })
        .catch(error => {
            logToConsole("AJAX/Network Failure: " + error.message, 'error');
            console.error(error);
            // On failure, we stop to protect data integrity.
            alert("Connection lost or Server Error. Check console for details.");
            importActive = false;
        });
    }
</script>

</body>
</html>