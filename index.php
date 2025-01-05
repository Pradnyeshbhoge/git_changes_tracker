<?php
session_start();
require_once 'git-changes-tracker-php.php';
require_once 'db_config.php';

// Move the formatSize function definition to the top of the file, after the requires
function formatSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_project'])) {
        $conn = getDbConnection();
        
        // Prepare the SQL statement
        $stmt = $conn->prepare("INSERT INTO projects (name, repo_path, backup_path) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $_POST['project_name'], $_POST['repo_path'], $_POST['backup_path']);
        
        // Execute the SQL statement
        if ($stmt->execute()) {
            $projectId = $conn->insert_id; // Get the new project ID
            
            // Continue with the existing JSON file storage
            $projects = json_decode(file_get_contents('projects.json'), true) ?? [];
            $projects[] = [
                'id' => $projectId, // Store the ID
                'name' => $_POST['project_name'],
                'repo_path' => $_POST['repo_path'],
                'backup_path' => $_POST['backup_path']
            ];
            file_put_contents('projects.json', json_encode($projects, JSON_PRETTY_PRINT));
            $_SESSION['success_message'] = "Project added successfully!";
        } else {
            $_SESSION['error_message'] = "Error adding project to database.";
        }
        
        $stmt->close();
        $conn->close();
    } elseif (isset($_POST['delete_project'])) {
        $conn = getDbConnection();
        $projects = json_decode(file_get_contents('projects.json'), true) ?? [];
        $project = $projects[$_POST['project_index']];
        
        // Delete from database
        $stmt = $conn->prepare("DELETE FROM projects WHERE name = ? AND repo_path = ? AND backup_path = ?");
        $stmt->bind_param("sss", $project['name'], $project['repo_path'], $project['backup_path']);
        $stmt->execute();
        
        // Continue with existing JSON file deletion
        unset($projects[$_POST['project_index']]);
        $projects = array_values($projects);
        file_put_contents('projects.json', json_encode($projects, JSON_PRETTY_PRINT));
        
        $stmt->close();
        $conn->close();
    } elseif (isset($_POST['backup_project'])) {
        $projects = json_decode(file_get_contents('projects.json'), true) ?? [];
        $project = $projects[$_POST['project_index']];
        
        $tracker = new GitChangesTracker($project['repo_path']);
        ob_start();
        $result = $tracker->backupChangedFiles($project['backup_path'], $project['id']); // Pass the project ID
        $output = ob_get_clean();
        
        if ($result === false) {
            $_SESSION['backup_message'] = "No changes detected since last backup.";
        } else {
            $_SESSION['backup_message'] = $output;
        }
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Load projects from database
$conn = getDbConnection();
$result = $conn->query("SELECT * FROM projects ORDER BY created_at DESC");
$dbProjects = [];
if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $dbProjects[] = [
            'id' => $row['id'], // Include the ID
            'name' => $row['name'],
            'repo_path' => $row['repo_path'],
            'backup_path' => $row['backup_path']
        ];
    }
    // Sync with JSON file
    file_put_contents('projects.json', json_encode($dbProjects, JSON_PRETTY_PRINT));
}
$conn->close();

// Use the projects from database
$projects = $dbProjects;

// Add success/error messages display
if (isset($_SESSION['success_message'])) {
    echo '<div class="alert alert-success">' . $_SESSION['success_message'] . '</div>';
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    echo '<div class="alert alert-danger">' . $_SESSION['error_message'] . '</div>';
    unset($_SESSION['error_message']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Git Changes Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .project-card {
            transition: transform 0.2s;
        }
        .project-card:hover {
            transform: translateY(-5px);
        }
        .backup-item {
        display: flex;
        align-items: center;
        padding: 4px 0;
        border-bottom: 1px solid #eee;
    }
    .backup-item:last-child {
        border-bottom: none;
    }
    .backup-icon {
        margin-right: 8px;
    }
    .backup-date {
        color: #666;
        font-size: 0.9em;
    }
    @keyframes fadeOut {
        0% { opacity: 1; }
        70% { opacity: 1; }
        100% { opacity: 0; }
    }
    </style>
</head>
<body class="bg-light">
    <div class="container py-5">
        <h1 class="mb-4 text-center">Project Changes Tracker</h1>

        <!-- Add New Project Form -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Add New Project</h5>
            </div>
            <div class="card-body">
                <form action="" method="POST">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Project Name</label>
                            <input type="text" class="form-control" name="project_name" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Repository Path</label>
                            <input type="text" class="form-control" name="repo_path" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Backup Path</label>
                            <input type="text" class="form-control" name="backup_path" required>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" name="add_project" class="btn btn-primary">Add Project</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Backup Message -->
        <?php if (isset($_SESSION['backup_message'])): ?>
            <div class="alert alert-info" role="alert">
                <pre class="mb-0"><?php echo htmlspecialchars($_SESSION['backup_message']); ?></pre>
            </div>
            <?php unset($_SESSION['backup_message']); ?>
        <?php endif; ?>
        <!-- Projects Grid -->
        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
            <?php foreach ($projects as $index => $project): ?>
                <div class="col">
                    <div class="card h-100 project-card">
                        <div class="card-body">
                            <h5 class="card-title"><?php echo htmlspecialchars($project['name']); ?></h5>
                            <input type="hidden" name="project_id" value="<?php echo $project['id']; ?>">
                            <p class="card-text">
                                <small class="text-muted">
                                    <strong>Repo:</strong> <?php echo htmlspecialchars($project['repo_path']); ?><br>
                                    <strong>Backup:</strong> <?php echo htmlspecialchars($project['backup_path']); ?>
                                </small>
                            </p>
                            
                            <!-- Backup History from Database -->
                            <?php
                            $conn = getDbConnection();
                            $stmt = $conn->prepare("SELECT * FROM backup_history WHERE project_id = ? ORDER BY created_at DESC LIMIT 5");
                            $stmt->bind_param("i", $project['id']);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            
                            if ($result->num_rows > 0) {
                                echo '<div class="backup-history mb-3">';
                                echo '<strong>Recent Backups:</strong>';
                                echo '<ul class="list-unstyled small mt-1">';
                                while ($backup = $result->fetch_assoc()) {
                                    $size = formatSize($backup['backup_size']);
                                    echo "<li class='backup-item'>";
                                    echo "<span class='backup-icon'>📁</span> ";
                                    echo date('Y-m-d H:i:s', strtotime($backup['created_at']));
                                    echo " ({$backup['files_count']} files, {$size})";
                                    echo "<div class='ms-auto'>";
                                    echo "<button onclick='copyToClipboard(\"" . addslashes($backup['backup_path']) . "\")' 
                                            class='btn btn-outline-secondary btn-sm py-0 me-2' title='Copy Path'>
                                            📋 Copy
                                        </button>";
                                    echo "<a href='javascript:void(0)' 
                                            onclick='openInExplorer(\"" . addslashes($backup['backup_path']) . "\")' 
                                            class='btn btn-outline-primary btn-sm py-0' title='Open in Explorer'>
                                            📂 Open
                                        </a>";
                                    echo "</div>";
                                    echo "</li>";
                                }
                                echo '</ul>';
                                echo '</div>';
                            }
                            $stmt->close();
                            $conn->close();
                            ?>

                            <div class="d-flex gap-2">
                                <form action="" method="POST" class="d-inline">
                                    <input type="hidden" name="project_index" value="<?php echo $index; ?>">
                                    <button type="submit" name="backup_project" class="btn btn-success btn-sm">
                                        Backup Now
                                    </button>
                                </form>
                                <form action="" method="POST" class="d-inline">
                                    <input type="hidden" name="project_index" value="<?php echo $index; ?>">
                                    <button type="submit" name="delete_project" class="btn btn-danger btn-sm" 
                                            onclick="return confirm('Are you sure you want to delete this project?')">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function openInExplorer(path) {
        fetch('open_folder.php?path=' + encodeURIComponent(path))
            .then(response => response.text())
            .then(data => console.log(data))
            .catch(error => console.error('Error:', error));
    }

    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(() => {
            // Show a temporary tooltip or notification
            const tooltip = document.createElement('div');
            tooltip.textContent = 'Path copied!';
            tooltip.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: #28a745;
                color: white;
                padding: 10px 20px;
                border-radius: 5px;
                z-index: 1000;
                animation: fadeOut 2s forwards;
            `;
            document.body.appendChild(tooltip);
            
            // Remove the tooltip after animation
            setTimeout(() => {
                tooltip.remove();
            }, 2000);
        }).catch(err => {
            console.error('Failed to copy text: ', err);
        });
    }
    </script>
</body>
</html> 