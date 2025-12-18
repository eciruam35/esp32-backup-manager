<?php
// sources/app.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backup.php';

// Session et sécurité
session_start();
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('Location: login.php');
    exit;
}

$config = new ESP32Config();
$backup = new ESP32Backup($config);

// Actions
$action = $_GET['action'] ?? '';
$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($action) {
        case 'test_connection':
            if ($backup->testFTPConnection()) {
                $message = "✓ Connexion FTP réussie!";
                $success = true;
            } else {
                $message = "✗ Échec de connexion FTP";
            }
            break;
            
        case 'manual_backup':
            $result = $backup->manualBackup();
            $message = $result['message'];
            $success = $result['success'];
            break;
            
        case 'update_config':
            $new_config = $_POST;
            if ($config->save($new_config)) {
                $message = "Configuration mise à jour";
                $success = true;
                // Recharger la config
                $config = new ESP32Config();
                $backup = new ESP32Backup($config);
            } else {
                $message = "Erreur de sauvegarde";
            }
            break;
            
        case 'delete_backup':
            $filename = $_POST['filename'];
            if ($backup->deleteBackup($filename)) {
                $message = "Backup supprimé";
                $success = true;
            } else {
                $message = "Erreur suppression";
            }
            break;
    }
}

// Récupérer les données
$backups_list = $backup->listBackups();
$backup_stats = $backup->getStatistics();
$system_info = $backup->getSystemInfo();
$cron_status = $backup->getCronStatus();

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ESP32 Backup Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .card {
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .status-badge {
            font-size: 0.8em;
            padding: 3px 8px;
        }
        .backup-item {
            border-left: 4px solid #0d6efd;
            margin-bottom: 10px;
        }
        .progress-thin {
            height: 5px;
        }
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-microchip"></i> ESP32 Backup Manager
            </a>
            <span class="navbar-text">
                <i class="fas fa-sync-alt"></i> Dernière sync: <?= date('H:i:s') ?>
            </span>
        </div>
    </nav>
    
    <div class="container mt-4">
        <?php if ($message): ?>
            <div class="alert alert-<?= $success ? 'success' : 'danger' ?> alert-dismissible fade show">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Dashboard -->
        <div class="row">
            <div class="col-md-3">
                <div class="card stats-card">
                    <div class="card-body">
                        <h6><i class="fas fa-database"></i> Backups</h6>
                        <h2><?= $backup_stats['total_backups'] ?></h2>
                        <small><?= $backup_stats['total_size'] ?> MB</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h6><i class="fas fa-history"></i> Rétention</h6>
                        <h2><?= $config->get('retention_days') ?> jours</h2>
                        <small>Prochain nettoyage: <?= $backup_stats['next_cleanup'] ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h6><i class="fas fa-clock"></i> Intervalle</h6>
                        <h2><?= ucfirst($config->get('backup_interval')) ?></h2>
                        <small>Prochain: <?= $cron_status['next_run'] ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-dark">
                    <div class="card-body">
                        <h6><i class="fas fa-hdd"></i> Espace</h6>
                        <h2><?= $system_info['free_space'] ?> GB</h2>
                        <div class="progress progress-thin">
                            <div class="progress-bar" style="width: <?= $system_info['usage_percent'] ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Actions rapides -->
        <div class="card mt-4">
            <div class="card-header">
                <i class="fas fa-bolt"></i> Actions Rapides
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <form method="post" action="?action=test_connection" class="d-inline">
                            <button type="submit" class="btn btn-outline-primary w-100">
                                <i class="fas fa-wifi"></i> Tester FTP
                            </button>
                        </form>
                    </div>
                    <div class="col-md-3">
                        <form method="post" action="?action=manual_backup" class="d-inline">
                            <button type="submit" class="btn btn-outline-success w-100">
                                <i class="fas fa-play"></i> Backup Maintenant
                            </button>
                        </form>
                    </div>
                    <div class="col-md-3">
                        <a href="#config" class="btn btn-outline-info w-100">
                            <i class="fas fa-cog"></i> Configurer
                        </a>
                    </div>
                    <div class="col-md-3">
                        <a href="logs.php" class="btn btn-outline-secondary w-100">
                            <i class="fas fa-file-alt"></i> Voir Logs
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Liste des Backups -->
        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-archive"></i> Backups Disponibles</span>
                <span class="badge bg-primary"><?= count($backups_list) ?></span>
            </div>
            <div class="card-body">
                <?php if (empty($backups_list)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Aucun backup disponible
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Nom</th>
                                    <th>Date</th>
                                    <th>Taille</th>
                                    <th>Fichiers</th>
                                    <th>Statut</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($backups_list as $backup_item): ?>
                                <tr>
                                    <td>
                                        <i class="fas fa-file-archive text-primary"></i>
                                        <?= htmlspecialchars($backup_item['name']) ?>
                                    </td>
                                    <td><?= $backup_item['date'] ?></td>
                                    <td><?= $backup_item['size'] ?> MB</td>
                                    <td><?= $backup_item['file_count'] ?></td>
                                    <td>
                                        <span class="badge bg-<?= $backup_item['status_color'] ?>">
                                            <?= $backup_item['status'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="download.php?file=<?= urlencode($backup_item['name']) ?>" 
                                               class="btn btn-outline-primary">
                                                <i class="fas fa-download"></i>
                                            </a>
                                            <button type="button" class="btn btn-outline-info" 
                                                    onclick="showBackupInfo('<?= $backup_item['name'] ?>')">
                                                <i class="fas fa-info"></i>
                                            </button>
                                            <form method="post" action="?action=delete_backup" class="d-inline"
                                                  onsubmit="return confirm('Supprimer ce backup?');">
                                                <input type="hidden" name="filename" value="<?= $backup_item['name'] ?>">
                                                <button type="submit" class="btn btn-outline-danger">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Configuration -->
        <div class="card mt-4" id="config">
            <div class="card-header">
                <i class="fas fa-cog"></i> Configuration
            </div>
            <div class="card-body">
                <form method="post" action="?action=update_config">
                    <div class="row">
                        <div class="col-md-6">
                            <h5>Connexion FTP</h5>
                            <div class="mb-3">
                                <label class="form-label">Hôte ESP32</label>
                                <input type="text" name="ftp_host" class="form-control" 
                                       value="<?= htmlspecialchars($config->get('ftp_host')) ?>" required>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="form-label">Port</label>
                                    <input type="number" name="ftp_port" class="form-control" 
                                           value="<?= $config->get('ftp_port') ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Utilisateur</label>
                                    <input type="text" name="ftp_user" class="form-control" 
                                           value="<?= htmlspecialchars($config->get('ftp_user')) ?>" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Mot de passe</label>
                                <input type="password" name="ftp_password" class="form-control" 
                                       value="<?= htmlspecialchars($config->get('ftp_password')) ?>" required>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <h5>Paramètres Backup</h5>
                            <div class="mb-3">
                                <label class="form-label">Intervalle</label>
                                <select name="backup_interval" class="form-select">
                                    <option value="hourly" <?= $config->get('backup_interval') == 'hourly' ? 'selected' : '' ?>>Chaque heure</option>
                                    <option value="daily" <?= $config->get('backup_interval') == 'daily' ? 'selected' : '' ?>>Quotidien</option>
                                    <option value="weekly" <?= $config->get('backup_interval') == 'weekly' ? 'selected' : '' ?>>Hebdomadaire</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Rétention (jours)</label>
                                <input type="number" name="retention_days" class="form-control" 
                                       value="<?= $config->get('retention_days') ?>" min="1" max="365">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Dossier de sauvegarde</label>
                                <input type="text" class="form-control" value="/home/yunohost.backup/archives/esp32-backup" disabled>
                                <small class="text-muted">Géré automatiquement par Yunohost</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Enregistrer
                        </button>
                        <button type="reset" class="btn btn-outline-secondary">Annuler</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Cron Status -->
        <div class="card mt-4">
            <div class="card-header">
                <i class="fas fa-clock"></i> Planificateur
            </div>
            <div class="card-body">
                <pre class="bg-light p-3 rounded"><?= htmlspecialchars($cron_status['cron_line']) ?></pre>
                <p class="text-muted">
                    <i class="fas fa-info-circle"></i> 
                    Le cron est automatiquement géré par Yunohost. 
                    Prochaine exécution: <?= $cron_status['next_run'] ?>
                </p>
            </div>
        </div>
    </div>
    
    <!-- Modal pour les infos backup -->
    <div class="modal fade" id="backupInfoModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Détails du Backup</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="backupInfoContent">
                    Chargement...
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function showBackupInfo(filename) {
        fetch('backup_info.php?file=' + encodeURIComponent(filename))
            .then(response => response.text())
            .then(html => {
                document.getElementById('backupInfoContent').innerHTML = html;
                new bootstrap.Modal(document.getElementById('backupInfoModal')).show();
            });
    }
    
    // Auto-refresh toutes les 60 secondes
    setInterval(() => {
        fetch('status.php')
            .then(response => response.json())
            .then(data => {
                if (data.need_refresh) {
                    window.location.reload();
                }
            });
    }, 60000);
    </script>
</body>
</html>