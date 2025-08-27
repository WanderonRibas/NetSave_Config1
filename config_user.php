<?php
session_start();

// Redireciona se o usuário não estiver logado
if (!isset($_SESSION['usuario'])) {
    header('Location: index.php'); // Certifique-se de que index.php é a sua página de login
    exit;
}

// Detalhes da conexão com o MySQL
// ATENÇÃO: Substitua 'localhost', 'net_backup', 'root' e 'Master@308882#'
// com as suas informações de banco de dados reais.
// Configura��o MySQL
$host = 'localhost';
$dbname = 'net_backup';
$username = 'net_backup_user';
$password = 'net_backup_user';
try {

    // Conexão PDO para MySQL
    $db = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    
    // Define o modo de erro do PDO para lançar exceções em caso de problemas
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Define o modo de busca padrão para associativo (nomes das colunas como chaves)
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Em caso de erro na conexão, exibe uma mensagem e encerra o script
    die("Erro de conexão com o banco de dados: " . $e->getMessage());
}

$usuarioLogado = $_SESSION['usuario'];
$msg = '';
$erro = '';

// --- Alterar senha do usuário logado ---
if (isset($_POST['alterar_senha'])) {
    $nova = $_POST['nova'];
    $confirma = $_POST['confirma'];

    // Validação: As senhas devem conferir e ter um comprimento mínimo
    if ($nova === $confirma && strlen($nova) >= 6) { 
        // HASHEAR A SENHA AQUI ANTES DE ATUALIZAR!
        $senha_hash = password_hash($nova, PASSWORD_DEFAULT);
        
        // Atualiza apenas a senha
        $stmt = $db->prepare("UPDATE usuarios SET senha = ? WHERE login = ?");
        $stmt->execute([$senha_hash, $usuarioLogado]);
        
        $msg = "Senha alterada com sucesso!";
    } else {
        $erro = "As senhas não conferem, estão vazias ou são muito curtas (mínimo 6 caracteres)!";
    }
}

// --- Adicionar novo usuário ---
if (isset($_POST['adicionar_usuario'])) {
    $novo_login = trim($_POST['novo_login']);
    $nova_senha_pura = $_POST['nova_senha']; // A senha em texto puro vinda do formulário

    // Validação: O login não pode ser vazio e a senha deve ter um comprimento mínimo
    if (!empty($novo_login) && strlen($nova_senha_pura) >= 6) {
        // HASHEAR A SENHA ANTES DE INSERIR!
        $senha_hash = password_hash($nova_senha_pura, PASSWORD_DEFAULT);

        try {
            // Insere o novo usuário com a senha hasheada.
            $stmt = $db->prepare("INSERT INTO usuarios (login, senha) VALUES (?, ?)");
            $stmt->execute([$novo_login, $senha_hash]); 
            
            $msg = "Usuário '" . htmlspecialchars($novo_login) . "' adicionado com sucesso!";
        } catch (PDOException $e) {
            // Verifica se o erro é devido a uma entrada duplicada (login UNIQUE)
            if ($e->getCode() == '23000') { 
                $erro = "Erro: já existe um usuário com esse login!";
            } else {
                $erro = "Erro ao adicionar usuário: " . $e->getMessage();
            }
        }
    } else {
        $erro = "Preencha todos os campos e garanta que a senha tenha no mínimo 6 caracteres.";
    }
}

// --- Excluir usuário ---
if (isset($_GET['excluir'])) {
    $usuario_excluir = $_GET['excluir'];

    // Impedir excluir o próprio usuário logado
    // E, por segurança, impedir a exclusão do usuário 'admin' principal (se existir)
    if ($usuario_excluir !== $usuarioLogado && $usuario_excluir !== 'admin') {
        $stmt = $db->prepare("DELETE FROM usuarios WHERE login = ?");
        $stmt->execute([$usuario_excluir]);
        $msg = "Usuário '" . htmlspecialchars($usuario_excluir) . "' excluído com sucesso!";
    } elseif ($usuario_excluir === $usuarioLogado) {
        $erro = "Você não pode excluir seu próprio usuário!";
    } elseif ($usuario_excluir === 'admin') {
        $erro = "O usuário 'admin' não pode ser excluído diretamente por aqui.";
    }
}

// --- Buscar lista de usuários ---
// Seleciona apenas o login, pois 'senha_pendente' não existe
$usuarios = $db->query("SELECT login FROM usuarios ORDER BY login ASC")->fetchAll();
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Gerenciar Usuários</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Estilos CSS (mantidos do seu código original) */
        .container { max-width: 600px; margin: auto; padding: 20px; }
        .btn { padding: 8px 15px; background: #007bff; color: white; border: none; cursor: pointer; margin-top: 10px; text-decoration: none; display: inline-block; }
        .btn:hover { opacity: 0.8; }
        .btn-danger { background: red; }
        input[type=password], input[type=text] { width: 100%; padding: 6px; margin: 5px 0; }
        .alert { padding: 10px; margin: 10px 0; border-radius: 5px; }
        .sucesso { background: #c8f7c5; }
        .erro { background: #f7c5c5; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: center; }
        th { background: #f0f0f0; }
    </style>
</head>
<body>

<div class="container">
    <h2>Gerenciamento de Usuários</h2>
    <a href="painel.php" class="btn">← Voltar ao Painel</a>

    <?php if (!empty($msg)) echo "<div class='alert sucesso'>" . htmlspecialchars($msg) . "</div>"; ?>
    <?php if (!empty($erro)) echo "<div class='alert erro'>" . htmlspecialchars($erro) . "</div>"; ?>

    <form method="POST" style="margin-bottom:20px;">
        <h3>Alterar Senha do Usuário Logado (<?php echo htmlspecialchars($usuarioLogado); ?>)</h3>
        <input type="password" name="nova" placeholder="Nova Senha" required minlength="6">
        <input type="password" name="confirma" placeholder="Confirmar Senha" required minlength="6">
        <button type="submit" name="alterar_senha" class="btn">Alterar Senha</button>
    </form>

    <form method="POST" style="margin-bottom:20px;">
        <h3>Adicionar Novo Usuário</h3>
        <input type="text" name="novo_login" placeholder="Login do novo usuário" required>
        <input type="password" name="nova_senha" placeholder="Senha do novo usuário" required minlength="6">
        <button type="submit" name="adicionar_usuario" class="btn">Adicionar Usuário</button>
    </form>

    <h3>Usuários Cadastrados</h3>
    <table>
        <tr>
            <th>Login</th>
            <th>Ações</th>
        </tr>
        <?php foreach ($usuarios as $u): ?>
        <tr>
            <td><?= htmlspecialchars($u['login']) ?></td>
            <td>
                <?php 
                // Proteção para não excluir o próprio usuário logado nem o usuário 'admin'
                if ($u['login'] !== $usuarioLogado && $u['login'] !== 'admin'): 
                ?>
                    <a href="?excluir=<?= urlencode($u['login']) ?>" class="btn btn-danger" onclick="return confirm('Deseja excluir o usuário \'<?= htmlspecialchars($u['login']) ?>\'?')">Excluir</a>
                <?php elseif ($u['login'] === 'admin'): ?>
                    (Administrador)
                <?php else: ?>
                    (Você)
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

</body>
</html>