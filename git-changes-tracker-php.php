<?php
    date_default_timezone_set('Asia/Kolkata'); // Ensure consistent timezone

class GitChangesTracker {
    private $repoPath;
    private $historyFile;
    private $isWindows;
    private $lastOutput;  // Add this property to store backup details

    public function __construct(string $repoPath) {
        $this->isWindows = (PHP_OS === 'WINNT');
        $this->repoPath = $this->normalizePath($repoPath);
        $this->historyFile = $this->repoPath . DIRECTORY_SEPARATOR . '.git_changes_history.json';
        $this->lastOutput = '';
        $this->initializeProject();
    }

    // Add getter for backup details
    public function getLastOutput(): string {
        return $this->lastOutput;
    }

    private function normalizePath($path): string {
        return rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    private function initializeProject(): void {
        try {
            // Check if .gitignore exists
            $gitignorePath = $this->repoPath . '/.gitignore';
            $ignoreEntry = '.git_changes_history.json';
            
            if (file_exists($gitignorePath)) {
                // Read existing .gitignore content
                $content = file_get_contents($gitignorePath);
                
                // Check if our entry is already there
                if (strpos($content, $ignoreEntry) === false) {
                    // Add our entry on a new line
                    $content = rtrim($content) . "\n" . $ignoreEntry . "\n";
                    file_put_contents($gitignorePath, $content);
                }
            } else {
                // Create new .gitignore with our entry
                file_put_contents($gitignorePath, $ignoreEntry . "\n");
            }
            
        } catch (Exception $e) {
            echo "Warning: Could not update .gitignore file: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Load the timestamp of the last backup from history file
     */
    private function loadLastBackupTime(): ?string {
        try {
            if (file_exists($this->historyFile)) {
                $data = json_decode(file_get_contents($this->historyFile), true);
                $lastBackup = $data['last_backup'] ?? null;
                
                // Validate the timestamp
                if ($lastBackup) {
                    // Convert both times to Asia/Kolkata timezone for comparison
                    $lastBackupTime = strtotime($lastBackup);
                    $currentTime = time();
                    
                    // If timestamp is in the future, ignore it
                    if ($lastBackupTime > $currentTime) {
                        echo "Warning: Found future timestamp in history file. Ignoring it.\n";
                        return null;
                    }
                }
                
                return $lastBackup;
            }
            return null;
        } catch (Exception $e) {
            echo "Error loading backup history: " . $e->getMessage() . "\n";
            return null;
        }
    }

    /**
     * Save current timestamp as last backup time
     */

private function saveBackupTime(): void {
    try {
        $data = [
            'last_backup' => date('Y-m-d H:i:s') // Store in Asia/Kolkata timezone
        ];
        file_put_contents($this->historyFile, json_encode($data));
    } catch (Exception $e) {
        echo "Error saving backup history: " . $e->getMessage() . "\n";
    }
}


    /**
     * Get list of files changed since the given datetime
     */
    private function getChangedFiles(?string $sinceDateTime = null): array {
        $changedFiles = [];
        
        try {
            // Change to repository directory
            $currentDir = getcwd();
            chdir($this->repoPath);

            if ($sinceDateTime === null) {
                // Get all tracked files if no datetime provided
                exec('git ls-files', $output);
                $changedFiles = $output;
            } else {
                // Windows-compatible version without using 'uniq'
                $command = sprintf(
                    'git log --name-only --pretty=format: --since="%s"',
                    $sinceDateTime
                );
                exec($command, $output);
                // Remove empty lines and get unique values using PHP instead of Unix uniq
                $changedFiles = array_unique(array_filter($output));
            }

            // Restore original directory
            chdir($currentDir);
        } catch (Exception $e) {
            echo "Error getting changed files: " . $e->getMessage() . "\n";
        }

        return $changedFiles;
    }

    /**
     * Create directory recursively
     */
    private function createDirectory(string $path): void {
        $path = $this->normalizePath($path);
        if (!file_exists($path)) {
            mkdir($path, 0755, true);
        }
    }

    /**
     * Backup all changed files since last backup to the specified directory
     */
    public function backupChangedFiles(string $backupDir, int $projectId = null): ?bool {
        try {
            $this->lastOutput = '';  // Reset output
            $backupDir = $this->normalizePath($backupDir);
            $lastBackup = $this->loadLastBackupTime();
            $changedFiles = $this->getChangedFiles($lastBackup);

            if (empty($changedFiles)) {
                $this->lastOutput = "No changes detected since last backup.\n";
                return false;
            }

            $timestamp = date('Y-m-d_H-i-s');
            $backupPath = $backupDir . DIRECTORY_SEPARATOR . "backup_" . $timestamp;
            
            $this->createDirectory($backupPath);

            $copyCount = 0;
            $totalSize = 0;
            $copiedFiles = [];

            foreach ($changedFiles as $file) {
                $sourcePath = $this->repoPath . DIRECTORY_SEPARATOR . $file;
                if (file_exists($sourcePath) && !is_dir($sourcePath)) {
                    $targetPath = $backupPath . DIRECTORY_SEPARATOR . $file;
                    $this->createDirectory(dirname($targetPath));
                    if (copy($sourcePath, $targetPath)) {
                        $copyCount++;
                        $fileSize = filesize($sourcePath);
                        $totalSize += $fileSize;
                        $copiedFiles[] = [
                            'name' => $file,
                            'size' => $this->formatSize($fileSize)
                        ];
                    }
                }
            }

            if ($projectId !== null && $copyCount > 0) {
                $this->saveBackupToDatabase($projectId, $backupPath, $copyCount, $totalSize);
            }

            if ($copyCount > 0) {
                $this->saveBackupTime();
                
                // Build the output string
                $output = "\nBackup completed: $copyCount files copied to $backupPath\n\n";
                $output .= "Files backed up:\n";
                $output .= str_repeat('-', 80) . "\n";
                $output .= sprintf("%-60s %20s\n", "File Path", "Size");
                $output .= str_repeat('-', 80) . "\n";
                
                // Sort files by name
                usort($copiedFiles, function($a, $b) {
                    return strcmp($a['name'], $b['name']);
                });
                
                foreach ($copiedFiles as $file) {
                    $output .= sprintf("%-60s %20s\n", 
                        strlen($file['name']) > 59 ? '...' . substr($file['name'], -56) : $file['name'],
                        $file['size']
                    );
                }
                
                $output .= str_repeat('-', 80) . "\n";
                $output .= sprintf("%60s %20s\n", 
                    "Total Files: " . $copyCount, 
                    "Total Size: " . $this->formatSize($totalSize)
                );

                $this->lastOutput = $output;
                return true;
            }
            
            return false;

        } catch (Exception $e) {
            $this->lastOutput = "Error during backup process: " . $e->getMessage() . "\n";
            return null;
        }
    }

    private function formatSize($bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    private function saveBackupToDatabase(int $projectId, string $backupPath, int $filesCount, int $totalSize): void {
        try {
            require_once 'db_config.php';
            $conn = getDbConnection();
            
            $stmt = $conn->prepare("INSERT INTO backup_history (project_id, backup_path, files_count, backup_size) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isii", $projectId, $backupPath, $filesCount, $totalSize);
            $stmt->execute();
            
            $stmt->close();
            $conn->close();
        } catch (Exception $e) {
            echo "Warning: Could not save backup to database: " . $e->getMessage() . "\n";
        }
    }
    
}

// Command line interface
if (PHP_SAPI === 'cli') {
    // Parse command line arguments
    $options = getopt('', ['repo:', 'backup-dir:']);
    
    $repoPath = $options['repo'] ?? '.';
    $backupDir = $options['backup-dir'] ?? './backups';

    try {
        $tracker = new GitChangesTracker($repoPath);
        $tracker->backupChangedFiles($backupDir);
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

?>

