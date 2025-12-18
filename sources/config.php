<?php
// sources/config.php

class ESP32Config {
    private $config_file;
    private $settings = [];
    
    public function __construct() {
        // Chemin du fichier config dans le dossier de l'application Yunohost
        $this->config_file = dirname(__DIR__) . '/../config/esp32_backup.json';
        $this->load();
    }
    
    private function load() {
        if (file_exists($this->config_file)) {
            $json = file_get_contents($this->config_file);
            $this->settings = json_decode($json, true) ?: [];
        } else {
            // Valeurs par défaut
            $this->settings = [
                'ftp_host' => '',
                'ftp_port' => 21,
                'ftp_user' => '',
                'ftp_password' => '',
                'backup_interval' => 'daily',
                'retention_days' => 30,
                'last_backup' => null,
                'backup_count' => 0
            ];
            $this->save();
        }
    }
    
    public function save($new_settings = null) {
        if ($new_settings) {
            $this->settings = array_merge($this->settings, $new_settings);
        }
        
        // S'assurer que le dossier existe
        $config_dir = dirname($this->config_file);
        if (!file_exists($config_dir)) {
            mkdir($config_dir, 0755, true);
        }
        
        return file_put_contents($this->config_file, 
            json_encode($this->settings, JSON_PRETTY_PRINT));
    }
    
    public function get($key, $default = null) {
        return $this->settings[$key] ?? $default;
    }
    
    public function getAll() {
        return $this->settings;
    }
    
    public function updateLastBackup($success = true) {
        $this->settings['last_backup'] = date('Y-m-d H:i:s');
        $this->settings['last_backup_success'] = $success;
        if ($success) {
            $this->settings['backup_count'] = ($this->settings['backup_count'] ?? 0) + 1;
        }
        return $this->save();
    }
}