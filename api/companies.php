<?php
// api/companies.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

Auth::requireLogin();
Auth::requireAdmin();

header('Content-Type: application/json; charset=utf-8');

$db   = Database::getInstance();
$uid  = Auth::getUserId();
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

if ($action === 'create_company' || $action === 'update_company') {
    $name    = trim($body['name'] ?? '');
    $icon    = trim($body['icon'] ?? '🏢');
    $id      = intval($body['id'] ?? 0);
    $metaAcc = $body['meta_accounts']   ?? [];
    $ttkAcc  = $body['tiktok_accounts'] ?? [];
    $userIds = $body['user_ids']        ?? [];

    if (!$name) { echo json_encode(['error'=>'Nome obrigatório']); exit; }

    // Normaliza e remove repetidos (a tela pode mandar a mesma conta 2x)
    $limpa = function ($lista) {
        $out = [];
        foreach ((array)$lista as $a) {
            $a = trim((string)$a);
            if ($a === '') continue;
            $out[strtolower($a)] = $a;   // ACT_123 e act_123 são a mesma conta
        }
        return array_values($out);
    };
    $metaAcc = $limpa($metaAcc);
    $ttkAcc  = $limpa($ttkAcc);

    // Uma conta pertence a UMA empresa. Avisa antes de tentar gravar.
    $conflitos = [];
    $todas = array_merge($metaAcc, $ttkAcc);
    if ($todas) {
        $ph      = implode(',', array_fill(0, count($todas), '?'));
        $params  = array_map('strtolower', $todas);
        $sqlConf = "SELECT ca.account_id, c.name AS empresa, a.account_name
                    FROM company_accounts ca
                    JOIN companies c ON c.id = ca.company_id
                    LEFT JOIN ad_accounts a ON a.account_id = ca.account_id
                    WHERE LOWER(ca.account_id) IN ($ph)";
        if ($action !== 'create_company' && $id) {
            $sqlConf  .= " AND ca.company_id <> ?";
            $params[]  = $id;
        }
        foreach ($db->fetchAll($sqlConf, $params) as $c) {
            $conflitos[] = ($c['account_name'] ?: $c['account_id']) . ' → já está em "' . $c['empresa'] . '"';
        }
    }
    if ($conflitos) {
        echo json_encode(['error' => 'Estas contas já pertencem a outra empresa: ' . implode(' · ', $conflitos)
            . '. Remova de lá antes de vincular aqui.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $criouAgora = false;
    if ($action === 'create_company') {
        $id = $db->insert("INSERT INTO companies (name, icon, created_by) VALUES (?,?,?)", [$name, $icon, $uid]);
        $criouAgora = true;
    } else {
        $db->execute("UPDATE companies SET name=?, icon=? WHERE id=?", [$name, $icon, $id]);
    }

    // Reconstrói vínculos de contas
    try {
        $db->execute("DELETE FROM company_accounts WHERE company_id=?", [$id]);
        foreach ($metaAcc as $accId) {
            $db->execute("INSERT INTO company_accounts (company_id, account_id, platform) VALUES (?,?,'meta')", [$id, $accId]);
        }
        foreach ($ttkAcc as $accId) {
            $db->execute("INSERT INTO company_accounts (company_id, account_id, platform) VALUES (?,?,'tiktok')", [$id, $accId]);
        }
    } catch (Exception $e) {
        // Não deixa empresa criada pela metade
        if ($criouAgora) {
            try {
                $db->execute("DELETE FROM company_accounts WHERE company_id=?", [$id]);
                $db->execute("DELETE FROM companies WHERE id=?", [$id]);
            } catch (Exception $e2) {}
        }
        $msg = $e->getMessage();
        if (strpos($msg, 'unique_account') !== false || strpos($msg, '1062') !== false) {
            $msg = 'Uma das contas selecionadas já está vinculada a outra empresa.';
        }
        echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Reconstrói vínculos de usuários
    $db->execute("DELETE FROM company_user_access WHERE company_id=?", [$id]);
    foreach ($userIds as $userId) {
        $db->execute("INSERT INTO company_user_access (company_id, user_id) VALUES (?,?)", [$id, intval($userId)]);
    }

    echo json_encode(['success'=>true, 'id'=>$id], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'delete_company') {
    $id = intval($body['id'] ?? 0);
    if (!$id) { echo json_encode(['error'=>'ID inválido']); exit; }
    $db->execute("DELETE FROM company_accounts WHERE company_id=?", [$id]);
    $db->execute("DELETE FROM company_user_access WHERE company_id=?", [$id]);
    $db->execute("DELETE FROM companies WHERE id=?", [$id]);
    echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error'=>'Ação inválida'], JSON_UNESCAPED_UNICODE);