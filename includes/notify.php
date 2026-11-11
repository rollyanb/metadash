<?php
// includes/notify.php — avisa o usuário no sino E dispara o push na hora.
//
// Antes, gravar em `notifications` só acendia o sino: o push era enviado apenas
// pelo cron de resumo, então avisos pontuais (nova demanda, virada de lote)
// nunca chegavam ao celular. Este helper resolve os dois de uma vez.

require_once __DIR__ . '/webpush.php';

if (!defined('VAPID_PUBLIC')) {
    define('VAPID_PUBLIC',  'BOD8saPXZbuLU_Tf1HvD-eNQekZBjmjcyuBeOhyd5Khld_THUGpP4adEgl_RD9q3YPr-oEDPezxS3buQk7Mippo');
    define('VAPID_PRIVATE', 'ejsdX8aOvZVs8xuw9uvriYbtB-xHqmLsvgTuCJjX-II');
    define('VAPID_EMAIL',   'mailto:contato@rollyan.com.br');
}

/**
 * Notifica uma lista de usuários.
 *
 * @param array  $userIds  ids dos destinatários
 * @param string $titulo   título curto
 * @param string $mensagem corpo
 * @param string $link     destino ao clicar (ex.: 'event.php?id=12')
 * @param bool   $push     também enviar push? (padrão: sim)
 * @return array ['sino' => n, 'push' => n, 'erros' => []]
 */
function notificar($db, $userIds, $titulo, $mensagem, $link = '', $push = true) {
    $userIds = array_values(array_unique(array_filter(array_map('intval', (array)$userIds))));
    $res = ['sino' => 0, 'push' => 0, 'erros' => []];
    if (!$userIds) return $res;

    // 1) sino
    foreach ($userIds as $uid) {
        try {
            $db->execute("INSERT INTO notifications (user_id, title, message, link) VALUES (?,?,?,?)",
                [$uid, mb_substr($titulo, 0, 255), mb_substr($mensagem, 0, 1000), $link]);
            $res['sino']++;
        } catch (Exception $e) { $res['erros'][] = 'sino: ' . $e->getMessage(); }
    }

    if (!$push) return $res;

    // 2) push — nunca deixa uma falha aqui atrapalhar a ação do usuário
    try {
        $motivo = null;
        if (!SimpleWebPush::disponivel($motivo)) {
            $res['erros'][] = 'push indisponível: ' . $motivo;
            return $res;
        }

        $ph = implode(',', array_fill(0, count($userIds), '?'));
        $subs = $db->fetchAll("SELECT user_id, endpoint, p256dh, auth
                               FROM push_subscriptions WHERE user_id IN ($ph)", $userIds);
        if (!$subs) { $res['erros'][] = 'nenhum dispositivo inscrito'; return $res; }

        $wp = new SimpleWebPush(VAPID_PUBLIC, VAPID_PRIVATE, VAPID_EMAIL);
        $payload = json_encode(['title' => $titulo, 'body' => $mensagem, 'url' => $link]);

        foreach ($subs as $s) {
            $r = $wp->send($s['endpoint'], $s['p256dh'], $s['auth'], $payload);
            if ($r['ok']) {
                $res['push']++;
            } else {
                $res['erros'][] = 'HTTP ' . $r['status'] . ': ' . mb_substr((string)$r['error'], 0, 60);
                if ($r['expirado']) {
                    try { $db->execute("DELETE FROM push_subscriptions WHERE endpoint=?", [$s['endpoint']]); } catch (Exception $e) {}
                }
            }
        }
    } catch (Exception $e) {
        $res['erros'][] = 'push: ' . $e->getMessage();
    }

    return $res;
}
