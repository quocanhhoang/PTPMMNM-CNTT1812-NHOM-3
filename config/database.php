<?php
    $host = "localhost";
    $port = "5432";
    $dbname = "fileSharing";
    $user = "postgres";
    $password = "anhanh1";

    $conn = pg_connect(
        "host=$host port=$port dbname=$dbname user=$user password=$password"
    );

    if (!$conn) {
        error_log("PostgreSQL connection failed.");
        throw new RuntimeException("Không thể kết nối tới cơ sở dữ liệu.");
    }
?>