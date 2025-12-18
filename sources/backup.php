<?php
// sources/backup.php

class ESP32Backup {
    private $config;
    private $backup_dir;
    private $log_file;
    
    public function __construct($config) {
        $this->config = $config;
        
        // Dossier de backup standard Yunohost
        $this->backup_dir = '/home/yunohost.backup/archives/esp32-backup/';
        
        // Créer le dossier si nécessaire
        if (!file_exists($this->backup_dir)) {
            mkdir($this->backup_dir, 0755, true);
        }
        
        $this->log_file = $this->backup_dir . 'backup.log';
    }
    
    public function testFTPConnection() {
        $host = $this->config->get('ftp_host');
        $port = $this->config->get('ftp_port', 21);
        $user = $this->config->get('ftp_user');
        $pass = $this->config->get('ftp_password');
        
        $this->log("Test de connexion FTP à {$host}:{$port}");
        
        try {
            $ftp = ftp_connect($host, $port, 10);
            if (!$ftp) {
                throw new Exception("Connexion impossible");
            }
            
            if (!@ftp_login($ftp, $user, $pass)) {
                throw new Exception("Authentification échouée");
            }
            
            ftp_pasv($ftp, true);
            
            // Tester la liste des fichiers
            $files = @ftp_nlist($ftp, '/');
            if ($files === false) {
                throw new Exception("Impossible de lister les fichiers");
            }
            
            ftp_close($ftp);
            
            $this->log("✓ Connexion FTP réussie (" . count($files) . " éléments)");
            return true;
            
        } catch (Exception $e) {
            $this->log("✗ Échec FTP: " . $e->getMessage());
            return false;
        }
    }
    
    public function manualBackup() {
        $this->log("=== BACKUP MANUEL DÉMARRÉ ===");
        
        $result = $this->performBackup();
        
        if ($result['success']) {
            $this->config->updateLastBackup(true);
            $this->log("=== BACKUP MANUEL RÉUSSI ===");
        } else {
            $this->config->updateLastBackup(false);
            $this->log("=== BACKUP MANUEL ÉCHOUÉ ===");
        }
        
        return $result;
    }
    
    public function scheduledBackup() {
        $this->log("=== BACKUP PLANIFIÉ DÉMARRÉ ===");
        
        $result = $this->performBackup();
        
        if ($result['success']) {
            $this->config->updateLastBackup(true);
            $this->log("=== BACKUP PLANIFIÉ RÉUSSI ===");
            
            // Nettoyer les vieux backups
            $this->cleanOldBackups();
        } else {
            $this->config->updateLastBackup(false);
            $this->log("=== BACKUP PLANIFIÉ ÉCHOUÉ ===");
        }
        
        return $result;
    }
    
    private function performBackup() {
        $host = $this->config->get('ftp_host');
        $port = $this->config->get('ftp_port', 21);
        $user = $this->config->get('ftp_user');
        $pass = $this->config->get('ftp_password');
        
        try {
            // Connexion
            $ftp = ftp_connect($host, $port, 10);
            if (!$ftp) {
                throw new Exception("Connexion FTP impossible");
            }
            
            if (!ftp_login($ftp, $user, $pass)) {
                throw new Exception("Login FTP échoué");
            }
            
            ftp_pasv($ftp, true);
            $this->log("Connecté à l'ESP32");
            
            // Créer le dossier de backup du jour
            $date = date('Y-m-d_H-i-s');
            $daily_dir = $this->backup_dir . $date . '/';
            mkdir($daily_dir, 0755, true);
            
            // Liste récursive des fichiers
            $files = $this->listFilesRecursive($ftp, '/');
            
            if (empty($files)) {
                throw new Exception("Aucun fichier trouvé sur l'ESP32");
            }
            
            // Télécharger les fichiers
            $downloaded = 0;
            foreach ($files as $remote_file) {
                $local_file = $daily_dir . basename($remote_file);
                
                if ($this->downloadFile($ftp, $remote_file, $local_file)) {
                    $this->log("✓ " . $remote_file);
                    $downloaded++;
                } else {
                    $this->log("✗ " . $remote_file);
                }
            }
            
            ftp_close($ftp);
            
            // Créer l'archive ZIP
            $zip_path = $this->createZip($daily_dir, $date);
            
            // Supprimer le dossier temporaire
            $this->deleteDirectory($daily_dir);
            
            return [
                'success' => true,
                'message' => "Backup réussi: {$downloaded}/" . count($files) . " fichiers",
                'files_downloaded' => $downloaded,
                'total_files' => count($files),
                'zip_path' => $zip_path,
                'zip_size' => filesize($zip_path)
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => "Erreur: " . $e->getMessage()
            ];
        }
    }
    
    private function listFilesRecursive($ftp, $directory) {
        $files = [];
        $items = ftp_nlist($ftp, $directory);
        
        foreach ($items as $item) {
            // Ignorer les fichiers système
            if (strpos(basename($item), '.') === 0) {
                continue;
            }
            
            // Vérifier si c'est un dossier
            if (@ftp_chdir($ftp, $item)) {
                ftp_chdir($ftp, '..');
                // Pour les dossiers, on ne descend pas récursivement
                // (modifier si nécessaire)
                $files[] = $item;
            } else {
                $files[] = $item;
            }
        }
        
        return $files;
    }
    
    private function downloadFile($ftp, $remote, $local) {
        return @ftp_get($ftp, $local, $remote, FTP_BINARY);
    }
    
    private function createZip($source, $date) {
        $zip_path = $this->backup_dir . 'esp32_backup_' . $date . '.zip';
        $zip = new ZipArchive();
        
        if ($zip->open($zip_path, ZipArchive::CREATE) === TRUE) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            
            foreach ($files as $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen($source));
                    $zip->addFile($filePath, $relativePath);
                }
            }
            
            $zip->close();
            return $zip_path;
        }
        
        return false;
    }
    
    public function listBackups() {
        $backups = [];
        $files = glob($this->backup_dir . 'esp32_backup_*.zip');
        
        arsort($files); // Plus récent d'abord
        
        foreach ($files as $file) {
            $backups[] = [
                'name' => basename($file),
                'path' => $file,
                'size' => round(filesize($file) / 1024 / 1024, 2),
                'date' => date('Y-m-d H:i:s', filemtime($file)),
                'file_count' => $this->countZipFiles($file),
                'status' => 'Complet',
                'status_color' => 'success'
            ];
        }
        
        return $backups;
    }
    
    private function countZipFiles($zip_path) {
        $zip = new ZipArchive();
        if ($zip->open($zip_path) === TRUE) {
            $count = $zip->numFiles;
            $zip->close();
            return $count;
        }
        return 0;
    }
    
    public function deleteBackup($filename) {
        $filepath = $this->backup_dir . $filename;
        if (file_exists($filepath)) {
            $this->log("Suppression backup: " . $filename);
            return unlink($filepath);
        }
        return false;
    }
    
    private function cleanOldBackups() {
        $retention_days = $this->config->get('retention_days', 30);
        $cutoff_time = time() - ($retention_days * 24 * 60 * 60);
        
        $files = glob($this->backup_dir . 'esp32_backup_*.zip');
        $deleted = 0;
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff_time) {
                if (unlink($file)) {
                    $this->log("Nettoyage: " . basename($file));
                    $deleted++;
                }
            }
        }
        
        if ($deleted > 0) {
            $this->log("Nettoyage terminé: {$deleted} vieux backups supprimés");
        }
    }
    
    public function getStatistics() {
        $files = glob($this->backup_dir . 'esp32_backup_*.zip');
        $total_size = 0;
        
        foreach ($files as $file) {
            $total_size += filesize($file);
        }
        
        return [
            'total_backups' => count($files),
            'total_size' => round($total_size / 1024 / 1024, 2),
            'next_cleanup' => date('Y-m-d', time() + ($this->config->get('retention_days', 30) * 24 * 60 * 60))
        ];
    }
    
    public function getSystemInfo() {
        $backup_dir = $this->backup_dir;
        $total_space = disk_total_space($backup_dir);
        $free_space = disk_free_space($backup_dir);
        $used_space = $total_space - $free_space;
        
        return [
            'total_space' => round($total_space / 1024 / 1024 / 1024, 2),
            'free_space' => round($free_space / 1024 / 1024 / 1024, 2),
            'used_space' => round($used_space / 1024 / 1024 / 1024, 2),
            'usage_percent' => $total_space > 0 ? round(($used_space / $total_space) * 100, 1) : 0
        ];
    }
    
    public function getCronStatus() {
        $interval = $this->config->get('backup_interval', 'daily');
        
        // Générer la ligne cron selon l'intervalle
        switch ($interval) {
            case 'hourly':
                $cron_line = "0 * * * * php " . __DIR__ . "/cron.php";
                $next_run = "dans l'heure";
                break;
            case 'weekly':
                $cron_line = "0 2 * * 0 php " . __DIR__ . "/cron.php";
                $next_run = "dimanche prochain à 02:00";
                break;
            case 'daily':
            default:
                $cron_line = "0 2 * * * php " . __DIR__ . "/cron.php";
                $next_run = "demain à 02:00";
                break;
        }
        
        return [
            'cron_line' => $cron_line,
            'interval' => $interval,
            'next_run' => $next_run
        ];
    }
    
    private function log($message) {
        $timestamp = date('[Y-m-d H:i:s] ');
        $log_entry = $timestamp . $message . "\n";
        
        // Écrire dans le fichier log
        file_put_contents($this->log_file, $log_entry, FILE_APPEND);
        
        // Écrire aussi dans syslog pour Yunohost
        syslog(LOG_INFO, "ESP32-Backup: " . $message);
    }
    
    private function deleteDirectory($dir) {
        if (!file_exists($dir)) return true;
        
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            is_dir("$dir/$file") ? $this->deleteDirectory("$dir/$file") : unlink("$dir/$file");
        }
        
        return rmdir($dir);
    }
}