<?php
session_start();
// Define o fuso horário para São Paulo
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['usuario'])) {
    header('Location: index.php');
    exit;
}

// Configuração do diretório de backups
// Corrigido para o caminho fornecido pelo usuário
$baseBackupDir = '/var/www/NetSave_Config/python/backups';

// Sanitiza o caminho atual para evitar "path traversal" (../)
$currentPath = isset($_GET['path']) ? $_GET['path'] : '';
$fullCurrentDir = realpath($baseBackupDir . '/' . $currentPath);

// Garante que o caminho atual está dentro do diretório base de backups
if ($fullCurrentDir === false || strpos($fullCurrentDir, realpath($baseBackupDir)) !== 0) {
    // Se o caminho for inválido ou tentar acessar fora do diretório, redireciona para o diretório base
    header("Location: gerenciar_backups.php");
    exit;
}

// Ações: visualizar, download e excluir
if (isset($_GET['action']) && isset($_GET['file'])) {
    $filePath = $_GET['file'];
    $fullFilePath = realpath($baseBackupDir . '/' . $filePath);

    // Validação de segurança: o arquivo deve estar dentro do diretório de backups
    if ($fullFilePath === false || strpos($fullFilePath, realpath($baseBackupDir)) !== 0) {
        die("Acesso negado ou arquivo não encontrado.");
    }

    if (!file_exists($fullFilePath) || is_dir($fullFilePath)) {
        die("Arquivo não encontrado ou é um diretório!");
    }

    $fileName = basename($fullFilePath);

    switch ($_GET['action']) {
        case 'view':
            header('Content-Type: text/plain');
            readfile($fullFilePath);
            exit;

        case 'download':
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            header('Content-Length: ' . filesize($fullFilePath));
            readfile($fullFilePath);
            exit;

        case 'delete':
            unlink($fullFilePath);
            header("Location: gerenciar_backups.php?msg=" . urlencode("Arquivo $fileName excluído com sucesso"));
            exit;
    }
}

// --- Listar arquivos e pastas ---
$items = array_diff(scandir($fullCurrentDir), ['.', '..']);
$pastas = [];
$arquivos = [];

foreach ($items as $item) {
    $itemPath = $fullCurrentDir . '/' . $item;
    if (is_dir($itemPath)) {
        $pastas[] = $item;
    } else {
        $arquivos[] = $item;
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Gerenciador de Backups</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f4f4f4; }
        .container { max-width: 900px; margin: auto; }
        h1 { text-align: center; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px; border: 1px solid #ccc; text-align: left; background: white; }
        th { background: #eee; text-align: center; }
        td:nth-child(2) { text-align: right; }
        td:nth-child(3) { text-align: center; }
        a { text-decoration: none; color: blue; margin: 0 5px; }
        .msg { color: green; font-weight: bold; text-align: center; }
        .path { font-weight: bold; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <a href="painel.php" class="btn btn-blue" style="margin: 10px 0; display: inline-block;">← Voltar ao Painel</a>

        <h1>📂 Gerenciador de Backups</h1>

        <?php if (isset($_GET['msg'])): ?>
            <p class="msg"><?= htmlspecialchars($_GET['msg']) ?></p>
        <?php endif; ?>

        <div class="path">
            Caminho Atual: `<?= htmlspecialchars(str_replace(realpath($baseBackupDir), '', $fullCurrentDir) . '/') ?>`
        </div>

        <?php if (empty($pastas) && empty($arquivos)): ?>
            <p>Nenhum arquivo ou pasta de backup encontrado.</p>
        <?php else: ?>
            <table>
                <tr>
                    <th>Nome</th>
                    <th>Tamanho</th>
                    <th>Última Modificação</th>
                    <th>Ações</th>
                </tr>
                <?php
                // Link para voltar uma pasta, se não estiver no diretório raiz
                if ($fullCurrentDir !== realpath($baseBackupDir)): ?>
                    <tr>
                        <td>
                            <a href="?path=<?= urlencode(dirname($currentPath)) ?>">.../</a>
                        </td>
                        <td></td>
                        <td></td>
                        <td></td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($pastas as $pasta): ?>
                    <tr>
                        <td>
                            <a href="?path=<?= urlencode(trim($currentPath . '/' . $pasta, '/')) ?>">
                                📁 <?= htmlspecialchars($pasta) ?>
                            </a>
                        </td>
                        <td>-</td>
                        <td><?= date('d/m/Y H:i:s', filemtime($fullCurrentDir . '/' . $pasta)) ?></td>
                        <td>-</td>
                    </tr>
                <?php endforeach; ?>

                <?php foreach ($arquivos as $arquivo):
                    $caminho = $fullCurrentDir . '/' . $arquivo;
                ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($arquivo) ?>
                        </td>
                        <td><?= round(filesize($caminho) / 1024, 2) ?> KB</td>
                        <td><?= date('d/m/Y H:i:s', filemtime($caminho)) ?></td>
                        <td>
                            <a href="?action=view&file=<?= urlencode(trim($currentPath . '/' . $arquivo, '/')) ?>" target="_blank">👁 Ver</a> |
                            <a href="?action=download&file=<?= urlencode(trim($currentPath . '/' . $arquivo, '/')) ?>">⬇ Baixar</a> |
                            <a href="?action=delete&file=<?= urlencode(trim($currentPath . '/' . $arquivo, '/')) ?>" onclick="return confirm('Deseja realmente excluir este arquivo?')">🗑 Excluir</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>