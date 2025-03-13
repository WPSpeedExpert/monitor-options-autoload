<?php
/**
 * export-compare-autoload.php
 * Script to export or compare WordPress autoload values
 * Version: 1.0.0
 * 
 * @author Brian Chin
 * @package OctaHexa Utils
 */

// Basic security check
$key = isset($_GET['key']) ? $_GET['key'] : '';
if ($key !== 'compare-autoload') {
    die('Access denied. Use the correct key parameter.');
}

// Database connection settings - MODIFY THESE
$db_name = ''; // Your database name
$db_user = ''; // Your database username
$db_password = ''; // Your database password
$db_host = 'localhost'; // Usually localhost
$table_prefix = 'wp_'; // Your table prefix (usually wp_)

// Mode - 'export' or 'import'
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'export';

// Connect to the database if not in upload mode
$conn = null;
if ($mode !== 'upload') {
    $conn = new mysqli($db_host, $db_user, $db_password, $db_name);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
}

// Header
echo "<!DOCTYPE html>
<html>
<head>
    <title>WordPress Autoload Compare</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 0 auto; padding: 20px; }
        h1, h2 { color: #23282d; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f1f1f1; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        .button { background-color: #0073aa; color: white; padding: 10px 15px; text-decoration: none; display: inline-block; border-radius: 3px; margin-right: 10px; }
        .success { background-color: #dff0d8; color: #3c763d; padding: 10px; border: 1px solid #d6e9c6; margin-bottom: 20px; }
        .error { background-color: #f2dede; color: #a94442; padding: 10px; border: 1px solid #ebccd1; margin-bottom: 20px; }
        .different { background-color: #fcf8e3; }
    </style>
</head>
<body>
    <h1>WordPress Autoload Compare Tool</h1>";

// Function to get autoload data
function getAutoloadData($conn, $table_prefix) {
    $data = array();
    $sql = "SELECT option_name, autoload FROM {$table_prefix}options ORDER BY option_name";
    $result = $conn->query($sql);
    
    if ($result === false) {
        return "Error: " . $conn->error;
    }
    
    while ($row = $result->fetch_assoc()) {
        $data[$row['option_name']] = $row['autoload'];
    }
    
    return $data;
}

// Export mode
if ($mode === 'export') {
    $data = getAutoloadData($conn, $table_prefix);
    
    if (is_string($data)) {
        echo "<div class='error'>{$data}</div>";
    } else {
        // Write to file
        $json = json_encode($data, JSON_PRETTY_PRINT);
        $filename = "autoload_export_" . date("Y-m-d_His") . ".json";
        
        echo "<h2>Export Autoload Data</h2>";
        echo "<p>Copy the JSON data below or click Download:</p>";
        echo "<textarea style='width: 100%; height: 300px;'>{$json}</textarea>";
        echo "<p><a href='?key={$key}&mode=download' class='button'>Download JSON</a></p>";
        
        // Store in session for download
        session_start();
        $_SESSION['autoload_export'] = $json;
        $_SESSION['autoload_filename'] = $filename;
    }
} 
// Download mode
else if ($mode === 'download') {
    session_start();
    if (isset($_SESSION['autoload_export'])) {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $_SESSION['autoload_filename'] . '"');
        echo $_SESSION['autoload_export'];
        exit;
    } else {
        echo "<div class='error'>No export data found. Please export first.</div>";
    }
}
// Upload/Compare mode
else if ($mode === 'upload' || $mode === 'compare') {
    echo "<h2>Compare Autoload Data</h2>";
    
    // Show upload form
    if ($mode === 'upload' || !isset($_FILES['json_file'])) {
        echo "<form method='post' enctype='multipart/form-data' action='?key={$key}&mode=compare'>
            <p>Upload JSON file from healthy WordPress installation:</p>
            <input type='file' name='json_file' required>
            <p><input type='submit' value='Compare' class='button'></p>
        </form>";
    } 
    // Process uploaded file and compare
    else if ($mode === 'compare' && isset($_FILES['json_file'])) {
        $uploaded_file = $_FILES['json_file'];
        
        if ($uploaded_file['error'] !== 0) {
            echo "<div class='error'>Error uploading file: " . $uploaded_file['error'] . "</div>";
        } else {
            // Read uploaded JSON
            $json_content = file_get_contents($uploaded_file['tmp_name']);
            $healthy_data = json_decode($json_content, true);
            
            if ($healthy_data === null) {
                echo "<div class='error'>Invalid JSON file.</div>";
            } else {
                // Get current database data
                $current_data = getAutoloadData($conn, $table_prefix);
                
                if (is_string($current_data)) {
                    echo "<div class='error'>{$current_data}</div>";
                } else {
                    // Compare and show differences
                    echo "<h3>Autoload Differences</h3>";
                    echo "<p>Comparing your database with the healthy database:</p>";
                    
                    // Count differences
                    $differences = 0;
                    $common_options = array_intersect_key($current_data, $healthy_data);
                    
                    echo "<table>
                        <tr>
                            <th>Option Name</th>
                            <th>Your Database</th>
                            <th>Healthy Database</th>
                        </tr>";
                    
                    foreach ($common_options as $option => $autoload) {
                        if ($autoload !== $healthy_data[$option]) {
                            $differences++;
                            echo "<tr class='different'>
                                <td>{$option}</td>
                                <td>{$autoload}</td>
                                <td>{$healthy_data[$option]}</td>
                            </tr>";
                        }
                    }
                    
                    echo "</table>";
                    
                    if ($differences === 0) {
                        echo "<div class='success'>No differences found in common options.</div>";
                    } else {
                        echo "<div class='error'>Found {$differences} options with different autoload values.</div>";
                        
                        // Add fix button
                        echo "<p><a href='?key={$key}&mode=fix' class='button' onclick='return confirm(\"This will update the autoload values to match the healthy database. Continue?\");'>Fix Differences</a></p>";
                        
                        // Store healthy data for fix
                        session_start();
                        $_SESSION['healthy_data'] = $healthy_data;
                    }
                }
            }
        }
    }
}
// Fix mode
else if ($mode === 'fix') {
    session_start();
    
    if (!isset($_SESSION['healthy_data'])) {
        echo "<div class='error'>No comparison data found. Please compare first.</div>";
    } else {
        $healthy_data = $_SESSION['healthy_data'];
        $current_data = getAutoloadData($conn, $table_prefix);
        
        if (is_string($current_data)) {
            echo "<div class='error'>{$current_data}</div>";
        } else {
            // Create backup
            $backup_sql = "CREATE TABLE IF NOT EXISTS {$table_prefix}options_backup LIKE {$table_prefix}options";
            $conn->query($backup_sql);
            
            $insert_sql = "INSERT INTO {$table_prefix}options_backup SELECT * FROM {$table_prefix}options";
            $conn->query($insert_sql);
            
            // Fix differences
            $updates = 0;
            $common_options = array_intersect_key($current_data, $healthy_data);
            
            foreach ($common_options as $option => $autoload) {
                if ($autoload !== $healthy_data[$option]) {
                    $update_sql = "UPDATE {$table_prefix}options SET autoload = ? WHERE option_name = ?";
                    $stmt = $conn->prepare($update_sql);
                    $stmt->bind_param("ss", $healthy_data[$option], $option);
                    $stmt->execute();
                    $updates += $stmt->affected_rows;
                    $stmt->close();
                }
            }
            
            echo "<div class='success'>
                <p>Backup created as {$table_prefix}options_backup</p>
                <p>Updated {$updates} options to match the healthy database.</p>
            </div>";
        }
    }
}

// Show navigation links
echo "<h2>Options</h2>
<p>
    <a href='?key={$key}&mode=export' class='button'>Export</a>
    <a href='?key={$key}&mode=upload' class='button'>Compare</a>
</p>";

// Close connection
if ($conn) {
    $conn->close();
}

// Footer
echo "</body>
</html>";
