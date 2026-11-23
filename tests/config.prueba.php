<?php
// Configuración usada solo por las pruebas automáticas.
$base = require __DIR__ . '/../public/config/config.example.php';
return array_replace($base, [
    'url_sitio' => 'http://127.0.0.1:' . (getenv('PUERTO_SITIO') ?: '8090'),
    'mp_api_url' => 'http://127.0.0.1:' . (getenv('PUERTO_MP') ?: '8091'),
    'mp_access_token' => 'TEST-pruebas',
    'admin_clave_hash' => password_hash('clave-de-prueba', PASSWORD_DEFAULT),
    'correo_remitente' => 'pruebas@example.com',
    'transferencia' => array_replace($base['transferencia'], [
        'titular' => 'Titular Prueba', 'rut' => '11.111.111-1', 'banco' => 'Banco Prueba',
        'tipo' => 'Cuenta vista', 'numero' => '123',
    ]),
    'ruta_db' => getenv('DOMICFIT_DB') ?: sys_get_temp_dir() . '/domicfit-pruebas.sqlite',
]);
