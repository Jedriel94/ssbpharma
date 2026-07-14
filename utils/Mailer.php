<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Configuracion.php';

class Mailer {

    // ---------------------------------------------------------------
    // PÚBLICA: Restablecimiento de contraseña
    // ---------------------------------------------------------------
    public static function sendPasswordReset($to_email, $nombre, $link) {
        if (!self::validarEmail($to_email)) return false;
        $cfg = self::cfg();
        if (empty($cfg['from_email'])) return false;

        $tienda  = Configuracion::get('nombre_tienda', 'Solumedic');
        $asunto  = "Restablecer contraseña - {$tienda}";
        $nombre_s = htmlspecialchars($nombre, ENT_QUOTES);
        $link_s   = htmlspecialchars($link, ENT_QUOTES);

        $body_text = "Hola {$nombre},\n\nRecibimos una solicitud para restablecer tu contraseña en {$tienda}.\n\n"
                   . "Haz clic en el siguiente enlace (válido por 1 hora):\n{$link}\n\n"
                   . "Si no solicitaste esto, ignora este correo.\n\n{$tienda}";

        $body_html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="font-family:Arial,sans-serif;background:#f1f5f9;margin:0;padding:24px;">
<div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08);">
  <div style="background:#126c6a;padding:36px 28px;text-align:center;">
    <div style="font-size:48px;margin-bottom:8px;">🔑</div>
    <h1 style="color:#ffffff;margin:0;font-size:20px;font-weight:700;">Restablecer contraseña</h1>
    <p style="color:#a7d4d3;margin:6px 0 0;font-size:14px;">{$tienda}</p>
  </div>
  <div style="padding:32px 28px;">
    <p style="color:#475569;font-size:15px;margin:0 0 12px;">Hola, <strong style="color:#1e293b;">{$nombre_s}</strong></p>
    <p style="color:#475569;font-size:14px;margin:0 0 24px;">Recibimos una solicitud para restablecer tu contraseña. Haz clic en el botón (el enlace es válido por <strong>1 hora</strong>).</p>
    <div style="text-align:center;margin:28px 0;">
      <a href="{$link_s}" style="background:#126c6a;color:#ffffff;text-decoration:none;padding:14px 32px;border-radius:10px;font-weight:700;font-size:15px;display:inline-block;">
        Restablecer contraseña
      </a>
    </div>
    <p style="color:#94a3b8;font-size:12px;margin:20px 0 0;word-break:break-all;">Si el botón no funciona, copia este enlace en tu navegador:<br>{$link_s}</p>
    <p style="color:#94a3b8;font-size:12px;margin:16px 0 0;">Si no solicitaste este cambio, ignora este correo — tu contraseña no será modificada.</p>
  </div>
  <div style="background:#f8fafc;padding:14px 28px;text-align:center;font-size:12px;color:#94a3b8;border-top:1px solid #e2e8f0;">
    {$tienda} &mdash; Sistema interno
  </div>
</div>
</body>
</html>
HTML;
        return self::enviar($cfg, $to_email, $asunto, $body_text, $body_html);
    }

    // ---------------------------------------------------------------
    // PÚBLICA: Correo de confirmación de pedido
    // ---------------------------------------------------------------
    public static function sendConfirmacion($to_email, array $pedido, array $detalle) {
        if (!self::validarEmail($to_email)) return false;
        $cfg = self::cfg();
        if (empty($cfg['from_email'])) return false;

        $tienda    = Configuracion::get('nombre_tienda', 'Solumedic');
        $pedido_id = str_pad($pedido['id'], 4, '0', STR_PAD_LEFT);
        $asunto    = str_replace('{id}', $pedido_id, Configuracion::get('email_confirmacion_asunto', 'Tu pedido #{id} fue confirmado'));

        // Construir lista de productos
        $items_text = '';
        $items_html = '';
        foreach ($detalle as $item) {
            $items_text .= '- ' . $item['producto'] . ' x' . $item['cantidad'] . ' = $' . number_format($item['subtotal'], 2) . "\n";
            $items_html .= '<tr>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e2e8f0;">' . htmlspecialchars($item['producto']) . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e2e8f0;text-align:center;">' . intval($item['cantidad']) . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e2e8f0;text-align:right;">$' . number_format($item['subtotal'], 2) . '</td>'
                . '</tr>';
        }

        $template = Configuracion::get('email_confirmacion_cuerpo', '');
        $reemplazos = [
            '{nombre}' => $pedido['nombre'] ?? $pedido['telefono'],
            '{id}'     => $pedido_id,
            '{total}'  => '$' . number_format($pedido['total'], 2),
            '{items}'  => $items_text,
            '{tienda}' => $tienda,
        ];
        $body_text = strtr($template ?: "Hola {nombre},\n\nTu pedido #{id} ha sido CONFIRMADO.\n\nProductos:\n{items}\nTotal: {total}\n\nGracias por tu compra en {tienda}.", $reemplazos);
        $body_html = self::htmlConfirmacion($pedido, $pedido_id, $items_html, $tienda);

        return self::enviar($cfg, $to_email, $asunto, $body_text, $body_html);
    }

    // ---------------------------------------------------------------
    // PÚBLICA: Correo de factura electrónica con adjuntos
    // ---------------------------------------------------------------
    public static function sendFactura($to_email, array $pedido, $pdf_path = null, $xml_path = null) {
        if (!self::validarEmail($to_email)) return false;
        $cfg = self::cfg();
        if (empty($cfg['from_email'])) return false;

        $tienda    = Configuracion::get('nombre_tienda', 'Solumedic');
        $pedido_id = str_pad($pedido['id'], 4, '0', STR_PAD_LEFT);
        $asunto    = "Factura electrónica - Pedido #{$pedido_id}";
        $body_text = "Hola,\n\nAdjunto encontrarás la factura de tu pedido #{$pedido_id}.\nTotal: $"
                   . number_format($pedido['total'], 2) . "\n\nGracias por tu compra en {$tienda}.";
        $body_html = self::htmlFactura($pedido, $pedido_id, $tienda);

        $adjuntos = [];
        if ($pdf_path && file_exists($pdf_path)) {
            $adjuntos[] = ['path' => $pdf_path, 'name' => "factura_{$pedido_id}.pdf", 'type' => 'application/pdf'];
        }
        if ($xml_path && file_exists($xml_path)) {
            $adjuntos[] = ['path' => $xml_path, 'name' => "factura_{$pedido_id}.xml", 'type' => 'application/xml'];
        }

        return self::enviar($cfg, $to_email, $asunto, $body_text, $body_html, $adjuntos);
    }

    // ---------------------------------------------------------------
    // PRIVADO: Despacho (SMTP o mail())
    // ---------------------------------------------------------------
    private static function validarEmail($email) {
        return !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    private static function cfg() {
        return [
            'host'       => Configuracion::get('email_smtp_host', ''),
            'port'       => (int) Configuracion::get('email_smtp_port', 587),
            'user'       => Configuracion::get('email_smtp_user', ''),
            'pass'       => Configuracion::get('email_smtp_pass', ''),
            'from_name'  => Configuracion::get('email_from_nombre', 'Solumedic'),
            'from_email' => Configuracion::get('email_from_email', ''),
        ];
    }

    private static function enviar(array $cfg, $to, $subject, $text, $html, array $adjuntos = [], string $cc = '') {
        if (!empty($cfg['host']) && !empty($cfg['user'])) {
            $ok = self::enviarSmtp($cfg, $to, $subject, $text, $html, $adjuntos, $cc);
        } else {
            $ok = self::enviarMail($cfg, $to, $subject, $text, $html, $adjuntos, $cc);
        }
        if (!$ok) {
            error_log("Mailer: fallo al enviar correo a {$to} (asunto: {$subject})");
        }
        return $ok;
    }

    // ---------------------------------------------------------------
    // SMTP manual con fsockopen (sin PHPMailer)
    // ---------------------------------------------------------------
    private static function enviarSmtp(array $cfg, $to, $subject, $text, $html, array $adjuntos, string $cc = '') {
        $host = $cfg['host'];
        $port = $cfg['port'];

        $socket_host = ($port == 465) ? "ssl://{$host}" : $host;
        $errno = 0; $errstr = '';
        $conn = @fsockopen($socket_host, $port, $errno, $errstr, 15);
        if (!$conn) {
            error_log("Mailer SMTP no pudo conectar a {$socket_host}:{$port} – {$errstr} ({$errno})");
            return false;
        }

        // Función para enviar comando y leer respuesta
        $cmd = function ($linea) use ($conn) {
            if ($linea !== null) fwrite($conn, $linea . "\r\n");
            $resp = '';
            while ($l = fgets($conn, 512)) {
                $resp .= $l;
                if (strlen($l) > 3 && $l[3] === ' ') break;
            }
            return trim($resp);
        };

        // Saludo
        $cmd(null); // leer greeting

        $ehlo = $cmd('EHLO ' . (gethostname() ?: 'localhost'));
        if (substr($ehlo, 0, 3) !== '250') { fclose($conn); return false; }

        // STARTTLS en puerto 587
        if ($port == 587) {
            $tls = $cmd('STARTTLS');
            if (substr($tls, 0, 3) !== '220') { fclose($conn); return false; }
            stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $cmd('EHLO ' . (gethostname() ?: 'localhost'));
        }

        // AUTH LOGIN
        if (!empty($cfg['user'])) {
            $r = $cmd('AUTH LOGIN');
            if (substr($r, 0, 3) !== '334') { fclose($conn); return false; }
            fwrite($conn, base64_encode($cfg['user']) . "\r\n");
            $r2 = ''; while ($l = fgets($conn, 512)) { $r2 .= $l; if (strlen($l) > 3 && $l[3] === ' ') break; }
            if (substr(trim($r2), 0, 3) !== '334') { fclose($conn); return false; }
            fwrite($conn, base64_encode($cfg['pass']) . "\r\n");
            $r3 = ''; while ($l = fgets($conn, 512)) { $r3 .= $l; if (strlen($l) > 3 && $l[3] === ' ') break; }
            if (substr(trim($r3), 0, 3) !== '235') {
                error_log("Mailer AUTH falló: " . trim($r3));
                fclose($conn);
                return false;
            }
        }

        // Envelope
        $from = $cfg['from_email'];
        $r = $cmd("MAIL FROM:<{$from}>");
        if (substr($r, 0, 3) !== '250') { fclose($conn); return false; }
        $r = $cmd("RCPT TO:<{$to}>");
        if (substr($r, 0, 3) !== '250' && substr($r, 0, 3) !== '251') { fclose($conn); return false; }
        // CC adicional (copia al admin)
        if (!empty($cc) && filter_var($cc, FILTER_VALIDATE_EMAIL)) {
            $cmd("RCPT TO:<{$cc}>");
        }
        $cmd('DATA');

        $mensaje = self::construirMime($cfg, $to, $subject, $text, $html, $adjuntos, $cc);
        fwrite($conn, $mensaje . "\r\n.\r\n");

        $resp = ''; while ($l = fgets($conn, 512)) { $resp .= $l; if (strlen($l) > 3 && $l[3] === ' ') break; }
        $cmd('QUIT');
        fclose($conn);

        $ok = substr(trim($resp), 0, 3) === '250';
        if (!$ok) error_log("Mailer SMTP DATA resp: " . trim($resp));
        return $ok;
    }

    // ---------------------------------------------------------------
    // Fallback: PHP mail()
    // ---------------------------------------------------------------
    private static function enviarMail(array $cfg, $to, $subject, $text, $html, array $adjuntos, string $cc = '') {
        $mime = self::construirMime($cfg, $to, $subject, $text, $html, $adjuntos, $cc);
        $partes = explode("\r\n\r\n", $mime, 2);
        $raw_headers = $partes[0];
        $body        = $partes[1] ?? '';

        $lineas = explode("\r\n", $raw_headers);
        $filtradas = array_filter($lineas, fn($l) => !str_starts_with($l, 'To:') && !str_starts_with($l, 'Subject:'));
        $headers = implode("\r\n", $filtradas);

        $subj_encoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        return @mail($to, $subj_encoded, $body, $headers);
    }

    // ---------------------------------------------------------------
    // Construcción MIME completa (con soporte de adjuntos)
    // ---------------------------------------------------------------
    private static function construirMime(array $cfg, $to, $subject, $text, $html, array $adjuntos, string $cc = '') {
        $from_name  = '=?UTF-8?B?' . base64_encode($cfg['from_name']) . '?=';
        $from_email = $cfg['from_email'];
        $date       = date('r');
        $subj_enc   = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $boundary_alt = 'alt_' . md5(uniqid());
        $cc_header = (!empty($cc) && filter_var($cc, FILTER_VALIDATE_EMAIL)) ? "Cc: {$cc}\r\n" : '';

        if (empty($adjuntos)) {
            $headers = implode("\r\n", [
                "From: {$from_name} <{$from_email}>",
                "To: {$to}",
                rtrim("Subject: {$subj_enc}"),
                "MIME-Version: 1.0",
                "Content-Type: multipart/alternative; boundary=\"{$boundary_alt}\"",
                "Date: {$date}",
                "X-Mailer: SolumedShop/1.0",
            ]) . ($cc_header ? "\r\n" . rtrim($cc_header) : '');
            $body = "--{$boundary_alt}\r\n"
                  . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                  . chunk_split(base64_encode($text)) . "\r\n"
                  . "--{$boundary_alt}\r\n"
                  . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                  . chunk_split(base64_encode($html)) . "\r\n"
                  . "--{$boundary_alt}--";
        } else {
            $boundary_mix = 'mix_' . md5(uniqid());
            $headers = implode("\r\n", [
                "From: {$from_name} <{$from_email}>",
                "To: {$to}",
                "Subject: {$subj_enc}",
                "MIME-Version: 1.0",
                "Content-Type: multipart/mixed; boundary=\"{$boundary_mix}\"",
                "Date: {$date}",
                "X-Mailer: SolumedShop/1.0",
            ]) . ($cc_header ? "\r\n" . rtrim($cc_header) : '');
            $body = "--{$boundary_mix}\r\n"
                  . "Content-Type: multipart/alternative; boundary=\"{$boundary_alt}\"\r\n\r\n"
                  . "--{$boundary_alt}\r\n"
                  . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                  . chunk_split(base64_encode($text)) . "\r\n"
                  . "--{$boundary_alt}\r\n"
                  . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                  . chunk_split(base64_encode($html)) . "\r\n"
                  . "--{$boundary_alt}--\r\n\r\n";

            foreach ($adjuntos as $adj) {
                $data = file_get_contents($adj['path']);
                $body .= "--{$boundary_mix}\r\n"
                       . "Content-Type: {$adj['type']}; name=\"{$adj['name']}\"\r\n"
                       . "Content-Transfer-Encoding: base64\r\n"
                       . "Content-Disposition: attachment; filename=\"{$adj['name']}\"\r\n\r\n"
                       . chunk_split(base64_encode($data)) . "\r\n";
            }
            $body .= "--{$boundary_mix}--";
        }

        return "{$headers}\r\n\r\n{$body}";
    }

    // ---------------------------------------------------------------
    // Plantillas HTML
    // ---------------------------------------------------------------
    private static function htmlConfirmacion(array $pedido, $pedido_id, $items_html, $tienda) {
        $total = '$' . number_format($pedido['total'], 2);
        $nombre = htmlspecialchars($pedido['nombre'] ?? $pedido['telefono']);
        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="font-family:Arial,sans-serif;background:#f1f5f9;margin:0;padding:24px;">
<div style="max-width:580px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08);">
  <div style="background:#E07856;padding:36px 28px;text-align:center;">
    <div style="font-size:48px;margin-bottom:8px;">✅</div>
    <h1 style="color:#ffffff;margin:0;font-size:22px;font-weight:700;">¡Pedido Confirmado!</h1>
    <p style="color:#fde8d8;margin:6px 0 0;font-size:15px;">{$tienda}</p>
  </div>
  <div style="padding:32px 28px;">
    <p style="color:#64748b;font-size:15px;margin:0 0 6px;">Hola, <strong style="color:#1e293b;">{$nombre}</strong></p>
    <p style="color:#64748b;font-size:14px;margin:0 0 24px;">Tu pedido <strong>#{$pedido_id}</strong> ha sido confirmado y está siendo preparado.</p>
    <table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:16px;">
      <thead>
        <tr style="background:#f8fafc;">
          <th style="padding:8px;text-align:left;color:#64748b;font-weight:600;border-bottom:2px solid #e2e8f0;">Producto</th>
          <th style="padding:8px;text-align:center;color:#64748b;font-weight:600;border-bottom:2px solid #e2e8f0;">Cant.</th>
          <th style="padding:8px;text-align:right;color:#64748b;font-weight:600;border-bottom:2px solid #e2e8f0;">Subtotal</th>
        </tr>
      </thead>
      <tbody style="color:#1e293b;">
        {$items_html}
      </tbody>
    </table>
    <div style="text-align:right;padding:14px 0;border-top:2px solid #e2e8f0;">
      <span style="font-size:18px;font-weight:700;color:#E07856;">Total: {$total}</span>
    </div>
    <p style="color:#64748b;font-size:13px;margin:20px 0 0;">En breve recibirás información sobre el envío. Si tienes dudas, contáctanos.</p>
  </div>
  <div style="background:#f8fafc;padding:14px 28px;text-align:center;font-size:12px;color:#94a3b8;border-top:1px solid #e2e8f0;">
    Gracias por tu compra en <strong>{$tienda}</strong>
  </div>
</div>
</body>
</html>
HTML;
    }

    // ---------------------------------------------------------------
    // PÚBLICA: Resolver destinatario aplicando lógica de envío
    //
    // Retorna:
    //   null   → no enviar (correo desactivado)
    //   string → dirección a usar (prueba o la real)
    // ---------------------------------------------------------------
    public static function resolveRecipient(string $original): ?string {
        if (!intval(Configuracion::get('activar_envio_correo', '1'))) {
            return null; // Envío desactivado globalmente
        }
        $prueba = trim(Configuracion::get('correo_prueba', ''));
        if (!empty($prueba) && filter_var($prueba, FILTER_VALIDATE_EMAIL)) {
            return $prueba; // Redirigir a correo de prueba
        }
        return !empty($original) ? $original : null;
    }

    // ---------------------------------------------------------------
    // PÚBLICA: Correo de recepción de solicitud de inventario al rep
    // ---------------------------------------------------------------
    public static function sendSolicitudInventario($to_email, $nombre_rep, array $solicitud, array $detalle) {
        if (!self::validarEmail($to_email)) return false;
        $cfg = self::cfg();
        if (empty($cfg['from_email'])) return false;

        $tienda = Configuracion::get('nombre_tienda', 'Solumedic');
        $sol_id = str_pad((string)$solicitud['id'], 4, '0', STR_PAD_LEFT);
        $fecha  = date('d/M/Y H:i', strtotime($solicitud['created_at'] ?? 'now'));

        // Construir lista de productos (texto plano y filas HTML)
        $items_text = '';
        $items_html = '';
        foreach ($detalle as $item) {
            $prod = htmlspecialchars($item['producto'] ?? $item['nombre'] ?? '');
            $cant = (int)($item['cantidad_solicitada'] ?? $item['cantidad'] ?? 0);
            $items_text .= "- {$prod} x{$cant}\n";
            $items_html .= '<tr>'
                . "<td style=\"padding:6px 10px;border-bottom:1px solid #e2e8f0;\">{$prod}</td>"
                . "<td style=\"padding:6px 10px;border-bottom:1px solid #e2e8f0;text-align:center;\">{$cant}</td>"
                . '</tr>';
        }

        $asunto_tpl = Configuracion::get('email_solicitud_inventario_asunto', 'Recibimos tu solicitud de inventario #{id}');
        $cuerpo_tpl = Configuracion::get('email_solicitud_inventario_cuerpo', '');

        // Variables base (sin {productos} para separar versión texto vs HTML)
        $vars_base = [
            '{nombre}'  => $nombre_rep,
            '{id}'      => $sol_id,
            '{fecha}'   => $fecha,
            '{tienda}'  => $tienda,
        ];

        $asunto    = strtr($asunto_tpl, $vars_base + ['{productos}' => '']);
        $body_text = strtr(
            $cuerpo_tpl ?: "Hola {nombre},\n\nRecibimos tu solicitud de inventario #{id} con fecha {fecha}.\n\nProductos solicitados:\n{productos}\nEn breve te enviaremos confirmación del envío.\n\n{tienda}",
            $vars_base + ['{productos}' => $items_text]
        );
        // Para HTML: pasar la plantilla con vars básicas reemplazadas;
        // {productos} y {notas:...} los procesa htmlSolicitudInventario
        $cuerpo_para_html = strtr($cuerpo_tpl, $vars_base);
        $body_html = self::htmlSolicitudInventario($sol_id, $nombre_rep, $items_html, $fecha, $tienda, $cuerpo_para_html);

        // Copia al correo admin configurado (correo_copia_solicitudes)
        $cc = trim(Configuracion::get('correo_copia_solicitudes', ''));
        if (!filter_var($cc, FILTER_VALIDATE_EMAIL)) $cc = '';

        return self::enviar($cfg, $to_email, $asunto, $body_text, $body_html, [], $cc);
    }

    private static function htmlSolicitudInventario($sol_id, $nombre_rep, $items_html, $fecha, $tienda, $cuerpo = '') {
        $tienda_esc = htmlspecialchars($tienda);

        // Tabla completa de productos
        $products_table = '<table style="width:100%;border-collapse:collapse;font-size:14px;margin:0 0 16px;">'
            . '<thead><tr style="background:#e8f4fd;">'
            . '<th style="padding:8px 10px;text-align:left;color:#1B3464;font-weight:600;border-bottom:2px solid #29B5E8;">Producto</th>'
            . '<th style="padding:8px 10px;text-align:center;color:#1B3464;font-weight:600;border-bottom:2px solid #29B5E8;">Cant.</th>'
            . '</tr></thead>'
            . '<tbody style="color:#1e293b;">' . $items_html . '</tbody>'
            . '</table>';

        // Si no hay plantilla configurada, usar texto predeterminado
        if (empty(trim($cuerpo))) {
            $cuerpo = "Hola {$nombre_rep},\n\nRecibimos tu solicitud #{$sol_id} del {$fecha}.\n\n{productos}\n\nEn breve te enviaremos confirmación del envío.";
        }

        // Extraer {notas: ...} y convertirlos a caja azul SoluMedic
        $notas_html = '';
        $cuerpo = preg_replace_callback('/\{notas:\s*(.*?)\}/si', function ($m) use (&$notas_html) {
            $notas_html .= '<div style="background:#e8f4fd;border:1px solid #29B5E8;border-radius:10px;padding:14px 18px;margin-top:12px;">'
                . '<p style="margin:0;color:#1B3464;font-size:13px;">'
                . nl2br(htmlspecialchars(trim($m[1])))
                . '</p></div>';
            return '';
        }, $cuerpo);

        // Separar el texto por {productos} para insertar la tabla en el lugar correcto
        $partes = explode('{productos}', $cuerpo, 2);

        $render_parrafo = static function (string $texto): string {
            $texto = trim($texto);
            if ($texto === '') return '';
            $html = '';
            foreach (preg_split('/\n{2,}/', $texto) as $p) {
                $p = trim($p);
                if ($p !== '') {
                    $html .= '<p style="color:#475569;font-size:14px;margin:0 0 14px;">'
                        . nl2br(htmlspecialchars($p)) . '</p>';
                }
            }
            return $html;
        };

        $body_content  = $render_parrafo($partes[0]);
        $body_content .= $products_table;
        if (isset($partes[1])) {
            $body_content .= $render_parrafo($partes[1]);
        }
        $body_content .= $notas_html;

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="font-family:Arial,sans-serif;background:#f1f5f9;margin:0;padding:24px;">
<div style="max-width:580px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08);">
  <div style="background:#1B3464;padding:36px 28px;text-align:center;">
    <div style="font-size:48px;margin-bottom:8px;">📦</div>
    <h1 style="color:#ffffff;margin:0;font-size:22px;font-weight:700;">Solicitud de Inventario Recibida</h1>
    <p style="color:#29B5E8;margin:6px 0 0;font-size:15px;">{$tienda_esc}</p>
  </div>
  <div style="padding:32px 28px;">
    {$body_content}
  </div>
  <div style="background:#f8fafc;padding:14px 28px;text-align:center;font-size:12px;color:#94a3b8;border-top:1px solid #e2e8f0;">
    {$tienda_esc} — Inventario para representantes
  </div>
</div>
</body>
</html>
HTML;
    }

    private static function htmlFactura(array $pedido, $pedido_id, $tienda) {
        $total = '$' . number_format($pedido['total'], 2);
        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="font-family:Arial,sans-serif;background:#f1f5f9;margin:0;padding:24px;">
<div style="max-width:580px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08);">
  <div style="background:#6366f1;padding:36px 28px;text-align:center;">
    <div style="font-size:48px;margin-bottom:8px;">🧾</div>
    <h1 style="color:#ffffff;margin:0;font-size:22px;font-weight:700;">Factura Electrónica</h1>
    <p style="color:#e0e7ff;margin:6px 0 0;font-size:15px;">{$tienda}</p>
  </div>
  <div style="padding:32px 28px;">
    <p style="color:#64748b;font-size:15px;margin:0 0 8px;">Pedido <strong style="color:#1e293b;">#{$pedido_id}</strong></p>
    <p style="color:#64748b;font-size:14px;margin:0 0 20px;">Adjunto encontrarás tu factura electrónica (PDF y/o XML) correspondiente a tu compra.</p>
    <div style="background:#f8fafc;border-radius:10px;padding:16px 20px;display:inline-block;">
      <span style="font-size:17px;font-weight:700;color:#6366f1;">Total facturado: {$total}</span>
    </div>
    <p style="color:#64748b;font-size:13px;margin:20px 0 0;">Si tienes alguna pregunta sobre tu factura, no dudes en contactarnos.</p>
  </div>
  <div style="background:#f8fafc;padding:14px 28px;text-align:center;font-size:12px;color:#94a3b8;border-top:1px solid #e2e8f0;">
    Gracias por tu compra en <strong>{$tienda}</strong>
  </div>
</div>
</body>
</html>
HTML;
    }

    // ---------------------------------------------------------------
    // PÚBLICA: Notificación interna — pedido en "Por Verificar"
    // ---------------------------------------------------------------
    public static function sendNuevoPorVerificar(int $pedido_id, string $nombre_cliente, string $telefono, string $metodo_pago, string $total) {
        $correo_notif = trim(Configuracion::get('correo_notif_por_verificar', ''));
        error_log("[PorVerificar] correo_notif={$correo_notif}");
        if (!filter_var($correo_notif, FILTER_VALIDATE_EMAIL)) {
            error_log("[PorVerificar] correo inválido o vacío, abortando");
            return false;
        }

        $cfg = self::cfg();
        error_log("[PorVerificar] from_email={$cfg['from_email']} activar=" . Configuracion::get('activar_envio_correo', '1'));
        if (empty($cfg['from_email'])) return false;
        if (!intval(Configuracion::get('activar_envio_correo', '1'))) return false;

        $tienda   = Configuracion::get('nombre_tienda', 'Solumedic');
        $pedido_s = str_pad((string)$pedido_id, 4, '0', STR_PAD_LEFT);
        $fecha    = date('d/m/Y H:i');
        $metodo_s = htmlspecialchars(ucfirst(str_replace('_', ' ', $metodo_pago)));
        $cliente_s = htmlspecialchars($nombre_cliente);
        $tel_s    = htmlspecialchars($telefono);
        $total_s  = htmlspecialchars($total);

        $asunto   = "⚠️ Pedido #{$pedido_s} en Por Verificar — {$tienda}";

        $body_text = "Nuevo pedido pendiente de verificación.\n\n"
            . "Pedido: #{$pedido_s}\n"
            . "Cliente: {$nombre_cliente} ({$telefono})\n"
            . "Método de pago: {$metodo_pago}\n"
            . "Total: {$total}\n"
            . "Fecha: {$fecha}\n\n"
            . "Accede al panel para verificarlo.\n\n{$tienda}";

        $body_html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="font-family:Arial,sans-serif;background:#f1f5f9;margin:0;padding:24px;">
<div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08);">
  <div style="background:linear-gradient(135deg,#d97706,#b45309);padding:32px 28px;text-align:center;">
    <h1 style="color:#fff;margin:0;font-size:22px;font-weight:700;">⚠️ Pedido por verificar</h1>
    <p style="color:#fde68a;margin:6px 0 0;font-size:14px;">{$tienda}</p>
  </div>
  <div style="padding:28px;">
    <p style="color:#1e293b;font-size:15px;margin:0 0 20px;">Se recibió un comprobante de pago y el pedido está esperando verificación:</p>
    <table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:20px;">
      <tr style="border-bottom:1px solid #e2e8f0;">
        <td style="padding:10px 8px;color:#64748b;font-weight:600;">Pedido</td>
        <td style="padding:10px 8px;color:#1e293b;font-weight:700;">#{$pedido_s}</td>
      </tr>
      <tr style="border-bottom:1px solid #e2e8f0;background:#f8fafc;">
        <td style="padding:10px 8px;color:#64748b;font-weight:600;">Cliente</td>
        <td style="padding:10px 8px;color:#1e293b;">{$cliente_s}</td>
      </tr>
      <tr style="border-bottom:1px solid #e2e8f0;">
        <td style="padding:10px 8px;color:#64748b;font-weight:600;">Teléfono</td>
        <td style="padding:10px 8px;color:#1e293b;">{$tel_s}</td>
      </tr>
      <tr style="border-bottom:1px solid #e2e8f0;background:#f8fafc;">
        <td style="padding:10px 8px;color:#64748b;font-weight:600;">Método de pago</td>
        <td style="padding:10px 8px;color:#1e293b;">{$metodo_s}</td>
      </tr>
      <tr style="border-bottom:1px solid #e2e8f0;">
        <td style="padding:10px 8px;color:#64748b;font-weight:600;">Total</td>
        <td style="padding:10px 8px;color:#1e293b;font-weight:700;">{$total_s}</td>
      </tr>
      <tr style="background:#f8fafc;">
        <td style="padding:10px 8px;color:#64748b;font-weight:600;">Fecha</td>
        <td style="padding:10px 8px;color:#1e293b;">{$fecha}</td>
      </tr>
    </table>
    <p style="color:#64748b;font-size:13px;margin:0;">Accede al panel de administración para confirmar el pago.</p>
  </div>
  <div style="background:#f8fafc;padding:14px 28px;text-align:center;font-size:12px;color:#94a3b8;border-top:1px solid #e2e8f0;">
    Notificación interna de <strong>{$tienda}</strong>
  </div>
</div>
</body>
</html>
HTML;

        $result = self::enviar($cfg, $correo_notif, $asunto, $body_text, $body_html);
        error_log("[PorVerificar] enviar() resultado=" . ($result ? 'OK' : 'FAIL') . " para pedido #{$pedido_id}");
        return $result;
    }
}
