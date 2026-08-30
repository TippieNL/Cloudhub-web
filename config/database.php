<?php
return [
 'dsn'=>sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', env('DB_HOST','127.0.0.1'), env('DB_PORT','3306'), env('DB_NAME','cloud_file_hub'), env('DB_CHARSET','utf8mb4')),
 'user'=>(string)env('DB_USER','root'), 'pass'=>(string)env('DB_PASS','')
];
