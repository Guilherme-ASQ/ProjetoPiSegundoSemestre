<?php
session_start();

if (isset($_GET["sair"])) {
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit;
}

if (!isset($_SESSION["usuario"], $_SESSION["email"]) || !isset($_SESSION["tipo_usuario"]) || $_SESSION["tipo_usuario"] !== "CLIENTE") {
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

$resultadoUsuario = pg_query_params(
    $conexao,
    "SELECT id_usuarios, nome, email, senha, telefone FROM usuarios WHERE email = $1 AND tipo_usuario = 'CLIENTE'",
    array($_SESSION["email"])
);

if (!$resultadoUsuario || pg_num_rows($resultadoUsuario) !== 1) {
    die("Usuário não encontrado.");
}

$dadosUsuario = pg_fetch_assoc($resultadoUsuario);
$idUsuario = (int)$dadosUsuario["id_usuarios"];
$nome = $dadosUsuario["nome"];
$email = $dadosUsuario["email"];
$senha = $dadosUsuario["senha"];
$telefone = $dadosUsuario["telefone"];

function urlAssinadaSupabase($supabaseUrl, $supabaseKey, $nomeBucket, $caminho) {
    if (!$supabaseUrl || !$supabaseKey || !$caminho) {
        return null;
    }
    $partes = array_map("rawurlencode", explode("/", $caminho));
    $url = $supabaseUrl . "/storage/v1/object/sign/" . rawurlencode($nomeBucket) . "/" . implode("/", $partes);

    $curl = curl_init($url);
    if (!$curl) return null;

    curl_setopt_array($curl, array(
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array(
            "apikey: " . $supabaseKey,
            "Authorization: Bearer " . $supabaseKey,
            "Content-Type: application/json"
        ),
        CURLOPT_POSTFIELDS => json_encode(array("expiresIn" => 3600)),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30
    ));

    $resposta = curl_exec($curl);
    $http = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $erro = curl_error($curl);
    curl_close($curl);

    $dados = is_string($resposta) ? json_decode($resposta, true) : null;
    if ($http < 200 || $http >= 300 || !is_array($dados) || empty($dados["signedURL"])) {
        error_log("Falha ao gerar link Supabase. HTTP: " . $http . " CURL: " . $erro . " resposta: " . (is_string($resposta) ? $resposta : ""));
        return null;
    }

    $link = $dados["signedURL"];
    if (strpos($link, "http://") === 0 || strpos($link, "https://") === 0) {
        return $link;
    }
    if (strpos($link, "/storage/v1/") === 0) {
        return $supabaseUrl . $link;
    }
    return $supabaseUrl . "/storage/v1/" . ltrim($link, "/");
}

$erroPagina = "";
$erroSolicitacao = "";
$maxArquivos = 5;
$maxMbArquivo = 10;
$formatosAceitos = "JPG, PNG, WebP ou PDF";
$valoresForm = array("tipo_projeto" => "", "endereco" => "", "descricao" => "");

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    empty($_POST) && empty($_FILES) &&
    (int)($_SERVER["CONTENT_LENGTH"] ?? 0) > 0
) {
    $erroSolicitacao = "Os arquivos enviados ficaram grandes demais. Envie até " . $maxArquivos . " arquivos (" . $formatosAceitos . ") com no máximo " . $maxMbArquivo . " MB cada.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["salvar"])) {
    $novoNome = trim($_POST["nome"] ?? "");
    $novoEmail = trim($_POST["email"] ?? "");
    $novaSenha = $_POST["senha"] ?? "";
    $novoTelefone = trim($_POST["telefone"] ?? "");

    if ($novoNome === "" || $novoEmail === "") {
        $erroPagina = "Nome e e-mail são obrigatórios.";
    } else {
        if ($novoTelefone === "") {
            $novoTelefone = $telefone;
        }
        if ($novaSenha === "") {
            $r = pg_query_params(
                $conexao,
                "UPDATE usuarios SET nome = $1, email = $2, telefone = $3 WHERE id_usuarios = $4 AND tipo_usuario = 'CLIENTE'",
                array($novoNome, $novoEmail, $novoTelefone, $idUsuario)
            );
        } else {
            $r = pg_query_params(
                $conexao,
                "UPDATE usuarios SET nome = $1, email = $2, senha = $3, telefone = $4 WHERE id_usuarios = $5 AND tipo_usuario = 'CLIENTE'",
                array($novoNome, $novoEmail, password_hash($novaSenha, PASSWORD_DEFAULT), $novoTelefone, $idUsuario)
            );
        }
        if ($r) {
            $_SESSION["usuario"] = $novoNome;
            $_SESSION["email"] = $novoEmail;
            header("Location: area_cliente.php");
            exit;
        }
        $erroPagina = "Não foi possível atualizar. Verifique se o e-mail já está cadastrado.";
        error_log("Erro ao atualizar usuário cliente: " . pg_last_error($conexao));
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["solicitar_servico"])) {
    $tipoProjeto = trim($_POST["tipo_projeto"] ?? "");
    $endereco = trim($_POST["endereco"] ?? "");
    $descricao = trim($_POST["descricao"] ?? "");

    $valoresForm = array("tipo_projeto" => $tipoProjeto, "endereco" => $endereco, "descricao" => $descricao);

    if ($tipoProjeto === "") $erroSolicitacao = "Selecione o tipo de projeto.";
    elseif ($endereco === "") $erroSolicitacao = "Informe a cidade ou o endereço do projeto.";
    elseif ($descricao === "") $erroSolicitacao = "Descreva o serviço que deseja solicitar.";
    elseif (!$supabaseUrl || !$supabaseKey) $erroSolicitacao = "O envio de solicitações está indisponível no momento. Tente novamente mais tarde.";

    $arquivos = array();
    $permitidos = array(
        "image/jpeg" => "jpg",
        "image/png" => "png",
        "image/webp" => "webp",
        "application/pdf" => "pdf"
    );

    $total = isset($_FILES["anexos"]) ? count($_FILES["anexos"]["name"]) : 0;
    $selecionados = 0;
    for ($i = 0; $i < $total; $i++) {
        if ($_FILES["anexos"]["error"][$i] !== UPLOAD_ERR_NO_FILE) {
            $selecionados++;
        }
    }

    if ($erroSolicitacao === "" && $selecionados > $maxArquivos) {
        $erroSolicitacao = "Você selecionou " . $selecionados . " arquivos. O limite é de " . $maxArquivos . " arquivos por solicitação.";
    }

    for ($i = 0; $erroSolicitacao === "" && $i < $total; $i++) {
        $err = $_FILES["anexos"]["error"][$i];
        $nomeArquivo = basename($_FILES["anexos"]["name"][$i] ?? "");
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            $erroSolicitacao = "O arquivo \"" . $nomeArquivo . "\" passa do limite de " . $maxMbArquivo . " MB. Reduza o tamanho ou envie outro arquivo.";
            break;
        }
        if ($err !== UPLOAD_ERR_OK) {
            $erroSolicitacao = "Não conseguimos receber o arquivo \"" . $nomeArquivo . "\". Tente enviar novamente.";
            break;
        }

        $tmp = $_FILES["anexos"]["tmp_name"][$i];
        $size = (int)$_FILES["anexos"]["size"][$i];
        if ($size <= 0 || $size > $maxMbArquivo * 1024 * 1024) {
            $erroSolicitacao = "O arquivo \"" . $nomeArquivo . "\" passa do limite de " . $maxMbArquivo . " MB. Reduza o tamanho ou envie outro arquivo.";
            break;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $tmp) : false;
        if ($finfo) finfo_close($finfo);

        if (!isset($permitidos[$mime])) {
            $erroSolicitacao = "O arquivo \"" . $nomeArquivo . "\" não está em um formato aceito. Envie apenas " . $formatosAceitos . ".";
            break;
        }

        $arquivos[] = array(
            "tmp" => $tmp,
            "nome" => basename($_FILES["anexos"]["name"][$i]),
            "mime" => $mime,
            "ext" => $permitidos[$mime]
        );
    }

    if ($erroSolicitacao === "") {
        pg_query($conexao, "BEGIN");
        $rServico = pg_query_params(
            $conexao,
            "INSERT INTO servicos (tipo_projeto, endereco, descricao, status, id_usuario) VALUES ($1, $2, $3, 'SOLICITADO', $4) RETURNING id_servicos",
            array($tipoProjeto, $endereco, $descricao, $idUsuario)
        );

        if (!$rServico) {
            pg_query($conexao, "ROLLBACK");
            error_log("Erro ao criar serviço: " . pg_last_error($conexao));
            $erroSolicitacao = "Não foi possível registrar a solicitação. Tente novamente.";
        } else {
            $idServico = (int)pg_fetch_result($rServico, 0, "id_servicos");
            $enviados = array();
            $falhou = false;

            foreach ($arquivos as $arq) {
                $nomeArmazenado = bin2hex(random_bytes(16)) . "." . $arq["ext"];
                $caminho = $idUsuario . "/" . $idServico . "/" . $nomeArmazenado;
                $urlUpload = $supabaseUrl . "/storage/v1/object/" . rawurlencode($nomeBucket) . "/" . implode("/", array_map("rawurlencode", explode("/", $caminho)));
                $conteudo = file_get_contents($arq["tmp"]);

                if ($conteudo === false) {
                    $falhou = true;
                    break;
                }

                $curl = curl_init($urlUpload);
                curl_setopt_array($curl, array(
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => array(
                        "apikey: " . $supabaseKey,
                        "Authorization: Bearer " . $supabaseKey,
                        "Content-Type: " . $arq["mime"],
                        "x-upsert: false"
                    ),
                    CURLOPT_POSTFIELDS => $conteudo,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_TIMEOUT => 120
                ));

                $res = curl_exec($curl);
                $http = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                $curlErr = curl_error($curl);
                curl_close($curl);

                if ($res === false || $http < 200 || $http >= 300) {
                    error_log("Falha no upload Supabase. HTTP: " . $http . " CURL: " . $curlErr . " Resposta: " . (is_string($res) ? $res : ""));
                    $falhou = true;
                    break;
                }

                $enviados[] = $caminho;

                $rAnexo = pg_query_params(
                    $conexao,
                    "INSERT INTO anexos_servicos (id_servico, nome_arquivo, caminho_arquivo) VALUES ($1, $2, $3)",
                    array($idServico, $arq["nome"], $caminho)
                );

                if (!$rAnexo) {
                    error_log("Erro ao gravar anexo no Neon: " . pg_last_error($conexao));
                    $falhou = true;
                    break;
                }
            }

            if ($falhou) {
                foreach ($enviados as $caminho) {
                    $urlDel = $supabaseUrl . "/storage/v1/object/" . rawurlencode($nomeBucket) . "/" . implode("/", array_map("rawurlencode", explode("/", $caminho)));
                    $c = curl_init($urlDel);
                    curl_setopt_array($c, array(
                        CURLOPT_CUSTOMREQUEST => "DELETE",
                        CURLOPT_HTTPHEADER => array(
                            "apikey: " . $supabaseKey,
                            "Authorization: Bearer " . $supabaseKey
                        ),
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 20
                    ));
                    curl_exec($c);
                    curl_close($c);
                }
                pg_query_params($conexao, "DELETE FROM anexos_servicos WHERE id_servico = $1", array($idServico));
                pg_query_params($conexao, "DELETE FROM servicos WHERE id_servicos = $1 AND id_usuario = $2", array($idServico, $idUsuario));
                pg_query($conexao, "COMMIT");
                $erroSolicitacao = "Não foi possível salvar a solicitação ou enviar um dos arquivos. Tente novamente.";
            } else {
                pg_query($conexao, "COMMIT");
                header("Location: area_cliente.php?sucesso=1");
                exit;
            }
        }
    }
}

function garantirColunasOcultar($conexao) {
    $r = pg_query($conexao, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'servicos' AND column_name IN ('oculto_admin', 'oculto_cliente')");
    if ($r && (int)pg_fetch_result($r, 0, 0) === 2) return true;
    $ok = pg_query($conexao, "ALTER TABLE servicos ADD COLUMN IF NOT EXISTS oculto_admin BOOLEAN NOT NULL DEFAULT FALSE, ADD COLUMN IF NOT EXISTS oculto_cliente BOOLEAN NOT NULL DEFAULT FALSE");
    if (!$ok) error_log("Não foi possível criar as colunas de ocultar: " . pg_last_error($conexao));
    return (bool)$ok;
}
$ocultarDisponivel = garantirColunasOcultar($conexao);

if ($_SERVER["REQUEST_METHOD"] === "POST" && $ocultarDisponivel && (isset($_POST["remover_servico"]) || isset($_POST["limpar_finalizadas"]))) {
    if (isset($_POST["limpar_finalizadas"])) {
        $r = pg_query_params(
            $conexao,
            "UPDATE servicos SET oculto_cliente = TRUE WHERE id_usuario = $1 AND status IN ('CONCLUIDO', 'CANCELADO') AND oculto_cliente = FALSE",
            array($idUsuario)
        );
    } else {
        $idRemover = filter_var($_POST["id_servico"] ?? "", FILTER_VALIDATE_INT);
        $r = $idRemover ? pg_query_params(
            $conexao,
            "UPDATE servicos SET oculto_cliente = TRUE WHERE id_servicos = $1 AND id_usuario = $2 AND status IN ('CONCLUIDO', 'CANCELADO')",
            array($idRemover, $idUsuario)
        ) : false;
    }
    if (!$r) error_log("Erro ao remover solicitações do cliente: " . pg_last_error($conexao));
    header("Location: area_cliente.php" . ($r ? "?removido=" . pg_affected_rows($r) : ""));
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["cancelar_servico"])) {
    $idCancelar = filter_var($_POST["id_servico"] ?? "", FILTER_VALIDATE_INT);
    if ($idCancelar) {
        $r = pg_query_params(
            $conexao,
            "UPDATE servicos SET status = 'CANCELADO' WHERE id_servicos = $1 AND id_usuario = $2 AND status IN ('SOLICITADO', 'EM_ANALISE')",
            array($idCancelar, $idUsuario)
        );
        if (!$r) {
            error_log("Erro ao cancelar serviço: " . pg_last_error($conexao));
        }
    }
    header("Location: area_cliente.php");
    exit;
}

$rServicos = pg_query_params(
    $conexao,
    "SELECT id_servicos, tipo_projeto, endereco, descricao, status, resposta FROM servicos WHERE id_usuario = $1" . ($ocultarDisponivel ? " AND oculto_cliente = FALSE" : "") . " ORDER BY id_servicos DESC",
    array($idUsuario)
);

if (!$rServicos) {
    die("Erro ao buscar seus serviços.");
}
$servicos = pg_fetch_all($rServicos) ?: array();
$qtdFinalizadas = count(array_filter($servicos, function ($sv) {
    return $sv["status"] === "CONCLUIDO" || $sv["status"] === "CANCELADO";
}));

$anexosPorServico = array();
$rAnexos = pg_query_params(
    $conexao,
    "SELECT a.id_servico, a.nome_arquivo, a.caminho_arquivo FROM anexos_servicos a INNER JOIN servicos s ON s.id_servicos = a.id_servico WHERE s.id_usuario = $1" . ($ocultarDisponivel ? " AND s.oculto_cliente = FALSE" : "") . " ORDER BY a.id_anexo",
    array($idUsuario)
);

if ($rAnexos) {
    while ($a = pg_fetch_assoc($rAnexos)) {
        $url = urlAssinadaSupabase($supabaseUrl, $supabaseKey, $nomeBucket, $a["caminho_arquivo"]);
        $anexosPorServico[$a["id_servico"]][] = array(
            "nome" => $a["nome_arquivo"],
            "url" => $url
        );
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Área do cliente Um Pé de IDEIA</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,400&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=Space+Grotesk:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body data-page="cliente">
<header class="top" id="top">
  <div class="wrap bar">
    <a class="brand" href="index.php">
      <img src="https://res.cloudinary.com/p2srhjwx/image/upload/v1792142855/Logo_Um_P%C3%89_deIDEIA.jpg" alt="Logo Um Pé de IDEIA">
      <span><b>Um Pé de IDEIA</b><span class="lab">Área do cliente</span></span>
    </a>
    <nav class="nav" aria-label="Principal">
      <a class="btn sm out keep" href="index.php">← Voltar para a home</a>
      <a class="btn sm dk keep" href="area_cliente.php?sair=1">Sair</a>
    </nav>
  </div>
</header>
<main class="page">
  <div class="wrap">
    <div class="panel-top">
      <div class="user">
        <span class="avatar"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($nome, 0, 1, "UTF-8"), "UTF-8")); ?></span>
        <div><b>Olá, <?php echo htmlspecialchars($nome); ?></b><span class="lab"><?php echo htmlspecialchars($email); ?></span></div>
      </div>
      <a class="btn out sm" href="area_cliente.php?editar=1">Editar dados</a>
    </div>

    <?php if (isset($_GET["sucesso"])): ?>
      <div class="alert ok" role="status"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.7 2.7L16 9.8"/></svg><div><b>Solicitação enviada com sucesso!</b><p>O escritório vai analisar seu pedido e responder por aqui.</p></div></div>
    <?php endif; ?>
    <?php if ($erroPagina): ?>
      <div class="alert err" role="alert"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.5h.01"/></svg><div><b>Não foi possível concluir</b><p><?php echo htmlspecialchars($erroPagina); ?></p></div></div>
    <?php endif; ?>

    <?php if (isset($_GET["editar"])): ?>
      <section class="sheet pad-lg edit-box cliente-edit">
        <span class="lab ac">Minha conta</span>
        <h2>Editar informações</h2>
        <p class="hint">Deixe a senha em branco para manter a atual.</p>
        <form method="post">
          <div class="row-2">
            <label><span class="lab">Nome</span><input name="nome" value="<?php echo htmlspecialchars($nome, ENT_QUOTES, "UTF-8"); ?>" required></label>
            <label><span class="lab">E-mail</span><input type="email" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, "UTF-8"); ?>" required></label>
          </div>
          <div class="row-2">
            <label><span class="lab">Telefone</span><input name="telefone" value="<?php echo htmlspecialchars($telefone ?? "", ENT_QUOTES, "UTF-8"); ?>"></label>
            <label><span class="lab">Nova senha (opcional)</span><input type="password" name="senha" autocomplete="new-password"></label>
          </div>
          <div class="cliente-edit-actions">
            <button class="btn" name="salvar" value="1">Salvar alterações</button>
            <a class="btn out" href="area_cliente.php">Cancelar</a>
          </div>
        </form>
      </section>
    <?php endif; ?>

    <div class="dash">
      <div class="sticky">
        <section class="sheet pad-lg tape" id="nova-solicitacao">
          <span class="lab ac">Nova solicitação</span>
          <h3 class="cliente-form-tit">Conte a sua ideia</h3>
          <p class="hint">Preencha os campos abaixo. Se tiver fotos, plantas ou referências, anexe também.</p>
          <?php if ($erroSolicitacao): ?>
            <div class="alert err" role="alert"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.5h.01"/></svg><div><b>Não foi possível enviar a solicitação</b><p><?php echo htmlspecialchars($erroSolicitacao, ENT_QUOTES, "UTF-8"); ?></p></div></div>
          <?php endif; ?>
          <form method="post" enctype="multipart/form-data" class="cliente-form">
            <label>
              <span class="lab">Tipo de projeto</span>
              <select id="tipoProjeto" name="tipo_projeto" required>
                <option value="">Selecione uma opção</option>
                <?php foreach (array("Residência (obra nova)", "Reforma e interiores", "Espaço comercial", "Consultoria avulsa") as $opcao): ?>
                  <option value="<?php echo htmlspecialchars($opcao, ENT_QUOTES, "UTF-8"); ?>"<?php echo $valoresForm["tipo_projeto"] === $opcao ? " selected" : ""; ?>><?php echo htmlspecialchars($opcao, ENT_QUOTES, "UTF-8"); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>
              <span class="lab">Cidade ou endereço</span>
              <input id="localProjeto" name="endereco" placeholder="Cidade Estado ou endereço completo" required maxlength="255" value="<?php echo htmlspecialchars($valoresForm["endereco"], ENT_QUOTES, "UTF-8"); ?>">
            </label>
            <label>
              <span class="lab">Descreva o que você imagina</span>
              <textarea name="descricao" required maxlength="2000" placeholder="O que gostaria de construir, reformar ou melhorar?"><?php echo htmlspecialchars($valoresForm["descricao"], ENT_QUOTES, "UTF-8"); ?></textarea>
            </label>
            <div class="upl" data-max="<?php echo $maxArquivos; ?>" data-max-mb="<?php echo $maxMbArquivo; ?>">
              <div class="upl-head">
                <span class="lab">Anexos <span class="opc">(opcional)</span></span>
                <span class="upl-count" aria-live="polite">0 de <?php echo $maxArquivos; ?> arquivos</span>
              </div>
              <label class="upl-drop">
                <span class="upl-ico"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/></svg></span>
                <b>Arraste os arquivos aqui ou <u>clique para escolher</u></b>
                <small>Até <?php echo $maxArquivos; ?> arquivos · <?php echo $formatosAceitos; ?> · máximo de <?php echo $maxMbArquivo; ?> MB cada</small>
                <input type="file" name="anexos[]" accept="image/jpeg,image/png,image/webp,application/pdf" multiple aria-label="Escolher arquivos para anexar">
              </label>
              <div class="alert warn upl-alert" role="alert" hidden></div>
              <ul class="upl-list" hidden></ul>
            </div>
            <button class="btn wide lg cliente-enviar" name="solicitar_servico" value="1">Enviar solicitação</button>
          </form>
        </section>
      </div>

      <div>
        <div class="head-row cliente-lista-head">
          <div><span class="lab ac">Acompanhamento</span><h3 class="cliente-form-tit">Minhas solicitações</h3></div>
          <?php if ($ocultarDisponivel && $qtdFinalizadas > 0): ?>
            <form method="post" onsubmit="return confirm('Remover da sua lista todas as solicitações concluídas ou canceladas?')">
              <button class="btn out sm admin-btn-cancel" name="limpar_finalizadas" value="1">Limpar finalizadas (<?php echo $qtdFinalizadas; ?>)</button>
            </form>
          <?php endif; ?>
        </div>
        <?php if ((int)($_GET["removido"] ?? 0) > 0): ?>
          <?php $qtdRemovida = (int)$_GET["removido"]; ?>
          <div class="alert ok" role="status"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.7 2.7L16 9.8"/></svg><div><b><?php echo $qtdRemovida === 1 ? "1 solicitação removida da sua lista" : $qtdRemovida . " solicitações removidas da sua lista"; ?></b></div></div>
        <?php endif; ?>
        <?php if (count($servicos) > 0): ?>
          <?php foreach ($servicos as $s): ?>
            <?php
              $rotulosStatus = array("SOLICITADO" => "Solicitado", "EM_ANALISE" => "Em análise", "CONCLUIDO" => "Concluído", "CANCELADO" => "Cancelado");
              $anexosServico = $anexosPorServico[$s["id_servicos"]] ?? array();
            ?>
            <article class="req cliente-req">
              <div class="r1">
                <span class="lab">Solicitação #<?php echo (int)$s["id_servicos"]; ?></span>
                <span class="st <?php echo htmlspecialchars(strtolower($s["status"])); ?>"><?php echo htmlspecialchars($rotulosStatus[$s["status"]] ?? str_replace("_", " ", $s["status"])); ?></span>
              </div>
              <h4 class="cliente-req-tit"><?php echo htmlspecialchars($s["tipo_projeto"] ?? "Não informado", ENT_QUOTES, "UTF-8"); ?></h4>
              <p class="cliente-req-local"><?php echo htmlspecialchars($s["endereco"] ?? "Não informado", ENT_QUOTES, "UTF-8"); ?></p>
              <p class="desc"><?php echo htmlspecialchars($s["descricao"]); ?></p>

              <?php if (!empty($s["resposta"])): ?>
                <div class="answer">
                  <span class="lab ac">Resposta do escritório</span>
                  <p><?php echo htmlspecialchars($s["resposta"], ENT_QUOTES, "UTF-8"); ?></p>
                </div>
              <?php else: ?>
                <p class="hint cliente-aguardando">O escritório ainda não respondeu a esta solicitação.</p>
              <?php endif; ?>

              <div class="att">
                <span class="lab">Arquivos anexados<?php echo $anexosServico ? " · " . count($anexosServico) : ""; ?></span>
                <div class="attachment-links admin-attachments">
                  <?php if ($anexosServico): ?>
                    <?php foreach ($anexosServico as $a): ?>
                      <?php $ehImagem = in_array(strtolower(pathinfo($a["nome"], PATHINFO_EXTENSION)), array("jpg", "jpeg", "png", "webp"), true); ?>
                      <?php if ($a["url"] && $ehImagem): ?>
                        <a class="admin-attachment-image" href="<?php echo htmlspecialchars($a["url"], ENT_QUOTES, "UTF-8"); ?>" target="_blank" rel="noopener noreferrer">
                          <img src="<?php echo htmlspecialchars($a["url"], ENT_QUOTES, "UTF-8"); ?>" alt="<?php echo htmlspecialchars($a["nome"], ENT_QUOTES, "UTF-8"); ?>" loading="lazy">
                          <span><?php echo htmlspecialchars($a["nome"], ENT_QUOTES, "UTF-8"); ?></span>
                        </a>
                      <?php elseif ($a["url"]): ?>
                        <a class="admin-attachment-file" href="<?php echo htmlspecialchars($a["url"], ENT_QUOTES, "UTF-8"); ?>" target="_blank" rel="noopener noreferrer">
                          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/></svg>
                          <span><?php echo htmlspecialchars($a["nome"], ENT_QUOTES, "UTF-8"); ?></span>
                        </a>
                      <?php else: ?>
                        <span class="admin-attachment-file is-off"><?php echo htmlspecialchars($a["nome"]); ?> (link indisponível)</span>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <span class="admin-muted">Nenhum anexo.</span>
                  <?php endif; ?>
                </div>
              </div>
              <?php if ($s["status"] === "SOLICITADO" || $s["status"] === "EM_ANALISE"): ?>
                <form method="post" class="cliente-req-foot" onsubmit="return confirm('Deseja cancelar esta solicitação?')">
                  <input type="hidden" name="id_servico" value="<?php echo (int)$s["id_servicos"]; ?>">
                  <button class="btn out sm admin-btn-cancel" name="cancelar_servico" value="1">Cancelar solicitação</button>
                </form>
              <?php elseif ($ocultarDisponivel && ($s["status"] === "CONCLUIDO" || $s["status"] === "CANCELADO")): ?>
                <form method="post" class="cliente-req-foot" onsubmit="return confirm('Remover esta solicitação da sua lista?')">
                  <input type="hidden" name="id_servico" value="<?php echo (int)$s["id_servicos"]; ?>">
                  <button class="btn out sm admin-btn-cancel" name="remover_servico" value="1">Remover da lista</button>
                </form>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="empty">
            <span class="ico"><svg viewBox="0 0 24 24"><path d="M4 4h10l6 6v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><path d="M14 4v6h6M8 14h8M8 18h5"/></svg></span>
            <b>Você ainda não enviou solicitações</b>
            <p>Preencha o formulário ao lado para começar.</p>
          </div>
        <?php endif; ?>
      </div>
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
  var box = document.querySelector('.upl');
  if (!box) return;
  var form = box.closest('form');
  var input = box.querySelector('input[type=file]');
  var drop = box.querySelector('.upl-drop');
  var lista = box.querySelector('.upl-list');
  var alerta = box.querySelector('.upl-alert');
  var contador = box.querySelector('.upl-count');
  var botao = form.querySelector('.cliente-enviar');
  var max = parseInt(box.getAttribute('data-max'), 10) || 5;
  var maxMb = parseInt(box.getAttribute('data-max-mb'), 10) || 10;
  var tipos = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
  var extensoes = /\.(jpe?g|png|webp|pdf)$/i;
  var regra = 'Envie até ' + max + ' arquivos JPG, PNG, WebP ou PDF, com no máximo ' + maxMb + ' MB cada.';
  var arquivos = [];
  var urls = [];
  var enviando = false;
  var editavel = (function () { try { return !!new DataTransfer().items; } catch (e) { return false; } })();

  function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
  function tamanho(b) { return b >= 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
  function formatoOk(f) { return tipos.indexOf(f.type) !== -1 || (!f.type && extensoes.test(f.name)); }

  function avisar(msgs) {
    if (!msgs.length) { alerta.hidden = true; alerta.innerHTML = ''; return; }
    alerta.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4M12 17h.01"/></svg>' +
      '<div><b>' + (msgs.length > 1 ? 'Alguns arquivos não foram adicionados' : 'Arquivo não adicionado') + '</b><ul>' +
      msgs.map(function (m) { return '<li>' + esc(m) + '</li>'; }).join('') +
      '</ul><p>' + esc(regra) + '</p></div>';
    alerta.hidden = false;
  }

  function sincronizar() {
    if (!editavel) return;
    var dt = new DataTransfer();
    arquivos.forEach(function (f) { dt.items.add(f); });
    input.files = dt.files;
  }

  function desenhar() {
    urls.forEach(function (u) { URL.revokeObjectURL(u); });
    urls = [];
    lista.innerHTML = arquivos.map(function (f, i) {
      var imagem = /^image\//.test(f.type);
      var thumb;
      if (imagem) {
        var u = URL.createObjectURL(f); urls.push(u);
        thumb = '<img src="' + u + '" alt="">';
      } else {
        thumb = '<span class="upl-pdf">PDF</span>';
      }
      return '<li class="upl-item">' + '<span class="upl-thumb">' + thumb + '</span>' +
        '<span class="upl-info"><b>' + esc(f.name) + '</b><small>' + tamanho(f.size) + '</small></span>' +
        (editavel ? '<button type="button" class="upl-rm" data-i="' + i + '" aria-label="Remover ' + esc(f.name) + '">×</button>' : '') +
        '</li>';
    }).join('');
    lista.hidden = !arquivos.length;
    contador.textContent = arquivos.length + ' de ' + max + ' arquivo' + (max === 1 ? '' : 's');
    contador.classList.toggle('is-full', arquivos.length >= max);
    box.classList.toggle('has-files', arquivos.length > 0);
  }

  input.addEventListener('change', function () {
    var novos = Array.prototype.slice.call(input.files || []);
    var msgs = [];
    var sobrou = 0;
    if (!editavel) arquivos = [];
    novos.forEach(function (f) {
      var repetido = arquivos.some(function (a) { return a.name === f.name && a.size === f.size; });
      if (!formatoOk(f)) msgs.push('"' + f.name + '" não está em um formato aceito.');
      else if (f.size > maxMb * 1048576) msgs.push('"' + f.name + '" tem ' + tamanho(f.size) + ' e passa do limite de ' + maxMb + ' MB.');
      else if (repetido) return;
      else if (arquivos.length >= max) sobrou++;
      else arquivos.push(f);
    });
    if (sobrou) msgs.push('O limite é de ' + max + ' arquivos por solicitação. ' + (sobrou === 1 ? '1 arquivo ficou' : sobrou + ' arquivos ficaram') + ' de fora.');
    if (!editavel && msgs.length) { arquivos = []; input.value = ''; }
    sincronizar();
    desenhar();
    avisar(msgs);
  });

  lista.addEventListener('click', function (e) {
    var b = e.target.closest('.upl-rm');
    if (!b) return;
    arquivos.splice(parseInt(b.getAttribute('data-i'), 10), 1);
    sincronizar();
    desenhar();
    avisar([]);
  });

  ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function () { drop.classList.add('is-over'); }); });
  ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function () { drop.classList.remove('is-over'); }); });

  form.addEventListener('submit', function (e) {
    var atuais = Array.prototype.slice.call(input.files || []);
    var msgs = [];
    if (atuais.length > max) msgs.push('Você selecionou ' + atuais.length + ' arquivos. O limite é de ' + max + '.');
    atuais.forEach(function (f) {
      if (!formatoOk(f)) msgs.push('"' + f.name + '" não está em um formato aceito.');
      else if (f.size > maxMb * 1048576) msgs.push('"' + f.name + '" passa do limite de ' + maxMb + ' MB.');
    });
    if (msgs.length) {
      e.preventDefault();
      avisar(msgs);
      alerta.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    if (enviando) { e.preventDefault(); return; }
    enviando = true;
    botao.classList.add('is-loading');
    botao.textContent = atuais.length ? 'Enviando arquivos…' : 'Enviando…';
  });
})();
</script>
<script src="script.js"></script>
</body>
</html>
