<?php

require_once '../../../config/database.php';

if (!isset($_GET['client_id'])) {
    exit;
}

$client_id = (int)$_GET['client_id'];

$stmt = $db->prepare("
    SELECT
        id,
        project_name,
        cost,
        monthly_fee
    FROM projects
    WHERE client_id = ?
    AND status != 'Completed'
");

$stmt->bind_param("i", $client_id);
$stmt->execute();

$result = $stmt->get_result();

$data = [];

while($row = $result->fetch_assoc()) {

    $amount = $row['cost'] ?: $row['monthly_fee'];

    $data[] = [
        'id' => $row['id'],
        'name' => $row['project_name'],
        'amount' => $amount
    ];
}

header('Content-Type: application/json');
echo json_encode($data);