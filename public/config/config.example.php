<?php
/**
 * Configuración de Domicfit.
 *
 * 1. Copia este archivo como config.php en esta misma carpeta.
 * 2. Completa los datos marcados con "COMPLETAR".
 *
 * config.php NUNCA se sube a GitHub (está en .gitignore) porque tiene claves secretas.
 */
return [
    // Dirección pública de la página, sin "/" al final.
    'url_sitio' => 'https://www.domicfit.cl',

    // ---- Mercado Pago ----
    // Panel de Mercado Pago > Tus integraciones > Credenciales.
    // Para probar usa las credenciales de PRUEBA (empiezan con TEST-) y cámbialas por las de producción (APP_USR-) al publicar.
    'mp_access_token' => 'COMPLETAR',

    // ---- Correos ----
    'correo_admin'     => 'domicfit@gmail.com',     // aquí llegan los avisos de compras y reservas
    'correo_remitente' => 'no-responder@domicfit.cl', // crea esta casilla en cPanel o usa una existente del dominio
    'whatsapp'         => '56986558409',             // sin "+" ni espacios

    // ---- Panel de administración (/admin) ----
    // Hash de la contraseña. Se genera con:  php -r "echo password_hash('TU_CLAVE', PASSWORD_DEFAULT);"
    'admin_clave_hash' => 'COMPLETAR',

    // ---- Transferencia ----
    // Si 'activa' es false, la opción de transferencia no aparece al pagar.
    'transferencia' => [
        'activa'  => true,
        'titular' => 'COMPLETAR',
        'rut'     => 'COMPLETAR',
        'banco'   => 'COMPLETAR',
        'tipo'    => 'COMPLETAR', // Cuenta corriente / Cuenta vista / Cuenta RUT
        'numero'  => 'COMPLETAR',
        'correo'  => 'domicfit@gmail.com',
        'horas_para_pagar' => 24, // tiempo que se guarda el horario mientras llega la transferencia
    ],

    // ---- Agenda ----
    'zona_horaria'        => 'America/Santiago',
    'anticipacion_horas'  => 12, // mínimo de horas entre la reserva y la clase
    'dias_maximos'        => 30, // hasta cuántos días hacia adelante se puede reservar
    'minutos_pago_online' => 30, // tiempo que se guarda el horario mientras se paga con Mercado Pago
    'duracion_sesion'     => 60, // minutos

    'agenda' => [
        'sede' => [
            // cuántas reservas se aceptan a la misma hora
            'capacidad' => 1,
            // minutos extra que se dejan libres después de cada clase
            'margen' => 0,
            // 1 = lunes ... 7 = domingo. Horas de inicio de clase.
            'horarios' => [
                1 => ['10:00', '11:00', '20:00', '21:00', '22:00'],
                2 => ['10:00', '11:00', '20:00', '21:00', '22:00'],
                3 => ['10:00', '11:00', '20:00', '21:00', '22:00'],
                4 => ['10:00', '11:00', '20:00', '21:00', '22:00'],
                5 => ['10:00', '11:00', '20:00', '21:00', '22:00'],
            ],
        ],
        'domicilio' => [
            'capacidad' => 1,
            'margen' => 30, // tiempo de traslado entre una casa y otra
            // rangos: [primera hora de inicio, última hora de inicio, cada cuántos minutos]
            'rangos' => [
                1 => ['06:30', '21:00', 30],
                2 => ['06:30', '21:00', 30],
                3 => ['06:30', '21:00', 30],
                4 => ['06:30', '21:00', 30],
                5 => ['06:30', '21:00', 30],
                6 => ['07:00', '13:00', 30],
            ],
        ],
    ],

    // Archivo de la base de datos (se crea solo). Debe quedar en una carpeta protegida.
    'ruta_db' => __DIR__ . '/../privado/domicfit.sqlite',
];
