<?php
// Configuração MySQL
$host = 'localhost';
$dbname = 'net_backup';
$username = 'net_backup_user'; // Mantenha 'root' se essa é a credencial correta
$password = 'net_backup_user'; // Mantenha a sua senha do root

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Para ambientes de produção, use uma mensagem genérica para evitar vazar detalhes
    // die("Erro de conexão com o banco de dados.");
    // Para depuração, a mensagem detalhada é útil:
    die("Erro de conexão com o banco de dados: " . $e->getMessage());
}
?>