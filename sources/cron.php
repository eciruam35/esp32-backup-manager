<?php
// sources/cron.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backup.php';

// Démarrer le logging
openlog("esp32-backup-cron", LOG_PID | LOG_PERROR, LOG_LOCAL0);

try {
    $config = new ESP32Config();
    $backup = new ESP32Backup($config);
    
    syslog(LOG_INFO, "Démarrage du backup planifié");
    
    $result = $backup->scheduledBackup();
    
    if ($result['success']) {
        syslog(LOG_INFO, "Backup réussi: " . $result['message']);
        echo "SUCCESS: " . $result['message'] . "\n";
        exit(0);
    } else {
        syslog(LOG_ERR, "Backup échoué: " . $result['message']);
        echo "ERROR: " . $result['message'] . "\n";
        exit(1);
    }
    
} catch (Exception $e) {
    syslog(LOG_CRIT, "Exception: " . $e->getMessage());
    echo "CRITICAL: " . $e->getMessage() . "\n";
    exit(2);
}

closelog();