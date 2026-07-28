<?php
/**
 * StockPilot SMTP Mailer
 * Lightweight socket-based SMTP client. No external dependencies.
 * Uses credentials from smtp_config.php.
 */

require_once __DIR__ . '/smtp_config.php';

/**
 * Send an email via SMTP.
 *
 * @param string       $to      Recipient address (or "Name <addr>")
 * @param string       $subject Subject line
 * @param string       $body    HTML body
 * @param string|null  $replyTo Optional reply-to address
 * @return bool
 */
function sendEmail($to, $subject, $body, $replyTo = null) {
    if (!SMTP_USERNAME || !SMTP_PASSWORD) {
        logError("SMTP not configured: missing username/password");
        return false;
    }

    try {
        $host = SMTP_HOST;
        $port = SMTP_PORT;
        $secure = strtolower(SMTP_SECURE);

        // Open socket
        $context = stream_context_create();
        if ($secure === 'ssl') {
            $addr = "ssl://$host";
        } else {
            $addr = "tcp://$host";
        }

        $socket = stream_socket_client("$addr:$port", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            logError("SMTP connect failed ($errno): $errstr");
            return false;
        }
        stream_set_timeout($socket, 15);

        // Helper: read one SMTP response line
        $read = function() use ($socket) {
            $buf = '';
            while (!feof($socket)) {
                $line = fgets($socket, 512);
                $buf .= $line;
                // Multi-line responses have a dash after the code
                if (strlen($line) >= 4 && $line[3] === ' ') break;
            }
            return $buf;
        };

        // Helper: send command and get reply
        $cmd = function($command) use ($socket, $read) {
            fwrite($socket, $command . "\r\n");
            return $read();
        };

        $read(); // greeting

        // EHLO / STARTTLS handshake
        $ehlo = "EHLO " . (gethostname() ?: 'localhost');
        $cmd($ehlo);

        if ($secure === 'tls') {
            $reply = $cmd('STARTTLS');
            if (strpos($reply, '220') === false) {
                logError("SMTP STARTTLS failed: $reply");
                fclose($socket);
                return false;
            }
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                logError("SMTP TLS negotiation failed");
                fclose($socket);
                return false;
            }
            $cmd($ehlo); // re-greet after TLS
        }

        // AUTH LOGIN
        $cmd('AUTH LOGIN');
        $cmd(base64_encode(SMTP_USERNAME));
        $authReply = $cmd(base64_encode(SMTP_PASSWORD));
        if (strpos($authReply, '235') === false) {
            logError("SMTP auth failed: $authReply");
            fclose($socket);
            return false;
        }

        // Normalise from/to
        $fromEmail = SMTP_FROM_EMAIL;
        $fromName  = SMTP_FROM_NAME;
        $toEmail   = _extractEmail($to);

        $cmd("MAIL FROM:<$fromEmail>");
        $rcptReply = $cmd("RCPT TO:<$toEmail>");
        if (strpos($rcptReply, '250') === false) {
            logError("SMTP RCPT rejected for $toEmail: $rcptReply");
            fclose($socket);
            return false;
        }

        $cmd('DATA');

        // Build message headers + body
        $headers  = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <$fromEmail>\r\n";
        $headers .= "To: $to\r\n";
        $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: base64\r\n";
        if ($replyTo) {
            $headers .= "Reply-To: $replyTo\r\n";
        }
        $headers .= "X-Mailer: StockPilot/1.0\r\n";
        $headers .= "Date: " . date('r') . "\r\n";

        $encodedBody = chunk_split(base64_encode($body));
        $dataReply = $cmd($headers . "\r\n" . $encodedBody . "\r\n.");
        if (strpos($dataReply, '250') === false) {
            logError("SMTP DATA rejected: $dataReply");
            fclose($socket);
            return false;
        }

        $cmd('QUIT');
        fclose($socket);
        return true;

    } catch (Exception $e) {
        logError("sendEmail exception: " . $e->getMessage());
        return false;
    }
}

/**
 * Send email to all admin/manager users for a given store.
 * Returns count of successful sends.
 */
function sendEmailToStoreAdmins($conn, $store_id, $subject, $body) {
    $stmt = $conn->prepare("
        SELECT ul.email, ul.username
        FROM user_login ul
        JOIN staff_details sd ON ul.user_id = sd.user_id
        WHERE sd.store_id = ? AND sd.status = 'active'
          AND ul.email IS NOT NULL AND ul.email != ''
          AND sd.role IN ('admin', 'manager', 'owner')
        LIMIT 10
    ");
    // PDO bind
$stmt->execute([$store_id]);$stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    

    // Fallback: store owner from stores table
    if (empty($rows)) {
        $s2 = $conn->prepare("SELECT owner_email, owner_name FROM stores WHERE store_id = ? LIMIT 1");
        if ($s2) {
            // PDO bind
$s2->execute([$store_id]);$s2->execute();
            $owner = $s2->fetch(PDO::FETCH_ASSOC);
            
            if ($owner && !empty($owner['owner_email'])) {
                $rows = [['email' => $owner['owner_email'], 'username' => $owner['owner_name'] ?? 'Owner']];
            }
        }
    }

    $sent = 0;
    foreach ($rows as $row) {
        $recipient = $row['username'] ? "{$row['username']} <{$row['email']}>" : $row['email'];
        if (sendEmail($recipient, $subject, $body)) {
            $sent++;
        }
    }
    return $sent;
}

/** Extract bare email address from "Name <email>" or plain "email" */
function _extractEmail($addr) {
    if (preg_match('/<([^>]+)>/', $addr, $m)) {
        return trim($m[1]);
    }
    return trim($addr);
}
?>
