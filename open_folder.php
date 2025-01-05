<?php
if (isset($_GET['path'])) {
    $path = $_GET['path'];
    
    // Basic security check
    if (strpos($path, '..') !== false) {
        die('Invalid path');
    }
    
    if (is_dir($path)) {
        // Normalize path for current OS
        $path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
        
        try {
            if (PHP_OS === 'WINNT') {
                // Windows
                exec('explorer "' . $path . '"');
                echo "Opening folder in Windows Explorer: " . htmlspecialchars($path);
            } else {
                // Linux
                $fileManagers = ['xdg-open', 'nautilus', 'dolphin', 'thunar', 'pcmanfm'];
                $opened = false;
                
                foreach ($fileManagers as $fm) {
                    exec("which $fm 2>/dev/null", $output, $returnCode);
                    if ($returnCode === 0) {
                        exec("$fm \"$path\" > /dev/null 2>&1 &");
                        $opened = true;
                        echo "Opening folder using $fm: " . htmlspecialchars($path);
                        break;
                    }
                }
                
                if (!$opened) {
                    echo "Could not find a suitable file manager. Path: " . htmlspecialchars($path);
                }
            }
        } catch (Exception $e) {
            echo "Error opening folder: " . htmlspecialchars($e->getMessage());
        }
    } else {
        echo "Directory not found: " . htmlspecialchars($path);
    }
}
?>