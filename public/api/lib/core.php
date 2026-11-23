<?php
/**
 * Funciones compartidas: configuración, base de datos, catálogo, agenda, Mercado Pago y correos.
 */

declare(strict_types=1);

const ESTADOS_QUE_OCUPAN = ['pagado', 'reservado', 'pendiente_pago', 'pendiente_transferencia'];

function config(): array
{
    static $config = null;
    if ($config === null) {
        $ruta = __DIR__ . '/../../config/config.php';
        if (!is_file($ruta)) {
            error_log('Domicfit: falta config/config.php');
            responder_error('La página aún no está configurada.', 500);
        }
        $config = require $ruta;
        date_default_timezone_set($config['zona_horaria'] ?? 'America/Santiago');
    }
    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . config()['ruta_db']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('CREATE TABLE IF NOT EXISTS pedidos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ref TEXT NOT NULL UNIQUE,
            creado TEXT NOT NULL,
            estado TEXT NOT NULL,
            metodo TEXT NOT NULL,
            total INTEGER NOT NULL,
            items_json TEXT NOT NULL,
            modalidad TEXT NOT NULL,
            inicio TEXT NOT NULL,
            fin TEXT NOT NULL,
            expira TEXT,
            nombre TEXT NOT NULL,
            email TEXT NOT NULL,
            telefono TEXT NOT NULL,
            personas INTEGER NOT NULL DEFAULT 1,
            objetivo TEXT,
            comuna TEXT,
            direccion TEXT,
            comentarios TEXT,
            mp_preference_id TEXT,
            mp_link TEXT,
            mp_payment_id TEXT,
            aviso_enviado INTEGER NOT NULL DEFAULT 0
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS bloqueos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            modalidad TEXT NOT NULL,
            inicio TEXT NOT NULL,
            fin TEXT NOT NULL,
            motivo TEXT
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pedidos_inicio ON pedidos (modalidad, inicio)');
    }
    return $pdo;
}

/* ---------- Respuestas JSON ---------- */

function responder(array $datos, int $codigo = 200): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function responder_error(string $mensaje, int $codigo = 400): never
{
    responder(['ok' => false, 'error' => $mensaje], $codigo);
}

function leer_json_entrada(): array
{
    $datos = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($datos)) {
        responder_error('Solicitud inválida.');
    }
    return $datos;
}

/* ---------- Catálogo ---------- */

function catalogo(): array
{
    static $productos = null;
    if ($productos === null) {
        $json = json_decode((string) file_get_contents(__DIR__ . '/../../data/productos.json'), true);
        $productos = [];
        foreach ($json['productos'] ?? [] as $p) {
            $productos[$p['id']] = $p;
        }
    }
    return $productos;
}

/* ---------- Agenda ---------- */

function ahora(): DateTimeImmutable
{
    config();
    return new DateTimeImmutable('now');
}

function fmt(DateTimeImmutable $d): string
{
    return $d->format('Y-m-d H:i');
}

/** Horas de inicio posibles para una fecha y modalidad, según los horarios configurados. */
function horas_del_dia(string $modalidad, DateTimeImmutable $fecha): array
{
    $agenda = config()['agenda'][$modalidad] ?? null;
    if ($agenda === null) {
        return [];
    }
    $dia = (int) $fecha->format('N');
    $horas = [];
    if (isset($agenda['horarios'])) {
        $horas = $agenda['horarios'][$dia] ?? [];
    } elseif (isset($agenda['rangos'][$dia])) {
        [$desde, $hasta, $paso] = $agenda['rangos'][$dia];
        $t = DateTimeImmutable::createFromFormat('Y-m-d H:i', $fecha->format('Y-m-d') . ' ' . $desde);
        $limite = DateTimeImmutable::createFromFormat('Y-m-d H:i', $fecha->format('Y-m-d') . ' ' . $hasta);
        while ($t <= $limite) {
            $horas[] = $t->format('H:i');
            $t = $t->modify("+{$paso} minutes");
        }
    }
    return $horas;
}

/** Devuelve [['hora' => '10:00', 'libre' => true], ...] para un día. */
function disponibilidad(string $modalidad, string $fechaTexto, ?int $ignorarPedidoId = null): array
{
    $cfg = config();
    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaTexto);
    if (!$fecha || $fecha->format('Y-m-d') !== $fechaTexto || !isset($cfg['agenda'][$modalidad])) {
        return [];
    }
    $agenda = $cfg['agenda'][$modalidad];
    $duracion = (int) $cfg['duracion_sesion'];
    $margen = (int) ($agenda['margen'] ?? 0);
    $minimo = ahora()->modify('+' . (int) $cfg['anticipacion_horas'] . ' hours');
    $maximo = ahora()->setTime(23, 59)->modify('+' . (int) $cfg['dias_maximos'] . ' days');

    $diaInicio = $fecha->format('Y-m-d') . ' 00:00';
    $diaFin = $fecha->modify('+1 day')->format('Y-m-d') . ' 00:00';
    $ocupados = ocupaciones($modalidad, $diaInicio, $diaFin, $ignorarPedidoId);
    $bloqueos = bloqueos_entre($modalidad, $diaInicio, $diaFin);

    $resultado = [];
    foreach (horas_del_dia($modalidad, $fecha) as $hora) {
        $inicio = DateTimeImmutable::createFromFormat('Y-m-d H:i', $fechaTexto . ' ' . $hora);
        $fin = $inicio->modify("+{$duracion} minutes");
        $libre = $inicio >= $minimo && $inicio <= $maximo;

        if ($libre) {
            foreach ($bloqueos as $b) {
                if ($b['inicio'] < fmt($fin) && fmt($inicio) < $b['fin']) {
                    $libre = false;
                    break;
                }
            }
        }
        if ($libre) {
            // Con margen: una clase choca con otra si están a menos de "margen" minutos.
            $conMargenInicio = fmt($inicio->modify("-{$margen} minutes"));
            $conMargenFin = fmt($fin->modify("+{$margen} minutes"));
            $choques = 0;
            foreach ($ocupados as $o) {
                if ($o['inicio'] < $conMargenFin && $conMargenInicio < $o['fin']) {
                    $choques++;
                }
            }
            $libre = $choques < (int) $agenda['capacidad'];
        }
        $resultado[] = ['hora' => $hora, 'libre' => $libre];
    }
    return $resultado;
}

function ocupaciones(string $modalidad, string $desde, string $hasta, ?int $ignorarPedidoId = null): array
{
    $marcas = implode(',', array_fill(0, count(ESTADOS_QUE_OCUPAN), '?'));
    $sql = "SELECT id, inicio, fin, estado, expira FROM pedidos
            WHERE modalidad = ? AND inicio < ? AND fin > ? AND estado IN ($marcas)";
    // Se amplía la ventana para considerar el margen de traslado.
    $desdeAmpliado = fmt((new DateTimeImmutable($desde))->modify('-3 hours'));
    $hastaAmpliado = fmt((new DateTimeImmutable($hasta))->modify('+3 hours'));
    $st = db()->prepare($sql);
    $st->execute(array_merge([$modalidad, $hastaAmpliado, $desdeAmpliado], ESTADOS_QUE_OCUPAN));
    $ahora = fmt(ahora());
    return array_values(array_filter($st->fetchAll(), function ($p) use ($ahora, $ignorarPedidoId) {
        if ($ignorarPedidoId !== null && (int) $p['id'] === $ignorarPedidoId) {
            return false;
        }
        // Los pendientes de pago dejan de ocupar el horario cuando vencen.
        $pendiente = in_array($p['estado'], ['pendiente_pago', 'pendiente_transferencia'], true);
        return !($pendiente && $p['expira'] !== null && $p['expira'] < $ahora);
    }));
}

function bloqueos_entre(string $modalidad, string $desde, string $hasta): array
{
    $st = db()->prepare("SELECT * FROM bloqueos WHERE (modalidad = ? OR modalidad = 'todas') AND inicio < ? AND fin > ?");
    $st->execute([$modalidad, $hasta, $desde]);
    return $st->fetchAll();
}

function hora_libre(string $modalidad, string $fecha, string $hora, ?int $ignorarPedidoId = null): bool
{
    foreach (disponibilidad($modalidad, $fecha, $ignorarPedidoId) as $h) {
        if ($h['hora'] === $hora) {
            return $h['libre'];
        }
    }
    return false;
}

/* ---------- Pedidos ---------- */

function buscar_pedido(string $ref): ?array
{
    $st = db()->prepare('SELECT * FROM pedidos WHERE ref = ?');
    $st->execute([$ref]);
    return $st->fetch() ?: null;
}

function actualizar_pedido(int $id, array $campos): void
{
    $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($campos)));
    $st = db()->prepare("UPDATE pedidos SET $sets WHERE id = ?");
    $st->execute([...array_values($campos), $id]);
}

function pesos(int $monto): string
{
    return '$' . number_format($monto, 0, ',', '.');
}

function fecha_legible(string $inicio): string
{
    $d = new DateTimeImmutable($inicio);
    $dias = ['', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
    $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return $dias[(int) $d->format('N')] . ' ' . $d->format('j') . ' de ' . $meses[(int) $d->format('n')] . ' a las ' . $d->format('H:i');
}

/* ---------- Mercado Pago ---------- */

function mp_peticion(string $metodo, string $ruta, ?array $cuerpo = null): array
{
    $ch = curl_init((config()['mp_api_url'] ?? 'https://api.mercadopago.com') . $ruta);
    $cabeceras = [
        'Authorization: Bearer ' . config()['mp_access_token'],
        'Content-Type: application/json',
    ];
    if ($cuerpo !== null) {
        $cabeceras[] = 'X-Idempotency-Key: ' . bin2hex(random_bytes(16));
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cuerpo, JSON_UNESCAPED_UNICODE));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_HTTPHEADER => $cabeceras,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $respuesta = curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $datos = json_decode((string) $respuesta, true);
    if ($codigo < 200 || $codigo >= 300 || !is_array($datos)) {
        error_log("Domicfit: Mercado Pago respondió $codigo en $ruta: " . substr((string) $respuesta, 0, 500));
        throw new RuntimeException('Error al comunicarse con Mercado Pago.');
    }
    return $datos;
}

function mp_crear_preferencia(array $pedido, array $items): array
{
    $url = rtrim(config()['url_sitio'], '/');
    $retorno = $url . '/pago.html?ref=' . urlencode($pedido['ref']);
    return mp_peticion('POST', '/checkout/preferences', [
        'items' => array_map(fn($i) => [
            'id' => $i['id'],
            'title' => $i['nombre'],
            'quantity' => $i['cantidad'],
            'unit_price' => $i['precio'],
            'currency_id' => 'CLP',
        ], $items),
        'payer' => ['name' => $pedido['nombre'], 'email' => $pedido['email']],
        'external_reference' => $pedido['ref'],
        'back_urls' => ['success' => $retorno, 'pending' => $retorno, 'failure' => $retorno],
        'auto_return' => 'approved',
        'notification_url' => $url . '/api/webhook-mp.php',
        'statement_descriptor' => 'DOMICFIT',
        'expires' => true,
        'expiration_date_to' => (new DateTimeImmutable($pedido['expira']))->format('Y-m-d\TH:i:s.vP'),
    ]);
}

/**
 * Consulta un pago en Mercado Pago y actualiza el pedido. Nunca se confía en lo que diga
 * el navegador: el estado siempre se pregunta directo a Mercado Pago.
 */
function mp_sincronizar_pago(string $paymentId): ?array
{
    if (!preg_match('/^\d{1,20}$/', $paymentId)) {
        return null;
    }
    $pago = mp_peticion('GET', '/v1/payments/' . $paymentId);
    $pedido = buscar_pedido((string) ($pago['external_reference'] ?? ''));
    if ($pedido === null) {
        return null;
    }
    $estado = $pago['status'] ?? '';
    $montoCorrecto = (int) round((float) ($pago['transaction_amount'] ?? 0)) === (int) $pedido['total']
        && ($pago['currency_id'] ?? '') === 'CLP';

    if ($estado === 'approved' && $montoCorrecto && $pedido['estado'] !== 'pagado') {
        actualizar_pedido((int) $pedido['id'], ['estado' => 'pagado', 'mp_payment_id' => $paymentId, 'expira' => null]);
        $pedido = buscar_pedido($pedido['ref']);
        if ($pedido !== null) {
            enviar_avisos($pedido);
        }
    } elseif (in_array($estado, ['rejected', 'cancelled'], true) && $pedido['estado'] === 'pendiente_pago') {
        // Se deja pendiente para que pueda reintentar mientras el horario siga guardado.
        actualizar_pedido((int) $pedido['id'], ['mp_payment_id' => $paymentId]);
    } elseif ($estado === 'approved' && !$montoCorrecto) {
        error_log("Domicfit: el pago $paymentId no coincide con el total del pedido {$pedido['ref']}");
    }
    return buscar_pedido($pedido['ref']);
}

/* ---------- Correos ---------- */

function enviar_correo(string $para, string $asunto, string $texto): void
{
    $cfg = config();
    $cabeceras = implode("\r\n", [
        'From: Domicfit <' . $cfg['correo_remitente'] . '>',
        'Reply-To: ' . $cfg['correo_admin'],
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ]);
    $ok = @mail($para, mb_encode_mimeheader($asunto, 'UTF-8'), $texto, $cabeceras);
    if (!$ok) {
        error_log("Domicfit: no se pudo enviar correo a $para");
    }
}

function resumen_pedido(array $p): string
{
    $lineas = [];
    foreach (json_decode($p['items_json'], true) as $i) {
        $lineas[] = "- {$i['nombre']} x{$i['cantidad']}: " . pesos($i['precio'] * $i['cantidad']);
    }
    $lugar = $p['modalidad'] === 'sede'
        ? 'Sede San Joaquín (Pasaje Río Valdivia 3705B)'
        : "A domicilio: {$p['direccion']}, {$p['comuna']}";
    return implode("\n", [
        'Pedido: ' . $p['ref'],
        ...$lineas,
        'Total: ' . pesos((int) $p['total']),
        '',
        'Primera sesión: ' . fecha_legible($p['inicio']),
        'Lugar: ' . $lugar,
        'Personas: ' . $p['personas'],
        'Objetivo: ' . ($p['objetivo'] ?: '-'),
    ]);
}

const CONDICIONES = "Condiciones del servicio:
- El plan se paga completo antes de comenzar.
- Puedes suspender o cambiar una clase avisando con al menos 12 horas de anticipación, una vez por semana como máximo. Con menos aviso, la clase se cuenta como realizada. La recuperación depende del horario disponible del profesor.
- Las clases que no se realizan por motivos del alumno no se recuperan ni se traspasan a otra persona o a otro periodo.
- Puedes congelar el plan por enfermedad o vacaciones, informándolo por correo con el comprobante de la ausencia.";

/** Envía los correos al alumno y a Domicfit una sola vez por pedido y estado. */
function enviar_avisos(array $p): void
{
    $cfg = config();
    // Cada estado avisa una sola vez. Una transferencia avisa al reservar (2) y otra vez al confirmarse el pago (3).
    $marca = ['reservado' => 1, 'pendiente_transferencia' => 2, 'pagado' => 3][$p['estado']] ?? 0;
    if ($marca === 0 || (int) $p['aviso_enviado'] >= $marca) {
        return;
    }
    actualizar_pedido((int) $p['id'], ['aviso_enviado' => $marca]);

    $resumen = resumen_pedido($p);
    $whatsapp = 'https://wa.me/' . $cfg['whatsapp'];

    if ($p['estado'] === 'pendiente_transferencia') {
        $t = $cfg['transferencia'];
        $asuntoAlumno = 'Domicfit: datos para tu transferencia';
        $cuerpoAlumno = "Hola {$p['nombre']},\n\nGuardamos tu horario por {$t['horas_para_pagar']} horas mientras llega tu transferencia.\n\n$resumen\n\n"
            . "Datos de transferencia:\nTitular: {$t['titular']}\nRUT: {$t['rut']}\nBanco: {$t['banco']}\nTipo de cuenta: {$t['tipo']}\nNúmero: {$t['numero']}\nCorreo: {$t['correo']}\n\n"
            . "Envía el comprobante por WhatsApp ($whatsapp) indicando tu número de pedido.\n\n" . CONDICIONES . "\n\nEquipo Domicfit";
        $asuntoAdmin = "Nueva reserva con transferencia pendiente - {$p['nombre']}";
    } elseif ($p['estado'] === 'reservado') {
        $asuntoAlumno = 'Domicfit: tu clase de prueba está reservada';
        $cuerpoAlumno = "Hola {$p['nombre']},\n\n¡Tu clase de prueba está reservada!\n\n$resumen\n\nSi necesitas cambiarla, escríbenos por WhatsApp: $whatsapp\n\nEquipo Domicfit";
        $asuntoAdmin = "Nueva clase de prueba - {$p['nombre']}";
    } else {
        $asuntoAlumno = 'Domicfit: confirmación de tu compra';
        $cuerpoAlumno = "Hola {$p['nombre']},\n\n¡Gracias por tu compra! Tu pago fue recibido y tu primera sesión está agendada.\n\n$resumen\n\n"
            . "Las siguientes sesiones las coordinamos contigo por WhatsApp: $whatsapp\n\n" . CONDICIONES . "\n\nEquipo Domicfit";
        $asuntoAdmin = "Nueva compra pagada - {$p['nombre']} - " . pesos((int) $p['total']);
    }

    enviar_correo($p['email'], $asuntoAlumno, $cuerpoAlumno);
    enviar_correo($cfg['correo_admin'], $asuntoAdmin, "$resumen\n\nCliente: {$p['nombre']}\nCorreo: {$p['email']}\nTeléfono: {$p['telefono']}\nComentarios: " . ($p['comentarios'] ?: '-') . "\nMétodo: {$p['metodo']}\nEstado: {$p['estado']}");
}
