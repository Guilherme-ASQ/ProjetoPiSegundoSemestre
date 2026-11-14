<?php
session_start();

if (isset($_GET["sair"])) {
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit;
}

if (
    !isset($_SESSION["usuario"], $_SESSION["email"]) ||
    !isset($_SESSION["tipo_usuario"]) ||
    $_SESSION["tipo_usuario"] !== "ADMINISTRADOR"
) {
    header("Location: index.php");
    exit;
}

$databaseUrl = getenv("DATABASE_URL");
$conexao = $databaseUrl ? pg_connect($databaseUrl) : false;

if (!$conexao) {
    die("Erro ao conectar ao banco de dados.");
}

$supabaseUrl = rtrim(getenv("SUPABASE_URL") ?: "", "/");
$supabaseKey = getenv("SUPABASE_SERVICE_ROLE_KEY") ?: "";
$nomeBucket = "anexo-servicos";

function adminUrlAssinada($base, $key, $bucket, $path) {
    if (!$base || !$key || !$path) return null;
    $path = implode("/", array_map("rawurlencode", explode("/", $path)));
    $url = $base . "/storage/v1/object/sign/" . rawurlencode($bucket) . "/" . $path;

    $c = curl_init($url);
    if (!$c) return null;

    curl_setopt_array($c, array(
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array(
            "apikey: " . $key,
            "Authorization: Bearer " . $key,
            "Content-Type: application/json"
        ),
        CURLOPT_POSTFIELDS => json_encode(array("expiresIn" => 3600)),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30
    ));

    $res = curl_exec($c);
    $http = curl_getinfo($c, CURLINFO_HTTP_CODE);
    $err = curl_error($c);
    curl_close($c);

    $data = is_string($res) ? json_decode($res, true) : null;
    if ($http < 200 || $http >= 300 || !is_array($data) || empty($data["signedURL"])) {
        error_log("Falha ao assinar URL do anexo: HTTP " . $http . " CURL " . $err . " resposta " . (is_string($res) ? $res : ""));
        return null;
    }

    $link = $data["signedURL"];
    if (strpos($link, "http://") === 0 || strpos($link, "https://") === 0) return $link;
    if (strpos($link, "/storage/v1/") === 0) return $base . $link;
    return $base . "/storage/v1/" . ltrim($link, "/");
}

function adminLinkWhatsApp($telefone, $nome) {
    if (!$telefone) return null;
    $numero = preg_replace('/\D/', '', $telefone);
    if (strlen($numero) === 10 || strlen($numero) === 11) {
        $numero = "55" . $numero;
    }
    if (strlen($numero) < 12 || strlen($numero) > 13) return null;

    $mensagem = "Olá, " . $nome . "! Aqui é da equipe Um Pé de IDEIA. Estou entrando em contato sobre sua solicitação de serviço.";
    return "https://wa.me/" . $numero . "?text=" . rawurlencode($mensagem);
}

$usuarioLogado = $_SESSION["email"];
$rUser = pg_query_params(
    $conexao,
    "SELECT id_usuarios, nome, email, senha, telefone FROM usuarios WHERE email = $1 AND tipo_usuario = 'ADMINISTRADOR'",
    array($usuarioLogado)
);

if (!$rUser || pg_num_rows($rUser) !== 1) {
    die("Administrador não encontrado.");
}

$u = pg_fetch_assoc($rUser);
$idUsuario = (int) $u["id_usuarios"];
$nome = $u["nome"];
$email = $u["email"];
$senha = $u["senha"];
$telefone = $u["telefone"];
$erro = "";

function garantirColunasOcultar($conexao) {
    $r = pg_query($conexao, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'servicos' AND column_name IN ('oculto_admin', 'oculto_cliente')");
    if ($r && (int) pg_fetch_result($r, 0, 0) === 2) return true;
    $ok = pg_query($conexao, "ALTER TABLE servicos ADD COLUMN IF NOT EXISTS oculto_admin BOOLEAN NOT NULL DEFAULT FALSE, ADD COLUMN IF NOT EXISTS oculto_cliente BOOLEAN NOT NULL DEFAULT FALSE");
    if (!$ok) error_log("Não foi possível criar as colunas de ocultar: " . pg_last_error($conexao));
    return (bool) $ok;
}
$ocultarDisponivel = garantirColunasOcultar($conexao);

if ($_SERVER["REQUEST_METHOD"] === "POST" && $ocultarDisponivel && (isset($_POST["remover_concluido"]) || isset($_POST["limpar_concluidos"]))) {
    if (isset($_POST["limpar_concluidos"])) {
        $r = pg_query(
            $conexao,
            "UPDATE servicos SET oculto_admin = TRUE WHERE status = 'CONCLUIDO' AND oculto_admin = FALSE AND id_usuario IN (SELECT id_usuarios FROM usuarios WHERE tipo_usuario = 'CLIENTE')"
        );
    } else {
        $id = filter_var($_POST["id_servico"] ?? "", FILTER_VALIDATE_INT);
        $r = $id ? pg_query_params(
            $conexao,
            "UPDATE servicos SET oculto_admin = TRUE WHERE id_servicos = $1 AND status = 'CONCLUIDO'",
            array($id)
        ) : false;
    }
    if (!$r) error_log("Erro ao remover concluídos do painel: " . pg_last_error($conexao));
    header("Location: area_administrador.php" . ($r ? "?removido=" . pg_affected_rows($r) : ""));
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["enviar_resposta"])) {
    $idServico = filter_var($_POST["id_servico"] ?? "", FILTER_VALIDATE_INT);
    $resposta = trim($_POST["resposta"] ?? "");
    if (!$idServico || $resposta === "") die("Informe uma resposta válida.");

    $rResposta = pg_query_params(
        $conexao,
        "UPDATE servicos SET resposta = $1 WHERE id_servicos = $2",
        array($resposta, $idServico)
    );
    if (!$rResposta) {
        error_log("Erro ao salvar resposta: " . pg_last_error($conexao));
        die("Não foi possível salvar a resposta.");
    }
    header("Location: area_administrador.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["alterar_status"])) {
    $id = filter_var($_POST["id_servico"] ?? "", FILTER_VALIDATE_INT);
    $novo = $_POST["novo_status"] ?? "";
    $permitidos = array("EM_ANALISE" => "SOLICITADO", "CONCLUIDO" => "EM_ANALISE", "CANCELADO" => "EM_ANALISE");

    if (!$id || !isset($permitidos[$novo])) die("Alteração de status inválida.");

    $r = pg_query_params(
        $conexao,
        "UPDATE servicos SET status = $1 WHERE id_servicos = $2 AND status = $3",
        array($novo, $id, $permitidos[$novo])
    );
    if (!$r) error_log("Erro ao alterar status: " . pg_last_error($conexao));

    header("Location: area_administrador.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["salvar"])) {
    $novoNome = trim($_POST["nome"] ?? "");
    $novoEmail = trim($_POST["email"] ?? "");
    $novaSenha = $_POST["senha"] ?? "";
    $novoTelefone = trim($_POST["telefone"] ?? "");

    if ($novoTelefone === "") $novoTelefone = $telefone;

    if ($novoNome === "" || $novoEmail === "") {
        $erro = "Nome e e-mail são obrigatórios.";
    } else {
        if ($novaSenha === "") {
            $r = pg_query_params(
                $conexao,
                "UPDATE usuarios SET nome = $1, email = $2, telefone = $3 WHERE id_usuarios = $4 AND tipo_usuario = 'ADMINISTRADOR'",
                array($novoNome, $novoEmail, $novoTelefone, $idUsuario)
            );
        } else {
            $r = pg_query_params(
                $conexao,
                "UPDATE usuarios SET nome = $1, email = $2, senha = $3, telefone = $4 WHERE id_usuarios = $5 AND tipo_usuario = 'ADMINISTRADOR'",
                array($novoNome, $novoEmail, password_hash($novaSenha, PASSWORD_DEFAULT), $novoTelefone, $idUsuario)
            );
        }

        if ($r) {
            $_SESSION["usuario"] = $novoNome;
            $_SESSION["email"] = $novoEmail;
            header("Location: area_administrador.php");
            exit;
        }
        $erro = "Não foi possível atualizar os dados.";
        error_log("Erro ao atualizar admin: " . pg_last_error($conexao));
    }
}

function buscarServicos($conexao, $status, $esconderOcultos = false) {
    return pg_query_params(
        $conexao,
        "SELECT s.id_servicos AS id_servico, u.nome, u.email, u.telefone, s.tipo_projeto, s.endereco, s.descricao AS solicitacao, s.resposta, s.status FROM servicos s INNER JOIN usuarios u ON u.id_usuarios = s.id_usuario WHERE u.tipo_usuario = 'CLIENTE' AND s.status = $1" . ($esconderOcultos ? " AND s.oculto_admin = FALSE" : "") . " ORDER BY s.id_servicos ASC",
        array($status)
    );
}

function buscarAnexosAdmin($conexao, $idServico, $base, $key, $bucket) {
    $lista = array();
    $r = pg_query_params(
        $conexao,
        "SELECT nome_arquivo, caminho_arquivo FROM anexos_servicos WHERE id_servico = $1 ORDER BY id_anexo",
        array($idServico)
    );
    if (!$r) {
        error_log("Erro ao consultar anexos: " . pg_last_error($conexao));
        return $lista;
    }
    while ($a = pg_fetch_assoc($r)) {
        $extensao = strtolower(pathinfo($a["caminho_arquivo"], PATHINFO_EXTENSION));
        $lista[] = array(
            "nome" => $a["nome_arquivo"],
            "url" => adminUrlAssinada($base, $key, $bucket, $a["caminho_arquivo"]),
            "imagem" => in_array($extensao, array("jpg", "jpeg", "png", "webp"), true)
        );
    }
    return $lista;
}

$rSolicitados = buscarServicos($conexao, "SOLICITADO", $ocultarDisponivel);
$rAnalise = buscarServicos($conexao, "EM_ANALISE", $ocultarDisponivel);
$rConcluidos = buscarServicos($conexao, "CONCLUIDO", $ocultarDisponivel);

if (!$rSolicitados || !$rAnalise || !$rConcluidos) {
    die("Erro ao carregar os serviços.");
}

$etapasAdmin = array(
    array("titulo" => "Novas solicitações", "status" => "Solicitado", "id" => "admin-status-solicitado", "classe" => "stage-new", "quantidade" => pg_num_rows($rSolicitados)),
    array("titulo" => "Em análise", "status" => "Em análise", "id" => "admin-status-em_analise", "classe" => "stage-review", "quantidade" => pg_num_rows($rAnalise)),
    array("titulo" => "Concluídas", "status" => "Concluído", "id" => "admin-status-concluido", "classe" => "stage-done", "quantidade" => pg_num_rows($rConcluidos))
);
$totalEtapasAdmin = array_sum(array_column($etapasAdmin, "quantidade"));
foreach ($etapasAdmin as &$etapaAdmin) {
    $etapaAdmin["percentual"] = $totalEtapasAdmin > 0
        ? round(($etapaAdmin["quantidade"] / $totalEtapasAdmin) * 100, 1)
        : 0;
}
unset($etapaAdmin);

function adminTexto($valor) {
    return htmlspecialchars((string) $valor, ENT_QUOTES, "UTF-8");
}

function adminRotuloStatus($status) {
    $rotulos = array("SOLICITADO" => "Solicitado", "EM_ANALISE" => "Em análise", "CONCLUIDO" => "Concluído", "CANCELADO" => "Cancelado");
    return $rotulos[$status] ?? str_replace("_", " ", $status);
}

function mostraTabelaAdmin($resultado, $titulo, $tipo, $conexao, $base, $key, $bucket, $podeRemover = false) {
    $quantidade = pg_num_rows($resultado);
    $removivel = $podeRemover && $tipo === "CONCLUIDO";
    echo '<section class="admin-col" id="admin-status-' . strtolower($tipo) . '">';
    echo '<h2 class="admin-col-title">' . adminTexto($titulo) . ' <span>' . $quantidade . '</span></h2>';
    if ($removivel && $quantidade > 0) {
        echo '<div class="admin-col-tools">';
        echo '<p>Remova do painel o que já foi finalizado. O cliente continua vendo a solicitação no histórico dele.</p>';
        echo '<form method="post" onsubmit="return confirm(\'Remover do painel todas as ' . $quantidade . ' solicitações concluídas?\')">';
        echo '<button class="btn out sm admin-btn-cancel" name="limpar_concluidos" value="1" type="submit">';
        echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/></svg>';
        echo 'LIMPAR TODAS AS CONCLUÍDAS (' . $quantidade . ')</button>';
        echo '</form>';
        echo '</div>';
    }
    echo '<div class="admin-list">';
    if ($quantidade === 0) {
        echo '<div class="empty admin-empty">';
        echo '<span class="ico"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v16H4z"/><path d="m4 7 8 6 8-6"/></svg></span>';
        echo '<b>Nenhum serviço nesta etapa</b>';
        echo '<p>Quando houver solicitações com este status, elas aparecem aqui.</p>';
        echo '</div>';
    }
    while ($s = pg_fetch_assoc($resultado)) {
        $idServico = (int) $s["id_servico"];
        $telefone = preg_replace('/\D/', '', $s["telefone"] ?? "");
        if (strlen($telefone) === 10 || strlen($telefone) === 11) {
            $telefone = "55" . $telefone;
        }
        $temWhatsApp = (strlen($telefone) >= 12 && strlen($telefone) <= 13 && substr($telefone, 0, 2) === "55");
        $respondida = trim($s["resposta"] ?? "") !== "";
        $inicial = mb_strtoupper(mb_substr($s["nome"] ?? "?", 0, 1, "UTF-8"), "UTF-8");
        $anexos = buscarAnexosAdmin($conexao, $idServico, $base, $key, $bucket);

        echo '<article class="req admin-req">';

        echo '<div class="admin-req-head">';
        echo '<div class="admin-req-who">';
        echo '<span class="avatar admin-req-avatar" aria-hidden="true">' . adminTexto($inicial) . '</span>';
        echo '<div><span class="lab">Solicitação #' . $idServico . '</span><h3>' . adminTexto($s["nome"]) . '</h3></div>';
        echo '</div>';
        echo '<div class="admin-req-tags">';
        echo $respondida
            ? '<span class="admin-tag is-ok">Respondida</span>'
            : '<span class="admin-tag">Aguardando resposta</span>';
        echo '<span class="st ' . adminTexto(strtolower($s["status"])) . '">' . adminTexto(adminRotuloStatus($s["status"])) . '</span>';
        echo '</div>';
        echo '</div>';

        echo '<div class="admin-req-body">';

        echo '<div class="admin-req-info">';
        echo '<dl class="admin-req-meta">';
        echo '<div><dt>E-mail</dt><dd><a href="mailto:' . adminTexto($s["email"]) . '">' . adminTexto($s["email"]) . '</a></dd></div>';
        echo '<div><dt>Telefone</dt><dd>' . (!empty($s["telefone"]) ? adminTexto($s["telefone"]) : '<span class="admin-muted">Não informado</span>') . '</dd></div>';
        echo '<div><dt>Tipo de projeto</dt><dd>' . adminTexto($s["tipo_projeto"] ?? "Não informado") . '</dd></div>';
        echo '<div><dt>Endereço</dt><dd>' . adminTexto($s["endereco"] ?? "Não informado") . '</dd></div>';
        echo '</dl>';

        echo '<div class="admin-req-block">';
        echo '<span class="lab">Pedido do cliente</span>';
        echo '<p class="desc">' . adminTexto($s["solicitacao"]) . '</p>';
        echo '</div>';

        echo '<div class="admin-req-block">';
        echo '<span class="lab">Anexos do cliente' . ($anexos ? ' · ' . count($anexos) : '') . '</span>';
        echo '<div class="attachment-links admin-attachments">';
        if (!$anexos) {
            echo '<span class="admin-muted">Nenhum arquivo anexado.</span>';
        }
        foreach ($anexos as $a) {
            if ($a["url"] && $a["imagem"]) {
                echo '<a class="admin-attachment-image" target="_blank" rel="noopener noreferrer" href="' . adminTexto($a["url"]) . '">';
                echo '<img src="' . adminTexto($a["url"]) . '" alt="' . adminTexto($a["nome"]) . '" loading="lazy">';
                echo '<span>' . adminTexto($a["nome"]) . '</span>';
                echo '</a>';
            } elseif ($a["url"]) {
                echo '<a class="admin-attachment-file" target="_blank" rel="noopener noreferrer" href="' . adminTexto($a["url"]) . '">';
                echo '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/></svg>';
                echo '<span>' . adminTexto($a["nome"]) . '</span>';
                echo '</a>';
            } else {
                echo '<span class="admin-attachment-file is-off">' . adminTexto($a["nome"]) . ' (link indisponível)</span>';
            }
        }
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="admin-req-reply response-area">';
        echo '<span class="lab ac">Resposta ao cliente</span>';
        echo '<form method="post" class="response-form">';
        echo '<input type="hidden" name="id_servico" value="' . $idServico . '">';
        echo '<textarea name="resposta" rows="6" maxlength="5000" aria-label="Resposta ao cliente da solicitação #' . $idServico . '" placeholder="Escreva o retorno técnico, o prazo do estudo preliminar e os próximos passos..." required>' . adminTexto($s["resposta"] ?? "") . '</textarea>';
        echo '<div class="admin-reply-actions">';
        echo '<button class="btn sm response-send" type="submit" name="enviar_resposta" value="1">' . ($respondida ? 'ATUALIZAR RESPOSTA' : 'ENVIAR RESPOSTA') . '</button>';

        if ($temWhatsApp) {
            $linkWhatsApp = "https://wa.me/" . $telefone;
            echo '<a class="btn sm whatsapp" href="' . adminTexto($linkWhatsApp) . '" target="_blank" rel="noopener noreferrer">';
            echo '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/><path d="M9 9a5 5 0 0 0 6 6"/></svg>';
            echo 'WHATSAPP';
            echo '</a>';
        } else {
            echo '<button class="btn sm whatsapp whatsapp-disabled" type="button" disabled>';
            echo '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>';
            echo 'SEM WHATSAPP';
            echo '</button>';
        }
        echo '</div>';
        echo '</form>';
        echo '</div>';

        echo '</div>';

        if ($tipo === "SOLICITADO" || $tipo === "EM_ANALISE") {
            echo '<div class="admin-req-foot">';
            echo '<span class="admin-req-next">Próxima etapa</span>';
            echo '<div class="admin-req-actions">';
            if ($tipo === "SOLICITADO") {
                echo '<form method="post"><input type="hidden" name="id_servico" value="' . $idServico . '"><input type="hidden" name="novo_status" value="EM_ANALISE"><button class="btn sm dk" name="alterar_status" value="1" type="submit">MARCAR EM ANÁLISE →</button></form>';
            }
            if ($tipo === "EM_ANALISE") {
                echo '<form method="post" onsubmit="return confirm(\'Cancelar este serviço?\')"><input type="hidden" name="id_servico" value="' . $idServico . '"><input type="hidden" name="novo_status" value="CANCELADO"><button class="btn out sm admin-btn-cancel" name="alterar_status" value="1" type="submit">CANCELAR SERVIÇO</button></form>';
                echo '<form method="post"><input type="hidden" name="id_servico" value="' . $idServico . '"><input type="hidden" name="novo_status" value="CONCLUIDO"><button class="btn sm dk" name="alterar_status" value="1" type="submit">CONCLUIR SERVIÇO ✓</button></form>';
            }
            echo '</div>';
            echo '</div>';
        }

        if ($removivel) {
            echo '<div class="admin-req-foot">';
            echo '<span class="admin-req-next">Serviço finalizado</span>';
            echo '<div class="admin-req-actions">';
            echo '<form method="post" onsubmit="return confirm(\'Remover a solicitação #' . $idServico . ' do painel?\')"><input type="hidden" name="id_servico" value="' . $idServico . '"><button class="btn out sm admin-btn-cancel" name="remover_concluido" value="1" type="submit">REMOVER DO PAINEL</button></form>';
            echo '</div>';
            echo '</div>';
        }

        echo '</article>';
    }
    echo '</div>';
    echo '</section>';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Painel do arquiteto · Um Pé de IDEIA</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,400&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=Space+Grotesk:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body data-page="admin">
<header class="top">
<div class="wrap bar">
<a class="brand" href="index.php">
<img src="https://res.cloudinary.com/p2srhjwx/image/upload/v1792142855/Logo_Um_P%C3%89_deIDEIA.jpg" alt="">
<span><b>Um Pé de IDEIA</b><span class="lab">Painel do arquiteto</span></span>
</a>
<nav class="nav">
<a class="btn sm out keep" href="index.php">← Voltar para a home</a>
<a class="btn sm dk keep" href="area_administrador.php?sair=1">Sair</a>
</nav>
</div>
</header>
<main class="page">
<div class="wrap">
<div class="panel-top admin-top">
<div class="user">
<span class="avatar"><?php echo adminTexto(mb_strtoupper(mb_substr($nome, 0, 1, "UTF-8"), "UTF-8")); ?></span>
<div>
<span class="lab">Painel do arquiteto</span>
<b>Olá, <?php echo adminTexto($nome); ?></b>
<span class="admin-top-email"><?php echo adminTexto($email); ?></span>
</div>
</div>
<?php if (isset($_GET["editar"])): ?>
<a class="btn out sm" href="area_administrador.php">Fechar edição</a>
<?php else: ?>
<a class="btn out sm" href="area_administrador.php?editar=1">Editar meus dados</a>
<?php endif; ?>
</div>

<?php if ((int)($_GET["removido"] ?? 0) > 0): ?>
<?php $qtdRemovida = (int) $_GET["removido"]; ?>
<div class="alert ok" role="status"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.7 2.7L16 9.8"/></svg><div><b><?php echo $qtdRemovida === 1 ? "1 solicitação removida do painel" : $qtdRemovida . " solicitações removidas do painel"; ?></b><p>Elas continuam no histórico do cliente.</p></div></div>
<?php endif; ?>
<?php if ($erro): ?>
<p class="msg err"><?php echo adminTexto($erro); ?></p>
<?php endif; ?>

<?php if (isset($_GET["editar"])): ?>
<section class="sheet pad-lg edit-box admin-edit">
<div class="admin-edit-head">
<h2>Editar informações</h2>
<p class="hint">Deixe a senha em branco para manter a atual.</p>
</div>
<form method="post">
<div class="row-2">
<label>
<span class="lab">Nome</span>
<input type="text" name="nome" required value="<?php echo adminTexto($nome); ?>">
</label>
<label>
<span class="lab">E-mail</span>
<input type="email" name="email" required value="<?php echo adminTexto($email); ?>">
</label>
</div>
<div class="row-2">
<label>
<span class="lab">Telefone</span>
<input type="text" name="telefone" value="<?php echo adminTexto($telefone ?? ""); ?>">
</label>
<label>
<span class="lab">Nova senha (opcional)</span>
<input type="password" name="senha" autocomplete="new-password">
</label>
</div>
<div class="admin-edit-actions">
<button class="btn" name="salvar" value="1" type="submit">Salvar</button>
<a class="btn out" href="area_administrador.php">Cancelar</a>
</div>
</form>
</section>
<?php endif; ?>

<div class="admin-intro">
<div>
<span class="lab ac lab-line">Caixa de entrada</span>
<h1>Solicitações recebidas</h1>
<p>Escolha uma etapa para ver os pedidos, responder o cliente e avançar o serviço.</p>
</div>
<div class="admin-intro-total">
<b><?php echo $totalEtapasAdmin; ?></b>
<span>solicitações<br>no total</span>
</div>
</div>

<nav class="admin-stage-grid" aria-label="Etapas das solicitações">
<?php foreach ($etapasAdmin as $etapa): ?>
<a class="admin-stage <?php echo $etapa["classe"]; ?>" href="#<?php echo $etapa["id"]; ?>" data-stage="<?php echo $etapa["id"]; ?>">
<span class="admin-stage-heading">
<span class="admin-stage-name"><i aria-hidden="true"></i><?php echo adminTexto($etapa["titulo"]); ?></span>
<strong><?php echo $etapa["quantidade"]; ?></strong>
</span>
<span class="admin-stage-track" aria-hidden="true"><i style="width: <?php echo $etapa["percentual"]; ?>%"></i></span>
<span class="admin-stage-foot"><?php echo number_format($etapa["percentual"], 1, ",", "."); ?>% do total</span>
</a>
<?php endforeach; ?>
</nav>

<?php if (!$supabaseUrl || !$supabaseKey): ?>
<p class="msg err">Atenção: configure SUPABASE_URL e SUPABASE_SERVICE_ROLE_KEY no Render para exibir anexos.</p>
<?php endif; ?>

<div class="admin-board">
<?php
mostraTabelaAdmin($rSolicitados, "Novas solicitações", "SOLICITADO", $conexao, $supabaseUrl, $supabaseKey, $nomeBucket);
mostraTabelaAdmin($rAnalise, "Em análise", "EM_ANALISE", $conexao, $supabaseUrl, $supabaseKey, $nomeBucket);
mostraTabelaAdmin($rConcluidos, "Concluídas", "CONCLUIDO", $conexao, $supabaseUrl, $supabaseKey, $nomeBucket, $ocultarDisponivel);
?>
</div>
</div>
</main>
<footer>
<div class="wrap">
<div class="cols">
<div>
<a class="brand" href="index.php"><img src="https://res.cloudinary.com/p2srhjwx/image/upload/v1792142855/Logo_Um_P%C3%89_deIDEIA.jpg" alt=""><span><b>Um Pé de IDEIA</b><span class="lab">Arquitetura &amp; Design</span></span></a>
<p>Croquis à mão, projeto técnico e obra acompanhada de perto. Escritório em Santana, zona norte de São Paulo.</p>
<div class="socials">
<a href="https://www.instagram.com/arquiteto.aspi/" target="_blank" rel="noopener" aria-label="Instagram"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><path d="M17.5 6.5h.01"/></svg></a>
<a href="https://www.facebook.com/umpedeideia/" target="_blank" rel="noopener" aria-label="Facebook"><svg viewBox="0 0 24 24"><path d="M13.5 21v-8h2.7l.4-3h-3.1V8.1c0-.9.3-1.5 1.6-1.5h1.7V3.9c-.3 0-1.3-.1-2.5-.1-2.5 0-4.2 1.5-4.2 4.3V10H7.3v3h2.8v8z"/></svg></a>
<a href="https://wa.me/5511982916734?text=Ol%C3%A1!%20Vim%20pelo%20site%20e%20gostaria%20de%20conversar%20sobre%20um%20projeto." target="_blank" rel="noopener" aria-label="WhatsApp"><svg viewBox="0 0 24 24"><path d="M21 11.5a8.5 8.5 0 0 1-12.8 7.3L3 20.5l1.8-5A8.5 8.5 0 1 1 21 11.5z"/><path d="M8.6 9.2c.2 2.5 2.4 4.7 4.9 4.9"/></svg></a>
</div>
</div>
<div>
<h4>Navegar</h4>
<a href="index.php#escritorio">Escritório</a><a href="index.php#equipe">Equipe</a><a href="index.php#projetos">Galeria</a><a href="index.php#servicos">Serviços</a><a href="index.php#feedbacks">Feedbacks</a><a href="index.php#contato">Contato</a>
</div>
<div>
<h4>Contato</h4>
<a href="mailto:aspi@umpedeideia.com">aspi@umpedeideia.com</a>
<a href="tel:+5511982916734">(11) 98291-6734</a>
<a href="index.php#acesso">Login</a>
</div>
</div>
<div class="end">
<span>© <span id="ano">2026</span> Um Pé de IDEIA · Todos os direitos reservados</span>
<span>Três especialistas, um escritório · São Paulo SP</span>
</div>
</div>
</footer>
<script>
(function () {
  var board = document.querySelector('.admin-board');
  var nav = document.querySelector('.admin-stage-grid');
  if (!board || !nav) return;
  var tabs = Array.prototype.slice.call(nav.querySelectorAll('[data-stage]'));
  var cols = Array.prototype.slice.call(board.querySelectorAll('.admin-col'));
  var ids = tabs.map(function (t) { return t.getAttribute('data-stage'); });

  board.classList.add('is-tabs');
  nav.setAttribute('role', 'tablist');

  function mostrar(id) {
    tabs.forEach(function (t) {
      var ativo = t.getAttribute('data-stage') === id;
      t.classList.toggle('is-active', ativo);
      t.setAttribute('aria-selected', ativo ? 'true' : 'false');
    });
    cols.forEach(function (c) { c.hidden = c.id !== id; });
    try { sessionStorage.setItem('adminEtapa', id); } catch (e) {}
  }

  tabs.forEach(function (t) {
    t.setAttribute('role', 'tab');
    t.setAttribute('aria-controls', t.getAttribute('data-stage'));
    t.addEventListener('click', function (e) {
      e.preventDefault();
      mostrar(t.getAttribute('data-stage'));
    });
  });
  cols.forEach(function (c) { c.setAttribute('role', 'tabpanel'); });

  var inicial = location.hash.slice(1);
  if (ids.indexOf(inicial) === -1) {
    try { inicial = sessionStorage.getItem('adminEtapa'); } catch (e) { inicial = null; }
  }
  mostrar(ids.indexOf(inicial) !== -1 ? inicial : ids[0]);
})();
</script>
<script src="script.js"></script>
</body>
</html>
