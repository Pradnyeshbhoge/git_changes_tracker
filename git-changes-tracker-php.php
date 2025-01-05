<?php
    date_default_timezone_set('Asia/Kolkata'); // Ensure consistent timezone

class GitChangesTracker {
    private string $repoPath;
    private string $historyFile;

    public function __construct(string $repoPath) {
        $this->repoPath = rtrim($repoPath, '/');
        $this->historyFile = $this->repoPath . '/.git_changes_history.json';
        
        // Initialize project (add to .gitignore)
        $this->initializeProject();
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
        if (!file_exists($path)) {
            mkdir($path, 0777, true);
        }
    }

    /**
     * Backup all changed files since last backup to the specified directory
     */
    public function backupChangedFiles(string $backupDir, int $projectId = null): ?bool {
        try {
            $lastBackup = $this->loadLastBackupTime();
            $changedFiles = $this->getChangedFiles($lastBackup);

            if (empty($changedFiles)) {
                echo "No changes detected since last backup.\n";
                return false;
            }

            // Create backup directory with timestamp
            $timestamp = date('Y-m-d_H-i-s');
            $backupPath = rtrim($backupDir, '/') . "/backup_" . $timestamp;
            
            // Only create directory if there are actual changes
            $this->createDirectory($backupPath);

            // Copy changed files and track total size
            $copyCount = 0;
            $totalSize = 0;
            foreach ($changedFiles as $file) {
                $sourcePath = $this->repoPath . '/' . $file;
                if (file_exists($sourcePath) && !is_dir($sourcePath)) {
                    $targetPath = $backupPath . '/' . $file;
                    $this->createDirectory(dirname($targetPath));
                    if (copy($sourcePath, $targetPath)) {
                        $copyCount++;
                        $totalSize += filesize($sourcePath);
                    }
                }
            }

            // Save backup details to database if project ID is provided
            if ($projectId !== null && $copyCount > 0) {
                $this->saveBackupToDatabase($projectId, $backupPath, $copyCount, $totalSize);
            }

            // Save backup timestamp only if files were copied
            if ($copyCount > 0) {
                $this->saveBackupTime();
                echo "\nBackup completed: $copyCount files copied to $backupPath\n";
                return true;
            }
            
            return false;

        } catch (Exception $e) {
            echo "Error during backup process: " . $e->getMessage() . "\n";
            return null;
        }
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

