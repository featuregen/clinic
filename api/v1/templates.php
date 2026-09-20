<?php
/**
 * REST API Prescription Templates Endpoints - Feature Gen Care
 * GET    /api/v1/templates.php       (list templates)
 * GET    /api/v1/templates.php?id=N  (get single)
 * POST   /api/v1/templates.php       (create)
 * PUT    /api/v1/templates.php?id=N  (update)
 * DELETE /api/v1/templates.php?id=N  (delete)
 */
require_once __DIR__ . '/middleware/auth.php';
$auth = authenticateApiRequest();
$db = db();
$clinicId = intval($auth['user']['clinic_id'] ?? 1);
$method = $_SERVER['REQUEST_METHOD'];

// Ensure prescription_templates table exists
try {
    $db->query("CREATE TABLE IF NOT EXISTS prescription_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        clinic_id INT NOT NULL,
        name VARCHAR(200) NOT NULL,
        category VARCHAR(100) NULL,
        diagnosis TEXT NULL,
        medicines JSON NULL,
        instructions TEXT NULL,
        lab_tests TEXT NULL,
        follow_up_days INT NULL,
        created_by INT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_clinic (clinic_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {}

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            $tmpl = $db->fetch("SELECT * FROM prescription_templates WHERE id=? AND clinic_id=?", [intval($_GET['id']), $clinicId]);
            if (!$tmpl) jsonResponse(false, null, 'Template not found', 404);
            if (!empty($tmpl['medicines']) && is_string($tmpl['medicines'])) {
                $tmpl['medicines'] = json_decode($tmpl['medicines'], true);
            }
            jsonResponse(true, $tmpl);
        }
        $templates = $db->fetchAll("SELECT id, name, category, diagnosis, follow_up_days, is_active, created_at FROM prescription_templates WHERE clinic_id=? ORDER BY name", [$clinicId]);
        jsonResponse(true, ['templates' => $templates]);
        break;

    case 'POST':
        $input = getJsonInput();
        $name = trim($input['name'] ?? '');
        if (empty($name)) jsonResponse(false, null, 'Template name is required.', 400);

        $medicines = isset($input['medicines']) ? json_encode($input['medicines']) : null;
        try {
            $db->query(
                "INSERT INTO prescription_templates (clinic_id, name, category, diagnosis, medicines, instructions, lab_tests, follow_up_days, created_by) VALUES (?,?,?,?,?,?,?,?,?)",
                [$clinicId, $name, trim($input['category'] ?? '') ?: null, trim($input['diagnosis'] ?? '') ?: null, $medicines, trim($input['instructions'] ?? '') ?: null, trim($input['lab_tests'] ?? '') ?: null, intval($input['follow_up_days'] ?? 0) ?: null, $auth['user']['id']]
            );
            jsonResponse(true, ['id' => intval($db->lastInsertId())], null, 201);
        } catch (Exception $e) {
            jsonResponse(false, null, $e->getMessage(), 500);
        }
        break;

    case 'PUT':
        $id = intval($_GET['id'] ?? 0);
        if (!$id) jsonResponse(false, null, 'Template ID required', 400);
        $tmpl = $db->fetch("SELECT id FROM prescription_templates WHERE id=? AND clinic_id=?", [$id, $clinicId]);
        if (!$tmpl) jsonResponse(false, null, 'Template not found', 404);

        $input = getJsonInput();
        $medicines = isset($input['medicines']) ? json_encode($input['medicines']) : null;
        try {
            $db->query(
                "UPDATE prescription_templates SET name=?, category=?, diagnosis=?, medicines=?, instructions=?, lab_tests=?, follow_up_days=?, is_active=? WHERE id=?",
                [trim($input['name'] ?? ''), trim($input['category'] ?? '') ?: null, trim($input['diagnosis'] ?? '') ?: null, $medicines, trim($input['instructions'] ?? '') ?: null, trim($input['lab_tests'] ?? '') ?: null, intval($input['follow_up_days'] ?? 0) ?: null, intval($input['is_active'] ?? 1), $id]
            );
            jsonResponse(true, ['message' => 'Template updated']);
        } catch (Exception $e) {
            jsonResponse(false, null, $e->getMessage(), 500);
        }
        break;

    case 'DELETE':
        $id = intval($_GET['id'] ?? 0);
        if (!$id) jsonResponse(false, null, 'Template ID required', 400);
        $db->query("DELETE FROM prescription_templates WHERE id=? AND clinic_id=?", [$id, $clinicId]);
        jsonResponse(true, ['message' => 'Template deleted']);
        break;

    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}
