<?php
if (isset($_GET['path'])) {
    $path = $_GET['path'];
    
    // Basic security check to prevent directory traversal
    if (strpos($path, '..') !== false) {
        die('Invalid path');
    }
    
    if (is_dir($path)) {
        if (PHP_OS === 'WINNT') {
            // Windows
            exec('explorer "' . str_replace('/', '\\', $path) . '"');
        } else {
            // Linux/Mac
            exec('xdg-open "' . $path . '"');  // Linux
            // exec('open "' . $path . '"');    // Mac
        }
        echo "Opening folder: " . htmlspecialchars($path);
    } else {
        echo "Directory not found";
    }
} 