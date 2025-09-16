<?php
session_start();
date_default_timezone_set('America/Porto_Velho');

if (!isset($_SESSION['usuario'])) {
    header('Location: index.php');
    exit;
}

// Diretório base (ajuste se necessário)
$baseBackupDir = '/var/www/NetSave_Config/python/backups';
$realBase = realpath($baseBackupDir);
if ($realBase === false) {
    die('Diretório base de backups inválido.');
}

// Obtém e normaliza o path vindo por GET
$currentPath = isset($_GET['path']) ? $_GET['path'] : '';
// Normaliza separadores e remove barras extras
$currentPath = str_replace('\\', '/', $currentPath);
$currentPath = preg_replace('#/+#', '/', $currentPath);
$currentPath = trim($currentPath, '/');

// Resolve o diretório atual de forma segura
$fullCurrentDir = ($currentPath === '') ? $realBase : realpath($realBase . '/' . $currentPath);
if ($fullCurrentDir === false || strpos($fullCurrentDir, $realBase) !== 0) {
    // caminho inválido — volta para a página de listagem (raiz)
    header('Location: gerenciar_backups.php');
    exit;
}

// Tratamento de ações em arquivos (view, download, delete)
if (isset($_GET['action']) && isset($_GET['file'])) {
    $fileRaw = $_GET['file'];
    $fileRaw = str_replace('\\', '/', $fileRaw);
    $fileRaw = preg_replace('#/+#', '/', $fileRaw);
    $fileRaw = trim($fileRaw, '/');

    $fullFilePath = realpath($realBase . '/' . $fileRaw);
    if ($fullFilePath === false || strpos($fullFilePath, $realBase) !== 0) {
        die("Acesso negado ou arquivo não encontrado.");
    }
    if (!file_exists($fullFilePath) || is_dir($fullFilePath)) {
        die("Arquivo não encontrado ou é um diretório!");
    }

    $fileName = basename($fullFilePath);

    switch ($_GET['action']) {
        case 'view':
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: inline; filename="' . $fileName . '"');
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
            // Redireciona de volta para a mesma pasta (preserva path)
            $redirect = 'gerenciar_backups.php' . ($currentPath !== '' ? '?path=' . urlencode($currentPath) : '');
            header("Location: {$redirect}&msg=" . urlencode("Arquivo $fileName excluído com sucesso"));
            exit;
    }
}

// Lista itens no diretório atual
$items = array_diff(scandir($fullCurrentDir), ['.', '..']);
$pastas = [];
$arquivos = [];
foreach ($items as $item) {
    if (is_dir($fullCurrentDir . '/' . $item)) {
        $pastas[] = $item;
    } else {
        $arquivos[] = $item;
    }
}

// Função para obter parentPath seguro (volta apenas 1 nível)
function obter_parent_path(string $currentPath): string {
    $currentPath = trim($currentPath, '/');
    if ($currentPath === '') return '';
    $parts = explode('/', $currentPath);
    array_pop($parts);
    return implode('/', $parts);
}

$parentPath = obter_parent_path($currentPath);
$displayPath = $currentPath === '' ? '' : $currentPath . '/';
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
        .top-links { margin-bottom: 10px; }
        .btn { padding: 6px 10px; border-radius: 4px; background: #2d8cf0; color: white; text-decoration: none; }
        .btn-secondary { background: #6c757d; }
    </style>
</head>
<body>
    <div class="container">
        <div class="top-links">
            <a href="painel.php" class="btn btn-secondary" style="margin-right:10px;">← Voltar ao Painel</a>
            <!-- Link para voltar um nível: construído explicitamente apontando para gerenciar_backups.php -->
            <?php
                $parentHref = 'gerenciar_backups.php' . ($parentPath !== '' ? '?path=' . urlencode($parentPath) : 'gerenciar_backups.php');
                // corrigir: se parentPath vazio, queremos apenas 'gerenciar_backups.php' sem duplicar
                if ($parentPath === '') $parentHref = 'gerenciar_backups.php';
            ?>
            <?php if ($fullCurrentDir !== $realBase): ?>
                <a href="<?= htmlspecialchars($parentHref) ?>" class="btn">.../ Voltar uma pasta</a>
            <?php endif; ?>
        </div>

        <h1>📂 Gerenciador de Backups</h1>

        <?php if (isset($_GET['msg'])): ?>
            <p class="msg"><?= htmlspecialchars($_GET['msg']) ?></p>
        <?php endif; ?>

        <div class="path">
            Caminho Atual: <?= htmlspecialchars($displayPath) ?>
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

                <?php foreach ($pastas as $pasta): 
                    $newPath = trim($currentPath . '/' . $pasta, '/');
                    $dirHref = 'gerenciar_backups.php?path=' . urlencode($newPath);
                ?>
                    <tr>
                        <td>
                            <a href="<?= htmlspecialchars($dirHref) ?>">
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
                    $fileRel = trim($currentPath . '/' . $arquivo, '/');
                    $viewHref = 'gerenciar_backups.php?action=view&file=' . urlencode($fileRel) . ($currentPath !== '' ? '&path=' . urlencode($currentPath) : '');
                    $downHref = 'gerenciar_backups.php?action=download&file=' . urlencode($fileRel) . ($currentPath !== '' ? '&path=' . urlencode($currentPath) : '');
                    $delHref  = 'gerenciar_backups.php?action=delete&file=' . urlencode($fileRel) . ($currentPath !== '' ? '&path=' . urlencode($currentPath) : '');
                ?>
                    <tr>
                        <td><?= htmlspecialchars($arquivo) ?></td>
                        <td><?= round(filesize($caminho) / 1024, 2) ?> KB</td>
                        <td><?= date('d/m/Y H:i:s', filemtime($caminho)) ?></td>
                        <td>
                            <a href="<?= htmlspecialchars($viewHref) ?>" target="_blank">👁 Ver</a> |
                            <a href="<?= htmlspecialchars($downHref) ?>">⬇ Baixar</a> |
                            <a href="<?= htmlspecialchars($delHref) ?>" onclick="return confirm('Deseja realmente excluir este arquivo?')">🗑 Excluir</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>
