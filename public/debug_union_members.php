<?php
/**
 * Debug Script for Union Members
 * Run this to see what data is in your database
 */

require_once __DIR__ . '/../config/db.php';

// Get union_id from query string or use a test value
$union_id = $_GET['union_id'] ?? 1; // Change this to your actual union_id

echo "<h2>Union Members Debug Report</h2>";
echo "<p><strong>Testing with union_id:</strong> $union_id</p>";
echo "<hr>";

// Step 1: Check if union exists
echo "<h3>Step 1: Check Union</h3>";
$stmt = $mysqli->prepare("SELECT * FROM unions WHERE union_id = ?");
$stmt->bind_param("i", $union_id);
$stmt->execute();
$unionResult = $stmt->get_result();

if ($unionResult->num_rows > 0) {
    $union = $unionResult->fetch_assoc();
    echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px;'>";
    echo "✅ Union found: <strong>{$union['union_name_en']}</strong>";
    echo "</div>";
} else {
    echo "<div style='background: #f8d7da; padding: 10px; border-radius: 5px;'>";
    echo "❌ Union not found!";
    echo "</div>";
}
$stmt->close();

echo "<hr>";

// Step 2: Check users in this union
echo "<h3>Step 2: Check Users</h3>";
$stmt = $mysqli->prepare("SELECT COUNT(*) as total FROM users WHERE union_id = ?");
$stmt->bind_param("i", $union_id);
$stmt->execute();
$result = $stmt->get_result();
$count = $result->fetch_assoc();
$stmt->close();

echo "<p>Total users in this union: <strong>{$count['total']}</strong></p>";

if ($count['total'] == 0) {
    echo "<div style='background: #fff3cd; padding: 10px; border-radius: 5px;'>";
    echo "⚠️ No users found for this union!<br>";
    echo "Please check:<br>";
    echo "1. Is the union_id correct?<br>";
    echo "2. Are there users assigned to this union?<br>";
    echo "3. Check the users table in the database.";
    echo "</div>";
    exit;
}

echo "<hr>";

// Step 3: Check roles
echo "<h3>Step 3: Check Roles</h3>";
$stmt = $mysqli->prepare("SELECT * FROM roles ORDER BY id");
$stmt->execute();
$rolesResult = $stmt->get_result();

echo "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%;'>";
echo "<tr style='background: #e9ecef;'>";
echo "<th>ID</th><th>Role Name</th><th>Description</th>";
echo "</tr>";

while ($role = $rolesResult->fetch_assoc()) {
    echo "<tr>";
    echo "<td>{$role['id']}</td>";
    echo "<td><strong>{$role['role_name']}</strong></td>";
    echo "<td>{$role['description']}</td>";
    echo "</tr>";
}
echo "</table>";
$stmt->close();

echo "<hr>";

// Step 4: Fetch union members with roles
echo "<h3>Step 4: Union Members with Roles</h3>";
$stmt = $mysqli->prepare("
    SELECT 
        u.user_id,
        u.name_bn,
        u.name_en,
        u.role_id,
        u.phone_number AS phone,
        u.email,
        u.is_deleted,
        r.role_name
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    WHERE u.union_id = ?
    ORDER BY u.role_id, u.name_bn
");

$stmt->bind_param("i", $union_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background: #e9ecef;'>";
    echo "<th>User ID</th><th>Name (BN)</th><th>Name (EN)</th><th>Role ID</th><th>Role Name</th><th>Phone</th><th>Email</th><th>Deleted?</th>";
    echo "</tr>";

    while ($row = $result->fetch_assoc()) {
        $bgColor = $row['is_deleted'] ? '#f8d7da' : '#ffffff';
        echo "<tr style='background: $bgColor;'>";
        echo "<td>{$row['user_id']}</td>";
        echo "<td><strong>{$row['name_bn']}</strong></td>";
        echo "<td>{$row['name_en']}</td>";
        echo "<td>{$row['role_id']}</td>";
        echo "<td><strong>{$row['role_name']}</strong></td>";
        echo "<td>{$row['phone']}</td>";
        echo "<td>{$row['email']}</td>";
        echo "<td>" . ($row['is_deleted'] ? '❌ Yes' : '✅ No') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<div style='background: #f8d7da; padding: 10px; border-radius: 5px;'>";
    echo "❌ No users found with JOIN query!<br>";
    echo "This could mean:<br>";
    echo "1. The role_id in users table doesn't match any id in roles table<br>";
    echo "2. Data integrity issue between users and roles tables";
    echo "</div>";
}
$stmt->close();

echo "<hr>";

// Step 5: Build the unionMembers array (same as in your code)
echo "<h3>Step 5: Union Members Array Structure</h3>";

$unionMembers = [];

$stmt = $mysqli->prepare("
    SELECT 
        u.user_id,
        u.name_bn,
        u.name_en,
        u.role_id,
        u.phone_number AS phone,
        u.email,
        r.role_name
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    WHERE u.union_id = ? AND u.is_deleted = 0
    ORDER BY u.role_id, u.name_bn
");

$stmt->bind_param("i", $union_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $roleId = (int)$row['role_id'];

    if (!isset($unionMembers[$roleId])) {
        $unionMembers[$roleId] = [
            'role_name' => $row['role_name'],
            'role_id' => $roleId,
            'members' => []
        ];
    }

    $unionMembers[$roleId]['members'][] = [
        'user_id' => $row['user_id'],
        'name_bn' => $row['name_bn'],
        'name_en' => $row['name_en'],
        'phone' => $row['phone'],
        'email' => $row['email']
    ];
}
$stmt->close();

if (!empty($unionMembers)) {
    echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px; margin-bottom: 20px;'>";
    echo "✅ Union Members array created successfully!";
    echo "</div>";
    
    echo "<pre style='background: #f8f9fa; padding: 15px; border-radius: 5px; overflow-x: auto;'>";
    echo htmlspecialchars(print_r($unionMembers, true));
    echo "</pre>";
    
    // Show what will be rendered in dropdown
    echo "<h4>Dropdown Preview:</h4>";
    echo "<select class='form-control' size='10' style='width: 100%;'>";
    echo "<option value=''>-- যাচাইকারী নির্বাচন করুন --</option>";
    
    foreach ($unionMembers as $roleId => $group) {
        echo "<optgroup label='" . htmlspecialchars($group['role_name']) . "'>";
        foreach ($group['members'] as $member) {
            $display = htmlspecialchars($member['name_bn']);
            if (!empty($member['name_en'])) {
                $display .= " (" . htmlspecialchars($member['name_en']) . ")";
            }
            $display .= " - 📱 " . htmlspecialchars($member['phone']);
            echo "<option value='{$member['user_id']}'>$display</option>";
        }
        echo "</optgroup>";
    }
    echo "</select>";
    
} else {
    echo "<div style='background: #f8d7da; padding: 10px; border-radius: 5px;'>";
    echo "❌ Union Members array is EMPTY!<br>";
    echo "Check the previous steps to see where the data is missing.";
    echo "</div>";
}

echo "<hr>";
echo "<h3>✅ Debug Complete</h3>";
echo "<p>If you see empty names or role IDs instead of role names, check:</p>";
echo "<ol>";
echo "<li>Are the <code>name_bn</code> and <code>phone_number</code> columns actually filled in the users table?</li>";
echo "<li>Is the <code>role_name</code> column filled in the roles table?</li>";
echo "<li>Are the role IDs in users table matching the IDs in roles table?</li>";
echo "<li>Check for NULL values in the database</li>";
echo "</ol>";
