<?php
/**
 * REST API Communication Endpoints - Feature Gen Care
 * GET    /api/v1/communication.php?type=templates   (list templates)
 * GET    /api/v1/communication.php?type=logs         (list logs)
 * POST   /api/v1/communication.php                   (create/update template)
 * DELETE /api/v1/communication.php?id=N              (delete template)
 */
require_once __DIR__ . '/middleware/auth.php';
$auth = authenticateApiRequest();
$db = db();
$clinicId = intval($auth['user']['clinic_id'] ?? 1);
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $type = $_GET['type'] ?? 'templates';
        if ($type === 'logs') {
            $page = max(1, intval($_GET['page'] ?? 1));
            $limit = min(50, max(10, intval($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            $logs = $db->fetchAll(
                "SELECT cl.*, p.first_name, p.last_name, p.phone as patient_phone
                 FROM communication_logs cl
                 LEFT JOIN patients p ON cl.patient_id = p.id
                 WHERE cl.clinic_id = ? ORDER BY cl.created_at DESC LIMIT $limit OFFSET $offset", [$clinicId]
            );
            $total = $db->fetch("SELECT COUNT(*) as c FROM communication_logs WHERE clinic_id = ?", [$clinicId])['c'] ?? 0;
            jsonResponse(true, ['logs' => $logs], null, 200, ['total' => intval($total), 'page' => $page, 'limit' => $limit]);
        } else {
            $templates = $db->fetchAll("SELECT * FROM message_templates WHERE clinic_id = ? ORDER BY name", [$clinicId]);
            jsonResponse(true, ['templates' => $templates]);
        }
        break;

    case 'POST':
        $input = getJsonInput();
        $id = intval($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        $channel = $input['channel'] ?? 'sms';
        $eventTrigger = trim($input['event_trigger'] ?? '');
        $subject = trim($input['subject'] ?? '');
        $body = trim($input['body'] ?? '');
        $isActive = intval($input['is_active'] ?? 1);

        if (empty($name) || empty($body)) {
            jsonResponse(false, null, 'Name and body are required.', 400);
        }

        try {
            if ($id > 0) {
                $db->query(
                    "UPDATE message_templates SET name=?, channel=?, event_trigger=?, subject=?, body=?, is_active=? WHERE id=? AND clinic_id=?",
                    [$name, $channel, $eventTrigger ?: null, $subject ?: null, $body, $isActive, $id, $clinicId]
                );
                jsonResponse(true, ['message' => 'Template updated']);
            } else {
                $db->query(
                    "INSERT INTO message_templates (clinic_id, name, channel, event_trigger, subject, body, is_active) VALUES (?,?,?,?,?,?,?)",
                    [$clinicId, $name, $channel, $eventTrigger ?: null, $subject ?: null, $body, $isActive]
                );
                jsonResponse(true, ['id' => intval($db->lastInsertId())], null, 201);
            }
        } catch (Exception $e) {
            jsonResponse(false, null, $e->getMessage(), 500);
        }
        break;

    case 'DELETE':
        $id = intval($_GET['id'] ?? 0);
        if (!$id) jsonResponse(false, null, 'Template ID required', 400);
        $db->query("DELETE FROM message_templates WHERE id = ? AND clinic_id = ?", [$id, $clinicId]);
        jsonResponse(true, ['message' => 'Template deleted']);
        break;

    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}
