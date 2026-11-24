<?php
/**
 * Pruebas de la agenda, los pedidos, los pagos y el panel.
 * Se ejecutan con tests/ejecutar.sh, que levanta la página y un Mercado Pago falso.
 */
declare(strict_types=1);
date_default_timezone_set('America/Santiago');

$sitio = 'http://127.0.0.1:' . (getenv('PUERTO_SITIO') ?: '8090');
$rutaDb = getenv('DOMICFIT_DB') ?: sys_get_temp_dir() . '/domicfit-pruebas.sqlite';
$fallos = 0;
$total = 0;

/* ---------- Ayudantes ---------- */

function prueba(string $nombre, callable $fn): void
{
    global $fallos, $total;
    $total++;
    try {
        $fn();
        echo "  ✓ $nombre\n";
    } catch (Throwable $e) {
        $fallos++;
        echo "  ✗ $nombre\n      " . $e->getMessage() . "\n";
    }
}

function igual($esperado, $obtenido, string $que = ''): void
{
    if ($esperado !== $obtenido) {
        throw new RuntimeException(($que ? "$que: " : '') . 'se esperaba ' . var_export($esperado, true) . ' y se obtuvo ' . var_export($obtenido, true));
    }
}

function verdadero(bool $condicion, string $que): void
{
    if (!$condicion) {
        throw new RuntimeException($que);
    }
}

/** Petición HTTP. Devuelve [código, cuerpo decodificado o texto]. */
function http(string $metodo, string $ruta, $cuerpo = null, array $opciones = []): array
{
    global $sitio;
    $ch = curl_init($sitio . $ruta);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_TIMEOUT => 20]);
    if (is_array($cuerpo) && empty($opciones['formulario'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cuerpo));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    } elseif (is_array($cuerpo)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($cuerpo));
    }
    if (!empty($opciones['cookies'])) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $opciones['cookies']);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $opciones['cookies']);
    }
    $texto = (string) curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($texto, true);
    return [$codigo, is_array($json) ? $json : $texto];
}

function db(): PDO
{
    global $rutaDb;
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . $rutaDb);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }
    return $pdo;
}

function pedido(string $ref): array
{
    $st = db()->prepare('SELECT * FROM pedidos WHERE ref = ?');
    $st->execute([$ref]);
    return $st->fetch() ?: [];
}

/** Próxima fecha (a partir de pasado mañana) que cae en el día de la semana indicado (1 = lunes). */
function proximo(int $dia, int $semanasExtra = 0): string
{
    $d = new DateTimeImmutable('today +3 days');
    while ((int) $d->format('N') !== $dia) {
        $d = $d->modify('+1 day');
    }
    return $d->modify("+$semanasExtra weeks")->format('Y-m-d');
}

function libres(string $modalidad, string $fecha): array
{
    [, $r] = http('GET', "/api/disponibilidad.php?modalidad=$modalidad&fecha=$fecha");
    return array_column(array_filter($r['horas'], fn($h) => $h['libre']), 'hora');
}

function cliente(array $extra = []): array
{
    return array_replace(['nombre' => 'Ana Prueba', 'email' => 'ana@example.com', 'telefono' => '+56 9 1111 2222',
        'personas' => 1, 'objetivo' => 'Bajar de peso', 'comuna' => 'Ñuñoa', 'direccion' => 'Irarrázaval 1234'], $extra);
}

function reservar(array $datos): array
{
    return http('POST', '/api/crear-pedido.php', array_replace([
        'items' => [['id' => 'dom-8', 'cantidad' => 1]], 'modalidad' => 'domicilio', 'metodo' => 'transferencia',
        'cliente' => cliente(), 'acepta' => true,
    ], $datos));
}

/* ---------- Pruebas ---------- */

$miercoles = proximo(3);
$jueves = proximo(4);
$viernes = proximo(5);
$sabado = proximo(6);
$domingo = proximo(7);

echo "\nAgenda\n";

prueba('opciones públicas no exponen claves', function () {
    [$c, $r] = http('GET', '/api/opciones.php');
    igual(200, $c);
    igual([1, 2, 3, 4, 5], $r['dias_con_atencion']['sede']);
    igual([1, 2, 3, 4, 5, 6], $r['dias_con_atencion']['domicilio']);
    verdadero(!str_contains(json_encode($r), 'TEST-pruebas'), 'la respuesta incluye el token de Mercado Pago');
});

prueba('la sede muestra sus 5 horarios de lunes a viernes', function () use ($miercoles) {
    igual(['10:00', '11:00', '20:00', '21:00', '22:00'], libres('sede', $miercoles));
});

prueba('a domicilio el sábado la última clase empieza a las 11:00 (cierra a las 12:00)', function () use ($sabado) {
    $horas = libres('domicilio', $sabado);
    igual('07:00', $horas[0]);
    igual('11:00', end($horas));
    igual(9, count($horas));
});

prueba('a domicilio de lunes a viernes la última clase empieza a las 20:00 (cierra a las 21:00)', function () use ($jueves) {
    $horas = libres('domicilio', $jueves);
    igual('06:30', $horas[0]);
    igual('20:00', end($horas));
});

prueba('el domingo no hay horas', function () use ($domingo) {
    igual([], libres('sede', $domingo));
    igual([], libres('domicilio', $domingo));
});

prueba('no se puede reservar con menos de 12 horas de anticipación', function () {
    $pronto = new DateTimeImmutable('+2 hours');
    $minutos = (int) $pronto->format('i') < 30 ? '30' : '00';
    if ($minutos === '00') {
        $pronto = $pronto->modify('+1 hour');
    }
    [$c] = reservar(['fecha' => $pronto->format('Y-m-d'), 'hora' => $pronto->format('H:') . $minutos]);
    igual(409, $c);
});

prueba('no se puede reservar más allá de 30 días', function () {
    $lejos = (new DateTimeImmutable('today +40 days'));
    while ((int) $lejos->format('N') > 5) {
        $lejos = $lejos->modify('+1 day');
    }
    igual([], libres('sede', $lejos->format('Y-m-d')));
});

echo "\nPedidos\n";

$refTransferencia = '';
prueba('una reserva con transferencia usa el precio del servidor, no el del navegador', function () use ($miercoles, &$refTransferencia) {
    [$c, $r] = reservar(['items' => [['id' => 'dom-12', 'cantidad' => 1, 'precio' => 1]], 'fecha' => $miercoles, 'hora' => '10:00']);
    igual(200, $c, 'código');
    $refTransferencia = $r['ref'];
    $p = pedido($refTransferencia);
    igual('pendiente_transferencia', $p['estado']);
    igual(300000, (int) $p['total']);
});

prueba('a domicilio se bloquean las horas que chocan, contando 30 minutos de traslado', function () use ($miercoles) {
    $horas = libres('domicilio', $miercoles);
    foreach (['09:00', '09:30', '10:00', '10:30', '11:00'] as $h) {
        verdadero(!in_array($h, $horas, true), "$h debería estar ocupada");
    }
    foreach (['08:30', '11:30'] as $h) {
        verdadero(in_array($h, $horas, true), "$h debería estar libre");
    }
    [$c] = reservar(['fecha' => $miercoles, 'hora' => '10:30', 'cliente' => cliente(['email' => 'otra@example.com'])]);
    igual(409, $c, 'reservar encima');
});

prueba('la sede y el domicilio tienen agendas separadas', function () use ($miercoles) {
    verdadero(in_array('10:00', libres('sede', $miercoles), true), 'la sede a las 10:00 debería seguir libre');
});

prueba('rechaza mezclar productos de sede y domicilio', function () use ($jueves) {
    [$c, $r] = reservar(['items' => [['id' => 'dom-8', 'cantidad' => 1], ['id' => 'sede-4', 'cantidad' => 1]], 'fecha' => $jueves, 'hora' => '15:00']);
    igual(400, $c);
    verdadero(str_contains($r['error'], 'misma modalidad'), 'mensaje: ' . $r['error']);
});

prueba('la clase de prueba no se mezcla con otros productos', function () use ($jueves) {
    [$c] = reservar(['items' => [['id' => 'prueba-domicilio', 'cantidad' => 1], ['id' => 'dom-8', 'cantidad' => 1]], 'fecha' => $jueves, 'hora' => '15:00']);
    igual(400, $c);
});

prueba('rechaza productos que no existen', function () use ($jueves) {
    [$c] = reservar(['items' => [['id' => 'plan-falso', 'cantidad' => 1]], 'fecha' => $jueves, 'hora' => '15:00']);
    igual(400, $c);
});

prueba('a domicilio exige comuna y dirección', function () use ($jueves) {
    [$c] = reservar(['fecha' => $jueves, 'hora' => '15:00', 'cliente' => cliente(['direccion' => ''])]);
    igual(400, $c);
});

prueba('exige un correo válido', function () use ($jueves) {
    [$c] = reservar(['fecha' => $jueves, 'hora' => '15:00', 'cliente' => cliente(['email' => 'no-es-correo'])]);
    igual(400, $c);
});

prueba('exige aceptar las condiciones', function () use ($jueves) {
    [$c] = reservar(['fecha' => $jueves, 'hora' => '15:00', 'acepta' => false]);
    igual(400, $c);
});

prueba('rechaza a los bots que llenan el campo trampa', function () use ($jueves) {
    [$c] = reservar(['fecha' => $jueves, 'hora' => '15:00', 'website' => 'http://spam.example']);
    igual(400, $c);
});

prueba('la clase de prueba en sede es gratis y queda reservada', function () use ($jueves) {
    [$c, $r] = http('POST', '/api/crear-pedido.php', ['items' => [['id' => 'prueba-sede', 'cantidad' => 1]], 'modalidad' => 'sede',
        'fecha' => $jueves, 'hora' => '20:00', 'cliente' => cliente(['email' => 'luis@example.com', 'telefono' => '933334444']), 'acepta' => true]);
    igual(200, $c, 'código');
    $p = pedido($r['ref']);
    igual('reservado', $p['estado']);
    igual('gratis', $p['metodo']);
});

prueba('solo una clase de prueba gratis pendiente por persona', function () use ($viernes) {
    [$c] = http('POST', '/api/crear-pedido.php', ['items' => [['id' => 'prueba-sede', 'cantidad' => 1]], 'modalidad' => 'sede',
        'fecha' => $viernes, 'hora' => '20:00', 'cliente' => cliente(['email' => 'luis@example.com', 'telefono' => '933334444']), 'acepta' => true]);
    igual(400, $c);
});

prueba('una reserva vencida libera el horario y se informa como vencida', function () use ($viernes) {
    [, $r] = http('POST', '/api/crear-pedido.php', ['items' => [['id' => 'sede-4', 'cantidad' => 1]], 'modalidad' => 'sede', 'metodo' => 'transferencia',
        'fecha' => $viernes, 'hora' => '11:00', 'cliente' => cliente(), 'acepta' => true]);
    verdadero(!in_array('11:00', libres('sede', $viernes), true), 'debería estar ocupada antes de vencer');
    db()->prepare('UPDATE pedidos SET expira = ? WHERE ref = ?')->execute([(new DateTimeImmutable('-1 hour'))->format('Y-m-d H:i'), $r['ref']]);
    verdadero(in_array('11:00', libres('sede', $viernes), true), 'debería quedar libre al vencer');
    [, $estado] = http('GET', '/api/estado-pedido.php?ref=' . $r['ref']);
    igual('vencido', $estado['estado']);
});

prueba('el estado de un pedido inexistente responde 404', function () {
    [$c] = http('GET', '/api/estado-pedido.php?ref=0000000000000000');
    igual(404, $c);
    [$c] = http('GET', '/api/estado-pedido.php?ref=hola');
    igual(404, $c);
});

prueba('el estado de una transferencia muestra los datos bancarios', function () use (&$refTransferencia) {
    [, $r] = http('GET', '/api/estado-pedido.php?ref=' . $refTransferencia);
    igual('pendiente_transferencia', $r['estado']);
    igual('Banco Prueba', $r['transferencia']['banco']);
    verdadero(!isset($r['email']) && !isset($r['telefono']), 'no debe exponer correo ni teléfono');
});

echo "\nMercado Pago\n";

$refMp = '';
prueba('crea el pago en Mercado Pago y redirige al link de pago', function () use ($viernes, &$refMp) {
    [$c, $r] = http('POST', '/api/crear-pedido.php', ['items' => [['id' => 'sede-12', 'cantidad' => 2]], 'modalidad' => 'sede', 'metodo' => 'mercadopago',
        'fecha' => $viernes, 'hora' => '21:00', 'cliente' => cliente(), 'acepta' => true]);
    igual(200, $c, 'código');
    igual('https://mp.example/pagar?pref=PREF-PRUEBA', $r['redirigir']);
    $refMp = $r['ref'];
    $p = pedido($refMp);
    igual('pendiente_pago', $p['estado']);
    igual(170000, (int) $p['total']);
    $enviado = json_decode(file_get_contents(sys_get_temp_dir() . '/domicfit-mp-falso.json'), true)['cuerpo'];
    igual('CLP', $enviado['items'][0]['currency_id']);
    igual($refMp, $enviado['external_reference']);
    verdadero(str_ends_with($enviado['notification_url'], '/api/webhook-mp.php'), 'notification_url');
});

prueba('un pago con monto distinto no se marca como pagado', function () use (&$refMp) {
    http('POST', '/api/webhook-mp.php', ['type' => 'payment', 'data' => ['id' => '666']]);
    igual('pendiente_pago', pedido($refMp)['estado']);
});

prueba('el aviso de Mercado Pago marca el pedido como pagado', function () use (&$refMp) {
    [$c] = http('POST', '/api/webhook-mp.php', ['type' => 'payment', 'data' => ['id' => '777']]);
    igual(200, $c);
    $p = pedido($refMp);
    igual('pagado', $p['estado']);
    igual('777', $p['mp_payment_id']);
    igual(3, (int) $p['aviso_enviado'], 'correo de confirmación');
});

prueba('si Mercado Pago falla, la reserva se cancela y el horario queda libre', function () use ($viernes) {
    [$c] = http('POST', '/api/crear-pedido.php', ['items' => [['id' => 'sede-8', 'cantidad' => 1]], 'modalidad' => 'sede', 'metodo' => 'mercadopago',
        'fecha' => $viernes, 'hora' => '22:00', 'cliente' => cliente(['nombre' => 'FALLA']), 'acepta' => true]);
    igual(502, $c);
    verdadero(in_array('22:00', libres('sede', $viernes), true), 'el horario debería quedar libre');
});

echo "\nPanel de administración\n";

$cookies = tempnam(sys_get_temp_dir(), 'cookies');
prueba('rechaza una contraseña incorrecta', function () use ($cookies) {
    [, $html] = http('POST', '/admin/', ['clave' => 'incorrecta'], ['formulario' => true, 'cookies' => $cookies]);
    verdadero(str_contains($html, 'Contraseña incorrecta'), 'debería decir contraseña incorrecta');
});

$csrf = '';
prueba('entra con la contraseña correcta y muestra las reservas', function () use ($cookies, &$csrf) {
    http('POST', '/admin/', ['clave' => 'clave-de-prueba'], ['formulario' => true, 'cookies' => $cookies]);
    [, $html] = http('GET', '/admin/', null, ['cookies' => $cookies]);
    verdadero(str_contains($html, 'Próximas reservas'), 'no entró al panel');
    verdadero(str_contains($html, 'Ana Prueba'), 'no muestra las reservas');
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m);
    $csrf = $m[1] ?? '';
    verdadero($csrf !== '', 'falta el token de seguridad');
});

prueba('las acciones sin token de seguridad se rechazan', function () use ($cookies, &$refTransferencia) {
    $id = pedido($refTransferencia)['id'];
    [$c] = http('POST', '/admin/', ['accion' => 'cancelar', 'id' => $id], ['formulario' => true, 'cookies' => $cookies]);
    igual(400, $c);
    igual('pendiente_transferencia', pedido($refTransferencia)['estado']);
});

prueba('confirmar una transferencia la marca como pagada y avisa al alumno', function () use ($cookies, &$csrf, &$refTransferencia) {
    $id = pedido($refTransferencia)['id'];
    http('POST', '/admin/', ['csrf' => $csrf, 'accion' => 'confirmar_pago', 'id' => $id], ['formulario' => true, 'cookies' => $cookies]);
    $p = pedido($refTransferencia);
    igual('pagado', $p['estado']);
    igual(3, (int) $p['aviso_enviado']);
});

prueba('bloquear un horario lo quita de la agenda', function () use ($cookies, &$csrf, $jueves) {
    verdadero(in_array('10:00', libres('sede', $jueves), true), 'debería estar libre antes');
    http('POST', '/admin/', ['csrf' => $csrf, 'accion' => 'bloquear', 'fecha' => $jueves, 'desde' => '09:30', 'hasta' => '11:30', 'modalidad' => 'todas'],
        ['formulario' => true, 'cookies' => $cookies]);
    $sede = libres('sede', $jueves);
    verdadero(!in_array('10:00', $sede, true) && !in_array('11:00', $sede, true), 'debería estar bloqueado');
    verdadero(in_array('21:00', $sede, true), 'el resto del día debería seguir libre');
});

prueba('sin sesión no se puede ver el panel', function () {
    [, $html] = http('GET', '/admin/');
    verdadero(str_contains($html, 'name="clave"') && !str_contains($html, 'Ana Prueba'), 'muestra datos sin iniciar sesión');
});

echo "\nPrivacidad\n";

prueba('las reservas no pagadas de hace más de 6 meses se borran solas', function () {
    $viejo = (new DateTimeImmutable('-7 months'))->format('Y-m-d H:i');
    db()->prepare("INSERT INTO pedidos (ref, creado, estado, metodo, total, items_json, modalidad, inicio, fin, nombre, email, telefono)
        VALUES ('VIEJO00000000000', ?, 'cancelado', 'mercadopago', 1, '[]', 'sede', ?, ?, 'X', 'x@example.com', '1')")
        ->execute([$viejo, $viejo, $viejo]);
    http('GET', '/api/disponibilidad.php?modalidad=sede&fecha=' . date('Y-m-d')); // cualquier consulta a la base ejecuta la limpieza
    igual([], pedido('VIEJO00000000000'));
});

@unlink($cookies);
echo "\n" . ($total - $fallos) . " de $total pruebas pasaron.\n";
exit($fallos === 0 ? 0 : 1);
