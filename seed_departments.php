<?php
/**
 * Seed Departments - Advanced Clinic Suite
 */
require_once __DIR__ . '/config/database.php';

try {
    $pdo = db()->getConnection();
    
    $clinicId = 1; // Default clinic
    
    $departments = [
        ['General Medicine', 'Primary care for adults.'],
        ['Pediatrics', 'Medical care for infants, children, and adolescents.'],
        ['Gynecology', 'Health of the female reproductive system.'],
        ['Orthopedics', 'Care for the musculoskeletal system.'],
        ['Dermatology', 'Skin, hair, and nail conditions.'],
        ['Cardiology', 'Heart and blood vessel disorders.'],
        ['ENT', 'Ear, Nose, and Throat conditions.'],
        ['Dental', 'Oral health and hygiene.'],
        ['Neurology', 'Disorders of the nervous system.'],
        ['Psychiatry', 'Mental health disorders.']
    ];
    
    echo "Seeding departments for Clinic ID: $clinicId\n";
    
    foreach ($departments as $dept) {
        $name = $dept[0];
        $desc = $dept[1];
        
        // Check if exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE clinic_id = ? AND name = ?");
        $stmt->execute([$clinicId, $name]);
        if ($stmt->fetchColumn() == 0) {
            $stmt = $pdo->prepare("INSERT INTO departments (clinic_id, name, description, is_active) VALUES (?, ?, ?, 1)");
            $stmt->execute([$clinicId, $name, $desc]);
            echo "Inserted: $name\n";
        } else {
            echo "Skipped (exists): $name\n";
        }
    }
    
    echo "Done.\n";
    
} catch (\PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
