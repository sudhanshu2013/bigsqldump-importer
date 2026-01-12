<?php
session_start();

/*
  *******************************************************************************************
  * BigSQL Importer - AJAX Backend Processor                                                *
  * *
  * Script/Code Author: Mr. Shudhanshu Kumar Pandey                                         *
  * @license MIT                                                                            *
  * @year 2026                                                                              *
  * *
  * Description:                                                                            *
  * Core logic for processing SQL dumps in chunks to avoid timeouts.                        *
  * Includes robust "Atomic Breaking" to prevent syntax errors on split queries.            *
  * Implements 'Continue on Error' logic to ensure all valid data is inserted.              *
  * *
  * UPDATES (2026):                                                                         *
  * 1. Auto-Correction for 'utf8mb4_uca1400_ai_ci' collation errors.                        *
  * 2. Intelligent handling of 'Table Exists' (1050) and 'Duplicate Entry' (1062).          *
  *******************************************************************************************
*/

// ======================================================================
// 0. SET RESPONSE HEADERS
// ======================================================================
// Define the content type as JSON so the frontend JavaScript can parse the result.
header('Content-Type: application/json');

// ======================================================================
// 1. CONFIGURATION & TUNING
// ======================================================================

// BATCH SIZE LIMIT
// We aim for ~3000 lines per request to keep the UI responsive and update the progress bar frequently.
// NOTE: The script will now intelligently exceed this limit if required 
// to finish a specific query, preventing the "Syntax Error" logs caused by split SQL statements.
$cfg_lines_per_session = 3000; 

// EXECUTION TIME LIMIT
// Safety cutoff to prevent HTTP 504 Gateway Timeouts or PHP Max Execution limits.
// Standard PHP timeout is usually 30s, so we stop safely at 25s to save state and return.
$max_exec_time = 25; 

// Define the upload directory path
$upload_dir = dirname(__FILE__) . '/';

// ======================================================================
// 2. SECURITY & AUTHENTICATION
// ======================================================================

// Check if the user is authenticated via the session variable 'rh_auth'.
// Also ensure the request comes from our specific AJAX call ('ajax_process').
if (!isset($_SESSION['rh_auth']) || !isset($_POST['ajax_process'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized Access']);
    exit;
}

// ======================================================================
// 3. INITIALIZATION & STATE RESTORATION
// ======================================================================

// Retrieve state variables passed from the Frontend (JavaScript).
// These tell us where we left off in the previous batch.
$filename      = $_POST['filename'];            // Name of the SQL file
$start_line    = (int)$_POST['start_line'];     // Line number to start display counter
$file_offset   = (int)$_POST['file_offset'];    // Byte offset to seek in the file
$total_queries = (int)$_POST['total_queries'];  // Running count of queries run
$total_errors  = (int)$_POST['total_errors'];   // Running count of errors encountered
$filepath      = $upload_dir . $filename;       // Full path to the file

// Log File: Use session-based log name or fallback to default
$log_file      = $upload_dir . (isset($_SESSION['rh_log_file']) ? $_SESSION['rh_log_file'] : 'import_error_log.txt');

/**
 * Helper Function: write_log
 * ----------------------------------------------------------------------
 * Appends messages to the server-side text log for debugging.
 * useful for reviewing errors after the import completes.
 * * @param string $file Path to the log file
 * @param string $msg  The message to write
 */
function write_log($file, $msg) {
    $date = date('Y-m-d H:i:s');
    file_put_contents($file, "[$date] $msg" . PHP_EOL, FILE_APPEND);
}

// ======================================================================
// 4. DATABASE CONNECTION
// ======================================================================

// Enable Strict Reporting to catch all SQL errors as Exceptions.
// This allows us to use try-catch blocks to handle errors gracefully.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // Establish a new connection using session credentials
    $mysqli = new mysqli(
        $_SESSION['rh_db_host'],
        $_SESSION['rh_db_user'],
        $_SESSION['rh_db_pass'],
        $_SESSION['rh_db_name']
    );
    
    // Set the charset to UTF-8 to ensure special characters (Hindi, Emojis, etc.) are handled correctly.
    $mysqli->set_charset("utf8");
    
} catch (Exception $e) {
    // If connection fails, return JSON error immediately.
    echo json_encode(['status' => 'error', 'message' => 'Database Connection Failed: ' . $e->getMessage()]);
    exit;
}

// ======================================================================
// 5. FILE POINTER HANDLING
// ======================================================================

// Detect File Type (GZIP or Plain SQL)
$is_gzip = preg_match("/\.gz$/i", $filename);
$file_handle = false;

// Open File based on type
if ($is_gzip) {
    $file_handle = @gzopen($filepath, "r");
    if (!$file_handle) {
        echo json_encode(['status' => 'error', 'message' => 'Could not open GZIP file.']);
        exit;
    }
    // Resume Position (Seek to the byte offset where we stopped last time)
    if ($file_offset > 0) gzseek($file_handle, $file_offset);
} else {
    $file_handle = @fopen($filepath, "r");
    if (!$file_handle) {
        echo json_encode(['status' => 'error', 'message' => 'Could not open SQL file.']);
        exit;
    }
    // Resume Position (Seek to the byte offset)
    if ($file_offset > 0) fseek($file_handle, $file_offset);
}

// Get File Size for Progress Bar calculation
$filesize = 0;
if (!$is_gzip) {
    $filesize = filesize($filepath);
}

// ======================================================================
// 6. CORE PROCESSING LOOP (ATOMIC EXECUTION)
// ======================================================================

// Variables to track progress within this specific batch
$current_line_num = $start_line;
$query_buffer = "";      // Stores the accumulating SQL query (handling multi-line queries)
$delimiter = ";";        // Default delimiter (will change if DELIMITER command is found)
$lines_processed_count = 0;
$batch_logs = [];        // Logs to send back to Browser for live feedback
$status = 'finished';    // Default assumption, changed to 'continue' if loop breaks early
$start_time = time();
$current_table_context = "Unknown"; // For Error Logging context

/**
 * MAIN PROCESSING LOOP
 * ----------------------------------------------------------------------
 * Reads the file line-by-line.
 * Builds queries from multiple lines.
 * Executes queries when the delimiter is found.
 * * CRITICAL LOGIC: The loop condition is `true`. We explicitly `break` 
 * only when we hit limits (time/lines) AND the buffer is empty.
 */
while (true) {
    
    // ------------------------------------------------------------------
    // A. BREAK CONDITION CHECK (Atomic Safety)
    // ------------------------------------------------------------------
    // We ONLY check the limits if the $query_buffer is EMPTY.
    // This ensures we never split a query in the middle (Atomic Breaking).
    if (trim($query_buffer) === "") {
        // Check if we exceeded line limit OR time limit
        if ($lines_processed_count >= $cfg_lines_per_session || (time() - $start_time) > $max_exec_time) {
            $status = 'continue'; // Tell frontend to request next batch
            break; // Pause execution here
        }
    }

    // ------------------------------------------------------------------
    // B. READ NEXT LINE
    // ------------------------------------------------------------------
    $line = false;
    if ($is_gzip) {
        if (gzeof($file_handle)) break; // End of File
        $line = gzgets($file_handle, 40960); // Read ~40KB chunk buffer
    } else {
        if (feof($file_handle)) break; // End of File
        $line = fgets($file_handle, 40960);
    }

    // If reading failed or EOF reached naturally
    if ($line === false) {
        break;
    }

    // Handle BOM (Byte Order Mark) on the absolute first line to prevent syntax errors
    if ($file_offset == 0 && $current_line_num == 0) {
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
    }

    $trimmed_line = trim($line);

    // ------------------------------------------------------------------
    // C. PARSING LOGIC
    // ------------------------------------------------------------------

    // 1. Handle DELIMITER changes (e.g., for Stored Procedures/Triggers)
    // Example: DELIMITER $$
    if (preg_match('/^DELIMITER\s+(.*)$/i', $trimmed_line, $matches)) {
        $delimiter = $matches[1];
        $current_line_num++;
        $lines_processed_count++;
        continue;
    }

    // 2. Skip Comments (Optimize speed)
    // Only if line STARTS with comment and we aren't inside a query string buffer
    if (trim($query_buffer) === "" && (strpos($trimmed_line, '--') === 0 || strpos($trimmed_line, '#') === 0 || strpos($trimmed_line, '/*') === 0)) {
        $current_line_num++;
        $lines_processed_count++;
        continue;
    }

    // 3. Detect Table Name (For Logging purposes)
    // We look for INSERT INTO or CREATE TABLE statements at the start of a buffer
    if (trim($query_buffer) === "" && preg_match('/^\s*(INSERT\s+INTO|CREATE\s+TABLE)\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i', $trimmed_line, $matches)) {
        $current_table_context = $matches[2];
    }

    // 4. Append Line to Buffer
    $query_buffer .= $line;

    // ------------------------------------------------------------------
    // D. EXECUTION TRIGGER
    // ------------------------------------------------------------------
    
    // Check if the trimmed line ends with the current delimiter.
    // NOTE: This handles both single-line queries and multi-line extended inserts.
    if (substr($trimmed_line, -strlen($delimiter)) === $delimiter) {
        
        // Strip the delimiter to prepare SQL for execution
        $sql_to_run = substr(trim($query_buffer), 0, -strlen($delimiter));

        if (!empty($sql_to_run)) {
            
            // ----------------------------------------------------------
            // COMPATIBILITY FIX: COLLATION REPLACEMENT
            // ----------------------------------------------------------
            // Replace new MariaDB/MySQL 8.0 collations with standard ones
            // to prevent Error 1273 on older servers.
            if (strpos($sql_to_run, 'utf8mb4_uca1400_ai_ci') !== false) {
                $sql_to_run = str_replace('utf8mb4_uca1400_ai_ci', 'utf8mb4_unicode_ci', $sql_to_run);
            }
            if (strpos($sql_to_run, 'utf8mb4_0900_ai_ci') !== false) {
                $sql_to_run = str_replace('utf8mb4_0900_ai_ci', 'utf8mb4_unicode_ci', $sql_to_run);
            }

            try {
                // EXECUTE QUERY
                $mysqli->query($sql_to_run);
                $total_queries++;
            } catch (mysqli_sql_exception $e) {
                // ******************************************************
                // FAULT TOLERANCE & INTELLIGENT ERROR HANDLING
                // ******************************************************
                
                $err_msg = $e->getMessage();
                $err_code = $e->getCode();
                
                // Define codes that are "Warnings" rather than "Fatal Errors"
                // 1050: Table exists
                // 1062: Duplicate entry (Data exists)
                // 1359: Trigger exists
                // 1068: Multiple primary keys defined (Structure exists)
                // 1060: Duplicate column name
                $non_fatal_errors = [1050, 1062, 1359, 1068, 1060, 1061, 1005];

                if (in_array($err_code, $non_fatal_errors)) {
                    // Log as a warning but don't count towards critical failures if strictness is loose
                    $log_detail = "SKIPPED (Exists): $current_table_context | Code: $err_code | Msg: $err_msg";
                    // Only write to file, don't spam the browser console
                    write_log($log_file, $log_detail);
                } else {
                    // Critical Error
                    $log_detail = "CRITICAL ERROR: Table: $current_table_context | Line: $current_line_num | Error ($err_code): $err_msg";
                    write_log($log_file, $log_detail);
                    
                    // Save a snippet of the failed query for debugging
                    write_log($log_file, "Query Snippet: " . substr($sql_to_run, 0, 150) . "...");
                    write_log($log_file, "--------------------------------------------------");
                    
                    $total_errors++;
                    
                    // Send short error to Browser Console
                    $batch_logs[] = "Error in [$current_table_context]: " . substr($err_msg, 0, 60) . "...";
                }
            }
        }

        // RESET BUFFER (Ready for next query)
        $query_buffer = "";
        $current_table_context = "Unknown"; // Reset context
    }

    $current_line_num++;
    $lines_processed_count++;
}

// ======================================================================
// 7. FINALIZE BATCH STATE
// ======================================================================

// Save File Position so the next AJAX request knows where to start
if ($is_gzip) {
    $new_offset = gztell($file_handle);
    gzclose($file_handle);
} else {
    $new_offset = ftell($file_handle);
    fclose($file_handle);
}

// Calculate Percentage Complete
$pct = 0;
if ($filesize > 0) {
    $pct = round(($new_offset / $filesize) * 100, 2);
} else {
    // Fallback for Gzip (unknown size)
    $pct = 99; 
}
if ($pct > 100) $pct = 100;

// Final Completion Check
// If buffer is empty AND we broke the loop because of EOF (not limit), we are done.
if ($status !== 'continue' && empty(trim($query_buffer))) {
    $status = 'finished';
    $pct = 100;
}

// ======================================================================
// 8. POST-IMPORT STATISTICS (Only on Finish)
// ======================================================================
$table_stats = [];
if ($status === 'finished') {
    try {
        // Fetch table statistics to show the user a summary of what was imported
        $result = $mysqli->query("SHOW TABLE STATUS");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                // Calculate size in MB
                $size_mb = round(($row['Data_length'] + $row['Index_length']) / 1024 / 1024, 2);
                $table_stats[] = [
                    'Name' => $row['Name'],
                    'Rows' => number_format($row['Rows']),
                    'SizeMB' => $size_mb
                ];
            }
        }
    } catch (Exception $e) {
        write_log($log_file, "Warning: Could not fetch final table stats.");
    }
}

// ======================================================================
// 9. RETURN JSON RESPONSE
// ======================================================================
// Send data back to the frontend to update the UI
echo json_encode([
    'status' => $status,
    'current_line' => $current_line_num,
    'current_offset' => $new_offset,
    'total_queries' => $total_queries,
    'total_errors' => $total_errors,
    'pct_complete' => $pct,
    'batch_log' => $batch_logs,
    'log_file' => basename($log_file),
    'table_stats' => $table_stats
]);
exit;
?>
