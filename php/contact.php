<?php
// php/contact.php - Controlador de envío de correo para Teamoteo

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit;
}

$inputData = json_decode(file_get_contents("php://input"), true);

$nombre = trim($inputData['nombre'] ?? '');
$email = trim($inputData['email'] ?? '');
$servicio = trim($inputData['servicio'] ?? $inputData['asunto'] ?? 'Consulta General');
$mensaje = trim($inputData['mensaje'] ?? '');

if (empty($nombre) || empty($email) || empty($mensaje)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Por favor, completá todos los campos obligatorios.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'El formato del correo electrónico no es válido.']);
    exit;
}

function sendSmtpEmail($config, $to, $subject, $body, $replyTo = '') {
    $host = $config['smtp_host'] ?? $config['SMTP_HOST'] ?? 'smtp.gmail.com';
    $port = intval($config['smtp_port'] ?? $config['SMTP_PORT'] ?? 465);
    $user = $config['smtp_user'] ?? $config['SMTP_USER'] ?? '';
    $pass = $config['smtp_pass'] ?? $config['SMTP_PASS'] ?? '';

    if (empty($user) || empty($pass)) {
        throw new Exception("Faltan credenciales SMTP (usuario o contraseña) en config.php");
    }

    $scheme = ($port === 465) ? 'ssl://' : '';
    $address = $scheme . $host . ':' . $port;

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ]);

    $socket = @stream_socket_client($address, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        throw new Exception("No se pudo conectar al servidor SMTP ($address): $errstr ($errno)");
    }

    stream_set_timeout($socket, 15);

    $readResponse = function() use ($socket) {
        $response = '';
        while ($str = fgets($socket, 515)) {
            $response .= $str;
            if (substr($str, 3, 1) == ' ') break;
        }
        return $response;
    };

    $sendCommand = function($command, $expectedCode) use ($socket, $readResponse) {
        fputs($socket, $command . "\r\n");
        $res = $readResponse();
        $code = substr($res, 0, 3);
        if ($code != $expectedCode) {
            throw new Exception("Error SMTP ($command): " . trim($res));
        }
        return $res;
    };

    $readResponse(); // Banner 220

    $sendCommand("EHLO " . gethostname(), "250");

    if ($port === 587) {
        $sendCommand("STARTTLS", "220");
        $crypto = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
        if (!$crypto) {
            throw new Exception("Fallo en negociación TLS.");
        }
        $sendCommand("EHLO " . gethostname(), "250");
    }

    $sendCommand("AUTH LOGIN", "334");
    $sendCommand(base64_encode($user), "334");
    $sendCommand(base64_encode($pass), "235");

    $sendCommand("MAIL FROM: <$user>", "250");
    $sendCommand("RCPT TO: <$to>", "250");
    $sendCommand("DATA", "354");

    $headers = [
        "From: Teamoteo Contacto <$user>",
        "To: <$to>",
        "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=UTF-8",
        "Content-Transfer-Encoding: 8bit",
    ];
    if (!empty($replyTo)) {
        $headers[] = "Reply-To: <$replyTo>";
    }

    $emailData = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
    $sendCommand($emailData, "250");
    $sendCommand("QUIT", "221");

    fclose($socket);
    return true;
}

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Archivo de configuración config.php no encontrado.']);
    exit;
}

$config = require $configFile;
$to = $config['recipient'] ?? $config['SMTP_TO'] ?? $config['smtp_to'] ?? '';
if (empty($to)) {
    $to = $config['smtp_user'] ?? $config['SMTP_USER'] ?? 'contacto@teamoteo.com';
}

$subjectHeader = "Teamoteo Contacto: " . $servicio;
$body = "Nombre: $nombre\nEmail: $email\nServicio/Interés: $servicio\n\nMensaje:\n$mensaje\n";

try {
    sendSmtpEmail($config, $to, $subjectHeader, $body, $email);
    echo json_encode([
        'success' => true,
        'message' => 'Consulta enviada correctamente.',
        'data' => [
            'nombre' => $nombre,
            'email' => $email,
            'servicio' => $servicio
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error al enviar el correo: ' . $e->getMessage()
    ]);
}
