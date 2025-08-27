<?php
session_start();

if (!isset($_SESSION['usuario'])) {
    header('Location: index.php');
    exit;
}

// Inclui o arquivo de conexão com o banco de dados
require 'conexao.php';

// --- Inserir dispositivo ---
if (isset($_POST['adicionar'])) {
    $ip = $_POST['ip'];
    $porta = $_POST['porta_ssh'];
    $usuario = $_POST['usuario'];
    $senha_pura = $_POST['senha'];
    $vendor = $_POST['vendor'];

    // ATENÇÃO: A lógica de hash de senha foi removida.
    // As senhas serão salvas em texto puro no banco de dados.
    // O hostname não é mais um campo do formulário de adicionar
    
    // A coluna 'hostname' no banco de dados será preenchida pelo script Python posteriormente.
    $stmt = $db->prepare("INSERT INTO dispositivos (ip, porta_ssh, usuario, senha, vendor) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$ip, $porta, $usuario, $senha_pura, $vendor]);

    $_SESSION['msg_feedback'] = 'Dispositivo adicionado com sucesso. O Hostname será atualizado no próximo backup.';
    header("Location: painel.php");
    exit;
}

// --- Atualizar dispositivo ---
if (isset($_POST['editar'])) {
    $id = $_POST['id'];
    $ip = $_POST['ip'];
    $porta = $_POST['porta_ssh'];
    $usuario = $_POST['usuario'];
    $senha_pura = $_POST['senha'];
    $vendor = $_POST['vendor'];

    // Prepara a query para atualizar sem a senha. Hostname não é editável aqui.
    $sql = "UPDATE dispositivos SET ip=?, porta_ssh=?, usuario=?, vendor=? WHERE id=?";
    $params = [$ip, $porta, $usuario, $vendor, $id];

    if (!empty($senha_pura)) {
        $sql = "UPDATE dispositivos SET ip=?, porta_ssh=?, usuario=?, senha=?, vendor=? WHERE id=?";
        $params = [$ip, $porta, $usuario, $senha_pura, $vendor, $id];
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $_SESSION['msg_feedback'] = 'Dispositivo atualizado com sucesso.';
    header("Location: painel.php");
    exit;
}

// --- Excluir dispositivo ---
if (isset($_GET['excluir'])) {
    $id = intval($_GET['excluir']);
    $stmt = $db->prepare("DELETE FROM dispositivos WHERE id = ?");
    $stmt->execute([$id]);

    $_SESSION['msg_feedback'] = 'Dispositivo excluído com sucesso.';
    header("Location: painel.php");
    exit;
}

// --- Executar Backup (via API Python) ---
if (isset($_POST['executar_backup'])) {
    // URL do endpoint da sua API Python
    $url_api = 'http://localhost:5000/executar-tarefa';

    $ch = curl_init($url_api);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $msg_retorno = 'Erro cURL: ' . curl_error($ch);
    } else {
        $dados_api = json_decode($response, true);
        if ($http_code == 200 && isset($dados_api['status']) && $dados_api['status'] === 'sucesso') {
            $msg_retorno = "Comando de backup enviado com sucesso para a API! Mensagem: " . $dados_api['mensagem'];
        } else {
            $msg_retorno = "Erro ao executar o backup via API. HTTP Code: {$http_code}. Mensagem: " . ($dados_api['mensagem'] ?? 'Resposta inesperada.');
        }
    }
    curl_close($ch);
    $_SESSION['msg_feedback'] = $msg_retorno;
    header("Location: painel.php");
    exit;
}

// --- Exibir mensagens de retorno, se existirem ---
$msg_feedback = '';
if (isset($_SESSION['msg_feedback'])) {
    $msg_feedback = $_SESSION['msg_feedback'];
    unset($_SESSION['msg_feedback']);
}

// --- Buscar dispositivos (incluindo o hostname) ---
$dispositivos = $db->query("SELECT * FROM dispositivos ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Painel de Dispositivos</title>
    <link rel="stylesheet" href="static/Pstyle.css">
</head>
<body>

<div class="container">
    <h2>Bem-vindo, <?php echo htmlspecialchars($_SESSION['usuario']); ?>!</h2>
    
    <div class="top-buttons">
        <a href="cadastro_massa.php" class="btn">Cadastro em massa</a>
        <a href="config_user.php" class="btn">Configuração</a>
        <a href="gerenciar_backups.php" class="btn btn-blue">📂 Gerenciar Backups</a>
        <a href="logout.php" class="btn btn-danger">Sair</a>
    </div>

    <?php if (!empty($msg_feedback)): ?>
        <div class="alerta-backup"><?php echo htmlspecialchars($msg_feedback); ?></div>
    <?php endif; ?>

    <div class="backup-section">
        <form method="POST">
            <button type="submit" name="executar_backup" class="btn btn-purple">⚡ Executar Backup</button>
        </form>
    </div>
    
    <div class="agendar-section">
        <a href="agendador.php" class="btn">Agendar Backup</a>
    </div>
    
    <h3>Dispositivos Cadastrados</h3>
    <button class="btn btn-green" onclick="document.getElementById('modal').style.display='flex'">
        + Cadastrar Novo Dispositivo
    </button>

    <table>
        <tr>
            <th>Status</th>
            <th>Hostname</th>
            <th>IP</th>
            <th>Porta SSH</th>
            <th>Usuário</th>
            <th>Senha</th>
            <th>Vendor</th>
            <th>Ações</th>
        </tr>
        <?php foreach ($dispositivos as $d): ?>
        <form method="POST">
            <tr>
                <td style="text-align:center;">
                    <span class="status-bolinha <?= ($d['status_backup']==1) ? 'verde' : 'vermelha' ?>"></span>
                </td>
                <td><?= htmlspecialchars($d['hostname'] ?? '') ?></td>
                <td><input type="text" name="ip" value="<?= htmlspecialchars($d['ip']) ?>" required></td>
                <td><input type="text" name="porta_ssh" value="<?= htmlspecialchars($d['porta_ssh']) ?>" required></td>
                <td><input type="text" name="usuario" value="<?= htmlspecialchars($d['usuario']) ?>" required></td>
                <td><input type="password" name="senha" placeholder="Deixe em branco para não alterar"></td>
                <td>
                    <select name="vendor">
                        <option <?= ($d['vendor']=="Mikrotik")?"selected":"" ?>>Mikrotik</option>
                        <option <?= ($d['vendor']=="Ubiquiti")?"selected":"" ?>>Ubiquiti</option>
                        <option <?= ($d['vendor']=="Huawei")?"selected":"" ?>>Huawei</option>
                        <option <?= ($d['vendor']=="VSOL")?"selected":"" ?>>VSOL</option>
                    </select>
                </td>
                <td>
                    <input type="hidden" name="id" value="<?= htmlspecialchars($d['id']) ?>">
                    <button type="submit" name="editar" class="btn">Salvar</button>
                    <a href="?excluir=<?= htmlspecialchars($d['id']) ?>" class="btn btn-danger" onclick="return confirm('Deseja excluir este dispositivo?')">Excluir</a>
                </td>
            </tr>
        </form>
        <?php endforeach; ?>
    </table>
</div>

<div id="modal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="document.getElementById('modal').style.display='none'">&times;</span>
        <h3>Novo Dispositivo</h3>
        <form method="POST">
            <input type="text" name="ip" placeholder="IP" required>
            <input type="text" name="porta_ssh" placeholder="Porta SSH" value="22" required>
            <input type="text" name="usuario" placeholder="Usuário" required>
            <input type="password" name="senha" placeholder="Senha" required>
            <select name="vendor" required>
                <option value="">Selecione o Vendor</option>
                <option>Mikrotik</option>
                <option>Ubiquiti</option>
                <option>Huawei</option>
                <option>VSOL</option>
            </select>
            <button type="submit" name="adicionar" class="btn btn-green" style="margin-top:10px;width:100%;">Cadastrar</button>
        </form>
    </div>
</div>

<script>
    window.onclick = function(event) {
        const modal = document.getElementById('modal');
        if (event.target === modal) modal.style.display = "none";
    }
</script>

</body>
</html>