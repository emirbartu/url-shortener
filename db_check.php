<?php
// Database connection details
$host = 'localhost';
$dbname = 'url_shortener';
$username = 'url_user';
$password = 'password';

try {
    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);

    // Query link_lists table
    $stmt = $pdo->query("SELECT * FROM link_lists");
    echo "<h2>Link Lists</h2>";
    echo "<table border='1'><tr><th>ID</th><th>List Name</th><th>Short Code</th><th>Created At</th><th>Expiration Date</th></tr>";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['list_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['short_code']) . "</td>";
        echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
        echo "<td>" . htmlspecialchars($row['expiration_date']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";

    // Query list_items table
    $stmt = $pdo->query("SELECT * FROM list_items");
    echo "<h2>List Items</h2>";
    echo "<table border='1'><tr><th>ID</th><th>List ID</th><th>URL</th><th>Title</th><th>Description</th></tr>";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['list_id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['url']) . "</td>";
        echo "<td>" . htmlspecialchars($row['title']) . "</td>";
        echo "<td>" . htmlspecialchars($row['description']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";

} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
