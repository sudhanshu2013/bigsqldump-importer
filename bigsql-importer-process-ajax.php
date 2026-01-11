<?php
session_start();

/*
  *******************************************************************************************
  * BigSQL Importer - AJAX Backend Processor                                                *
  * *
  * @license MIT                                                                            *
  * @author Shudhanshu Kumar Pandey                                                         *
  * @year 2026                                                                              *
  * *
  * Description:                                                                            *
  * Handles the staggered import of SQL files via AJAX.                                     *
  * Implements Try/Catch blocks for fault tolerance.                                        *
  * Tracks processing context (Table Name) for better error logging.                        *
  * Returns detailed statistics upon completion.                                            *
  *******************************************************************************************
*/

header('Content-Type: application/json');

// 1. CONFIGURATION
// -------------------------------------------------------------------
// IMPORTANT: Limit lines per session to ensure frequent AJAX responses.
// This allows the frontend progress bar to update "live".
// A value of 3000-5000 is usually optimal for shared hosting.
$cfg_lines_per_session = 3000; 
$upload_dir = dirname(__FILE__) . '/';

// 2. AUTHENTICATION CHECK
// -------------------------------------------------------------------
if (!isset($_SESSION['rh_auth']) || !isset($_POST['ajax_process'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized Access']);
    exit;
}

// 3. INITIALIZATION
// -------------------------------------------------------------------
$filename      = $_POST['filename'];
$start_line    = (int)$_POST['start_line'];
$file_offset   = (int)$_POST['file_offset'];
$total_queries = (int)$_POST['total_queries'];
$total_errors  = (int)$_POST['total_errors'];
$filepath      = $upload_dir . $filename;
$log_file      = $upload_dir . (isset($_SESSION['rh_log_file']) ? $_SESSION['rh_log_file'] : 'import_error_log.txt');

// Helper function to write to text log
function write_log($file, $msg) {
    $date = date('Y-m-d H:i:s');
    file_put_contents($file, "[$date] $msg" . PHP_EOL, FILE_APPEND);
}

// 4. DATABASE CONNECTION
// -------------------------------------------------------------------
// Enable Strict Reporting to catch Exceptions
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $mysqli = new mysqli(
        $_SESSION['rh_db_host'],
        $_SESSION['rh_db_user'],
        $_SESSION['rh_db_pass'],
        $_SESSION['rh_db_name']
    );
    $mysqli->set_charset("utf8");
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database Connection Failed: ' . $e->getMessage()]);
    exit;
}

// 5. FILE HANDLING (GZIP & STANDARD)
// -------------------------------------------------------------------
$is_gzip = preg_match("/\.gz$/i", $filename);
$file_handle = false;

if ($is_gzip) {
    $file_handle = @gzopen($filepath, "r");
    if (!$file_handle) {
        echo json_encode(['status' => 'error', 'message' => 'Could not open GZIP file.']);
        exit;
    }
    // Seek GZIP (Note: gzseek is slow on large offsets, but necessary for staggering)
    if ($file_offset > 0) gzseek($file_handle, $file_offset);
} else {
    $file_handle = @fopen($filepath, "r");
    if (!$file_handle) {
        echo json_encode(['status' => 'error', 'message' => 'Could not open SQL file.']);
        exit;
    }
    if ($file_offset > 0) fseek($file_handle, $file_offset);
}

// Calculate/Estimate Filesize for Progress
$filesize = 0;
if (!$is_gzip) {
    $filesize = filesize($filepath);
} else {
    // For GZIP, we can't get uncompressed size easily. We'll rely on pointer position or 0.
    $filesize = 0; 
}

// 6. PROCESSING LOOP
// -------------------------------------------------------------------
$current_line_num = $start_line;
$query_buffer = "";
$delimiter = ";";
$lines_processed_count = 0;
$batch_logs = []; // Logs sent back to browser console
$status = 'continue';

// Tracking Variables for improved logging
$current_table_context = "Unknown"; 

// We run until we hit the line limit OR a time limit (soft check)
$start_time = time();
$max_exec_time = 25; // Safe margin for standard 30s timeout

while ($lines_processed_count < $cfg_lines_per_session) {
    
    // Check timeout protection
    if ((time() - $start_time) > $max_exec_time) {
        break; // Stop and let AJAX reload to reset timer
    }

    // Read Line
    if ($is_gzip) {
        if (gzeof($file_handle)) break;
        $line = gzgets($file_handle, 40960); // Read 40KB chunk
    } else {
        if (feof($file_handle)) break;
        $line = fgets($file_handle, 40960);
    }

    if ($line === false) break;

    // Remove BOM (Byte Order Mark) on very first line
    if ($file_offset == 0 && $current_line_num == 0) {
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
    }

    $trimmed_line = trim($line);

    // LOGIC: Handle DELIMITER switching (e.g. Procedures/Functions)
    if (preg_match('/^DELIMITER\s+(.*)$/i', $trimmed_line, $matches)) {
        $delimiter = $matches[1];
        $current_line_num++;
        $lines_processed_count++;
        continue;
    }

    // LOGIC: Skip Standard Comments (-- or #)
    // Only if they start the line.
    if (strpos($trimmed_line, '--') === 0 || strpos($trimmed_line, '#') === 0) {
        $current_line_num++;
        $lines_processed_count++;
        continue;
    }

    // LOGIC: Detect Table Name Context
    // We try to parse "INSERT INTO `tablename`" or "CREATE TABLE `tablename`"
    if (preg_match('/^\s*(INSERT\s+INTO|CREATE\s+TABLE)\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i', $trimmed_line, $matches)) {
        $current_table_context = $matches[2];
    }

    // Append line to buffer
    $query_buffer .= $line;

    // CHECK IF QUERY IS COMPLETE
    // We check if the trimmed line ends with the current delimiter
    if (substr($trimmed_line, -strlen($delimiter)) === $delimiter) {
        
        // Remove delimiter from the end to execute
        $sql_to_run = substr(trim($query_buffer), 0, -strlen($delimiter));

        if (!empty($sql_to_run)) {
            try {
                $mysqli->query($sql_to_run);
                $total_queries++;
            } catch (mysqli_sql_exception $e) {
                // ******************************************************
                // FAULT TOLERANCE & DETAILED LOGGING
                // ******************************************************
                
                $err_msg = $e->getMessage();
                $err_code = $e->getCode();
                
                // Construct detailed error message
                $log_detail = "Table: $current_table_context | Line: $current_line_num | Error ($err_code): $err_msg";
                
                // Log detailed error to text file
                write_log($log_file, $log_detail);
                
                // Add skipped info to text file
                write_log($log_file, "Query Snippet: " . substr($sql_to_run, 0, 150) . "...");
                write_log($log_file, "--------------------------------------------------");

                // Update counters
                $total_errors++;
                
                // Add to Browser Console Log (short version)
                $batch_logs[] = "Error in [$current_table_context]: " . substr($err_msg, 0, 50) . "...";
            }
        }

        // Reset Buffer
        $query_buffer = "";
    }

    $current_line_num++;
    $lines_processed_count++;
}

// 7. FINALIZE BATCH
// -------------------------------------------------------------------

// Get new offset
if ($is_gzip) {
    $new_offset = gztell($file_handle);
    gzclose($file_handle);
} else {
    $new_offset = ftell($file_handle);
    fclose($file_handle);
}

// Calculate Progress Percentage
$pct = 0;
if ($filesize > 0) {
    $pct = round(($new_offset / $filesize) * 100, 2);
} else {
    $pct = 99; // Fallback for Gzip
}
if ($pct > 100) $pct = 100;

// Determine if Finished
// If we read less than limit AND query buffer is empty, we are likely done.
if ($lines_processed_count < $cfg_lines_per_session && empty(trim($query_buffer))) {
    $status = 'finished';
    $pct = 100;
}

// 8. GATHER STATISTICS (IF FINISHED)
// -------------------------------------------------------------------
$table_stats = [];
if ($status === 'finished') {
    try {
        $result = $mysqli->query("SHOW TABLE STATUS");
        while ($row = $result->fetch_assoc()) {
            $size_mb = round(($row['Data_length'] + $row['Index_length']) / 1024 / 1024, 2);
            $table_stats[] = [
                'Name' => $row['Name'],
                'Rows' => number_format($row['Rows']),
                'SizeMB' => $size_mb
            ];
        }
    } catch (Exception $e) {
        // Fail silently on stats collection if something goes wrong, not critical
        write_log($log_file, "Warning: Could not fetch final table stats.");
    }
}

// 9. SEND JSON RESPONSE
// -------------------------------------------------------------------
echo json_encode([
    'status' => $status,
    'current_line' => $current_line_num,
    'current_offset' => $new_offset,
    'total_queries' => $total_queries,
    'total_errors' => $total_errors,
    'pct_complete' => $pct,
    'batch_log' => $batch_logs,
    'log_file' => basename($log_file),
    'table_stats' => $table_stats // Send final stats if finished
]);
exit;
?>