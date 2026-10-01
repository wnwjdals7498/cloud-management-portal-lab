<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$configPath = $root . '/.runtime/local.json';
if (is_file($configPath)) {
    $config = json_decode(file_get_contents($configPath), true, 64, JSON_THROW_ON_ERROR);
} else {
    $config = [
        'mode' => 'simulated', 'release' => 'prototype-p0',
        'mysql_port' => 33307, 'root_password' => bin2hex(random_bytes(24)),
        'proxy_secret' => bin2hex(random_bytes(32)), 'simulator_secret' => bin2hex(random_bytes(32)),
        'api_db' => 'portal_lab', 'sim_db' => 'portal_sim',
        'api_user' => 'portal_app', 'api_password' => bin2hex(random_bytes(24)),
        'sim_user' => 'portal_simulator', 'sim_password' => bin2hex(random_bytes(24)),
        'test_db' => 'portal_lab_test', 'sim_test_db' => 'portal_sim_test',
        'test_user' => 'portal_tester', 'test_password' => bin2hex(random_bytes(24)),
        'sim_test_user' => 'portal_sim_tester', 'sim_test_password' => bin2hex(random_bytes(24)),
        'seed_password' => bin2hex(random_bytes(12)), 'active_vm_limit' => 1,
        'api_url' => 'http://127.0.0.1:18100', 'simulator_url' => 'http://127.0.0.1:18200',
        'customer_origin' => 'http://customer.localhost:18080', 'admin_origin' => 'http://admin.localhost:18080',
        'simulation' => [
            'profile_version' => 'prototype-v1', 'seed' => 'portal-lab-local',
            'weights' => ['success' => 70, 'failure' => 15, 'late' => 5, 'missing' => 5, 'duplicate' => 5],
            'delay_min_seconds' => 2, 'delay_max_seconds' => 15,
            'late_min_seconds' => 30, 'late_max_seconds' => 60,
            'duplicate_delay_seconds' => 2, 'maximum_pending' => 100,
            'error_codes' => ['SIM_IMAGE_UNAVAILABLE', 'SIM_STORAGE_FULL', 'SIM_EXECUTION_FAILED'],
        ],
    ];
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli('127.0.0.1', 'root', $config['root_password'], '', $config['mysql_port']);
} catch (mysqli_sql_exception $e) {
    if (is_file($configPath)) { throw new RuntimeException('Local administrator connection failed.'); }
    $db = new mysqli('127.0.0.1', 'root', '', '', $config['mysql_port']);
    $db->query("ALTER USER 'root'@'localhost' IDENTIFIED BY '" . $db->real_escape_string($config['root_password']) . "'");
}
foreach (['api_db', 'sim_db', 'test_db', 'sim_test_db'] as $key) {
    $db->query('CREATE DATABASE IF NOT EXISTS `' . $config[$key] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
foreach (['api', 'sim', 'test', 'sim_test'] as $role) {
    $user = $config[$role . '_user']; $password = $db->real_escape_string($config[$role . '_password']);
    $db->query("CREATE USER IF NOT EXISTS '$user'@'127.0.0.1' IDENTIFIED BY '$password'");
}
file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
file_put_contents($root . '/.runtime/login.txt', "Synthetic local accounts only\nadmin@example.test\nmembera@example.test\nmemberb@example.test\nPassword: " . $config['seed_password'] . "\n");
foreach (['api', 'vmm-simulator', 'customer-console', 'admin-console'] as $app) {
    $path = $root . '/apps/' . $app;
    $composer = json_decode(file_get_contents($path . '/composer.json'), true, 64, JSON_THROW_ON_ERROR);
    $composer['autoload']['psr-4']['Portal\\Shared\\'] = '../../packages/core/src/';
    file_put_contents($path . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    $api = $app !== 'vmm-simulator';
    $environment = "CI_ENVIRONMENT = development\napp.baseURL = '" . $config[$api ? 'api_url' : 'simulator_url'] . "/'\n";
    $environment .= 'database.default.hostname = 127.0.0.1' . "\n";
    $environment .= 'database.default.database = ' . $config[$api ? 'api_db' : 'sim_db'] . "\n";
    $environment .= 'database.default.username = ' . $config[$api ? 'api_user' : 'sim_user'] . "\n";
    $environment .= 'database.default.password = ' . $config[$api ? 'api_password' : 'sim_password'] . "\n";
    $environment .= "database.default.DBDriver = MySQLi\ndatabase.default.port = " . $config['mysql_port'] . "\n";
    if (str_contains($app, 'console')) {
        $environment = "CI_ENVIRONMENT = development\napp.baseURL = '" . $config[$app === 'customer-console' ? 'customer_origin' : 'admin_origin'] . "/'\n";
    }
    file_put_contents($path . '/.env', $environment);
}
echo "Local configuration and isolated development/test databases ready.\n";
