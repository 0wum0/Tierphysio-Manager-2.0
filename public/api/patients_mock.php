<?php
/**
 * Mock API Response for Testing - Simulates working patient list with owners
 */

header('Content-Type: application/json; charset=utf-8');

// Mock data simulating a working database response
$mockPatients = [
    [
        'id' => 1,
        'patient_number' => 'P20241001',
        'patient_name' => 'Bello',
        'species' => 'dog',
        'breed' => 'Labrador',
        'gender' => 'male',
        'birth_date' => '2020-03-15',
        'created_at' => '2024-10-01 10:00:00',
        'is_active' => true,
        'owner_id' => 1,
        'owner_name' => 'Anna Müller',
        'owner_customer_number' => 'K20241001'
    ],
    [
        'id' => 2,
        'patient_number' => 'P20241002',
        'patient_name' => 'Luna',
        'species' => 'cat',
        'breed' => 'Europäisch Kurzhaar',
        'gender' => 'female',
        'birth_date' => '2019-07-22',
        'created_at' => '2024-10-01 11:00:00',
        'is_active' => true,
        'owner_id' => 1,
        'owner_name' => 'Anna Müller',
        'owner_customer_number' => 'K20241001'
    ],
    [
        'id' => 3,
        'patient_number' => 'P20241003',
        'patient_name' => 'Max',
        'species' => 'dog',
        'breed' => 'Schäferhund',
        'gender' => 'male',
        'birth_date' => '2018-11-05',
        'created_at' => '2024-10-02 09:00:00',
        'is_active' => true,
        'owner_id' => 2,
        'owner_name' => 'Thomas Schmidt',
        'owner_customer_number' => 'K20241002'
    ],
    [
        'id' => 4,
        'patient_number' => 'P20241004',
        'patient_name' => 'Felix',
        'species' => 'cat',
        'breed' => 'Maine Coon',
        'gender' => 'male',
        'birth_date' => '2021-02-28',
        'created_at' => '2024-10-03 14:00:00',
        'is_active' => true,
        'owner_id' => 3,
        'owner_name' => 'Maria Wagner',
        'owner_customer_number' => 'K20241003'
    ],
    [
        'id' => 5,
        'patient_number' => 'P20241005',
        'patient_name' => 'Stella',
        'species' => 'horse',
        'breed' => 'Haflinger',
        'gender' => 'female',
        'birth_date' => '2015-06-10',
        'created_at' => '2024-10-04 16:00:00',
        'is_active' => true,
        'owner_id' => 4,
        'owner_name' => 'Michael Becker',
        'owner_customer_number' => 'K20241004'
    ],
    [
        'id' => 6,
        'patient_number' => 'P20241006',
        'patient_name' => 'Streuner',
        'species' => 'cat',
        'breed' => 'Mischling',
        'gender' => 'unknown',
        'birth_date' => null,
        'created_at' => '2024-10-05 08:00:00',
        'is_active' => true,
        'owner_id' => null,
        'owner_name' => '—',
        'owner_customer_number' => null
    ]
];

$action = $_GET['action'] ?? 'list';

if ($action === 'list' || $action === 'get_all') {
    echo json_encode([
        'ok' => true,
        'items' => $mockPatients,
        'count' => count($mockPatients),
        'message' => 'Mock data - Database not connected'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} else {
    echo json_encode([
        'ok' => false,
        'error' => 'Unknown action: ' . $action
    ], JSON_UNESCAPED_UNICODE);
}