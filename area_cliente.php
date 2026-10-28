<?php
session_start();

if (isset($_GET["sair"])) {
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit;
}
if (!isset($_SESSION["usuario"], $_SESSION["email"]) ||
    !isset($_SESSION["tipo_usuario"]) || $_SESSION["tipo_usuario"] !== "CLIENTE") {
    header("Location: index.php");
    exit;
}

$databaseUrl = getenv("DATABASE_URL");
$conexao = $databaseUrl ? pg_connect($databaseUrl) : false;
if (!$conexao) die("Erro ao conectar ao banco de dados.");

$supabaseUrl = rtrim(getenv("SUPABASE_URL") ?: "", "/");
$supabaseKey = getenv("SUPABASE_SERVICE_ROLE_KEY") ?: "";
$nomeBucket = "anexo-servicos";

$resultadoUsuario = pg_query_params(
    $conexao,
    "SELECT id_usuarios, nome, email, senha, telefone FROM usuarios WHERE email = $1 AND tipo_usuario = 'CLIENTE'",
    array($_SESSION["email"])
);
if (!$resultadoUsuario || pg_num_rows($resultadoUsuario) !== 1) die("Usuário não encontrado.");
$dadosUsuario = pg_fetch_assoc($resultadoUsuario);
$idUsuario = (int)$dadosUsuario["id_usuarios"];
$nome = $dadosUsuario["nome"];
$email = $dadosUsuario["email"];
$senha = $dadosUsuario["senha"];
$telefone = $dadosUsuario["telefone"];

function urlAssinadaSupabase($supabaseUrl, $supabaseKey, $nomeBucket, $caminho) {
    if (!$supabaseUrl || !$supabaseKey || !$caminho) return null;
    $partes = array_map("rawurlencode", explode("/", $caminho));
    $url = $supabaseUrl . "/storage/v1/object/sign/" . rawurlencode($nomeBucket) . "/" . implode("/", $partes);
    $curl = curl_init($url);
    if (!$curl) return null;
    curl_setopt_array($curl, array(
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array("apikey: ".$supabaseKey, "Authorization: Bearer ".$supabaseKey, "Content-Type: application/json"),
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
        error_log("Falha ao gerar link Supabase. HTTP: ".$http." cURL: ".$erro." resposta: ".(is_string($resposta) ? $resposta : ""));
        return null;
    }
    $link = $dados["signedURL"];
    if (strpos($link, "http://") === 0 || strpos($link, "https://") === 0) return $link;
    if (strpos($link, "/storage/v1/") === 0) return $supabaseUrl . $link;
    return $supabaseUrl . "/storage/v1/" . ltrim($link, "/");
}

$erroPagina = "";
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["salvar"])) {
    $novoNome = trim($_POST["nome"] ?? "");
    $novoEmail = trim($_POST["email"] ?? "");
    $novaSenha = $_POST["senha"] ?? "";
    $novoTelefone = trim($_POST["telefone"] ?? "");
    if ($novoNome === "" || $novoEmail === "") {
        $erroPagina = "Nome e e-mail são obrigatórios.";
    } else {
        if ($novoTelefone === "") $novoTelefone = $telefone;
        if ($novaSenha === "") {
            $r = pg_query_params($conexao, "UPDATE usuarios SET nome=$1,email=$2,telefone=$3 WHERE id_usuarios=$4 AND tipo_usuario='CLIENTE'", array($novoNome,$novoEmail,$novoTelefone,$idUsuario));
        } else {
            $r = pg_query_params($conexao, "UPDATE usuarios SET nome=$1,email=$2,senha=$3,telefone=$4 WHERE id_usuarios=$5 AND tipo_usuario='CLIENTE'", array($novoNome,$novoEmail,$novaSenha,$novoTelefone,$idUsuario));
        }
        if ($r) {
            $_SESSION["usuario"] = $novoNome;
            $_SESSION["email"] = $novoEmail;
            header("Location: area_cliente.php");
            exit;
        }
        $erroPagina = "Não foi possível atualizar. Verifique se o e-mail já está cadastrado.";
        error_log("Erro ao atualizar usuário cliente: ".pg_last_error($conexao));
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["solicitar_servico"])) {
    $tipoProjeto = trim($_POST["tipo_projeto"] ?? "");
    $endereco = trim($_POST["endereco"] ?? "");
    $descricao = trim($_POST["descricao"] ?? "");
    if ($tipoProjeto === "") die("Selecione o tipo de projeto.");
    if ($endereco === "") die("Informe a cidade ou o endereço do projeto.");
    if ($descricao === "") die("Descreva o serviço que deseja solicitar.");
    if (!$supabaseUrl || !$supabaseKey) die("A configuração do Supabase não foi encontrada.");
    $arquivos = array();
    $permitidos = array("image/jpeg"=>"jpg", "image/png"=>"png", "image/webp"=>"webp", "application/pdf"=>"pdf");
    $total = isset($_FILES["anexos"]) ? count($_FILES["anexos"]["name"]) : 0;
    $selecionados = 0;
    for ($i=0; $i<$total; $i++) if ($_FILES["anexos"]["error"][$i] !== UPLOAD_ERR_NO_FILE) $selecionados++;
    if ($selecionados > 5) die("Você pode anexar no máximo 5 arquivos por solicitação.");
    for ($i=0; $i<$total; $i++) {
        $err = $_FILES["anexos"]["error"][$i];
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err !== UPLOAD_ERR_OK) die("Erro ao receber um arquivo. Tente novamente.");
        $tmp = $_FILES["anexos"]["tmp_name"][$i];
        $size = (int)$_FILES["anexos"]["size"][$i];
        if ($size <= 0 || $size > 10*1024*1024) die("Cada arquivo deve ter no máximo 10 MB.");
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $tmp) : false;
        if ($finfo) finfo_close($finfo);
        if (!isset($permitidos[$mime])) die("Formato não permitido. Envie JPG, PNG, WebP ou PDF.");
        $arquivos[] = array("tmp"=>$tmp,"nome"=>basename($_FILES["anexos"]["name"][$i]),"mime"=>$mime,"ext"=>$permitidos[$mime]);
    }

    pg_query($conexao, "BEGIN");
    $rServico = pg_query_params(
        $conexao,
        "INSERT INTO servicos (tipo_projeto, endereco, descricao, status, id_usuario)
         VALUES ($1, $2, $3, 'SOLICITADO', $4)
         RETURNING id_servicos",
        array($tipoProjeto, $endereco, $descricao, $idUsuario)
    );
    if (!$rServico) {
        pg_query($conexao, "ROLLBACK");
        error_log("Erro ao criar serviço: ".pg_last_error($conexao));
        die("Não foi possível registrar a solicitação.");
    }
    $idServico = (int)pg_fetch_result($rServico, 0, "id_servicos");
    $enviados = array();
    $falhou = false;

    foreach ($arquivos as $arq) {
        $nomeArmazenado = bin2hex(random_bytes(16)).".".$arq["ext"];
        $caminho = $idUsuario."/".$idServico."/".$nomeArmazenado;
        $urlUpload = $supabaseUrl."/storage/v1/object/".rawurlencode($nomeBucket)."/".implode("/",array_map("rawurlencode",explode("/",$caminho)));
        $conteudo = file_get_contents($arq["tmp"]);
        if ($conteudo === false) { $falhou = true; break; }
        $curl = curl_init($urlUpload);
        curl_setopt_array($curl, array(
            CURLOPT_POST=>true,
            CURLOPT_HTTPHEADER=>array("apikey: ".$supabaseKey,"Authorization: Bearer ".$supabaseKey,"Content-Type: ".$arq["mime"],"x-upsert: false"),
            CURLOPT_POSTFIELDS=>$conteudo, CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>15, CURLOPT_TIMEOUT=>120
        ));
        $res = curl_exec($curl); $http = curl_getinfo($curl,CURLINFO_HTTP_CODE); $curlErr = curl_error($curl); curl_close($curl);
        if ($res === false || $http < 200 || $http >= 300) {
            error_log("Falha no upload Supabase. HTTP: ".$http." cURL: ".$curlErr." Resposta: ".(is_string($res)?$res:""));
            $falhou = true; break;
        }
        $enviados[] = $caminho;
        $rAnexo = pg_query_params($conexao, "INSERT INTO anexos_servicos (id_servico,nome_arquivo,caminho_arquivo) VALUES ($1,$2,$3)", array($idServico,$arq["nome"],$caminho));
        if (!$rAnexo) { error_log("Erro ao gravar anexo no Neon: ".pg_last_error($conexao)); $falhou = true; break; }
    }

    if ($falhou) {
        foreach ($enviados as $caminho) {
            $urlDel = $supabaseUrl."/storage/v1/object/".rawurlencode($nomeBucket)."/".implode("/",array_map("rawurlencode",explode("/",$caminho)));
            $c = curl_init($urlDel);
            curl_setopt_array($c,array(CURLOPT_CUSTOMREQUEST=>"DELETE",CURLOPT_HTTPHEADER=>array("apikey: ".$supabaseKey,"Authorization: Bearer ".$supabaseKey),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20));
            curl_exec($c); curl_close($c);
        }
        pg_query_params($conexao,"DELETE FROM anexos_servicos WHERE id_servico=$1",array($idServico));
        pg_query_params($conexao,"DELETE FROM servicos WHERE id_servicos=$1 AND id_usuario=$2",array($idServico,$idUsuario));
        pg_query($conexao,"COMMIT");
        die("Não foi possível salvar a solicitação ou enviar um dos arquivos. Verifique os Logs do Render.");
    }
    pg_query($conexao, "COMMIT");
    header("Location: area_cliente.php?sucesso=1");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["cancelar_servico"])) {
    $idCancelar = filter_var($_POST["id_servico"] ?? "", FILTER_VALIDATE_INT);
    if ($idCancelar) {
        $r = pg_query_params($conexao, "UPDATE servicos SET status='CANCELADO' WHERE id_servicos=$1 AND id_usuario=$2 AND status IN ('SOLICITADO','EM_ANALISE')", array($idCancelar,$idUsuario));
        if (!$r) error_log("Erro ao cancelar serviço: ".pg_last_error($conexao));
    }
    header("Location: area_cliente.php");
    exit;
}

$rServicos = pg_query_params($conexao, "SELECT id_servicos,tipo_projeto,endereco,descricao,status FROM servicos WHERE id_usuario=$1 ORDER BY id_servicos DESC", array($idUsuario));
if (!$rServicos) die("Erro ao buscar seus serviços.");
$anexosPorServico = array();
$rAnexos = pg_query_params($conexao, "SELECT a.id_servico,a.nome_arquivo,a.caminho_arquivo FROM anexos_servicos a INNER JOIN servicos s ON s.id_servicos=a.id_servico WHERE s.id_usuario=$1 ORDER BY a.id_anexo", array($idUsuario));
if ($rAnexos) {
    while ($a = pg_fetch_assoc($rAnexos)) {
        $url = urlAssinadaSupabase($supabaseUrl,$supabaseKey,$nomeBucket,$a["caminho_arquivo"]);
        $anexosPorServico[$a["id_servico"]][] = array("nome"=>$a["nome_arquivo"],"url"=>$url);
    }
}
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Área do cliente · Um Pé de IDEIA</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,400&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=Space+Grotesk:wght@500&display=swap" rel="stylesheet"><link rel="stylesheet" href="style.css"></head><body data-page="cliente">
<header class="top"><div class="wrap bar"><a class="brand" href="index.php"><img src="https://res.cloudinary.com/p2srhjwx/image/upload/v1792142855/Logo_Um_P%C3%89_deIDEIA.jpg" alt=""><span><b>Um Pé de IDEIA</b><span class="lab">Área do cliente</span></span></a><nav class="nav"><a class="btn sm out keep" href="index.php">← Voltar para a home</a><a class="btn sm dk keep" href="area_cliente.php?sair=1">Sair</a></nav></div></header>
<main class="page"><div class="wrap">
<div class="panel-top"><div class="user"><span class="avatar"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($nome,0,1,"UTF-8"),"UTF-8")); ?></span><div><b>Olá, <?php echo htmlspecialchars($nome); ?></b><span class="lab"><?php echo htmlspecialchars($email); ?></span></div></div><a class="btn out sm" href="area_cliente.php?editar=1">Editar dados</a></div>
<?php if (isset($_GET["sucesso"])): ?><p class="msg ok">Solicitação enviada com sucesso.</p><?php endif; ?>
<?php if ($erroPagina): ?><p class="msg err"><?php echo htmlspecialchars($erroPagina); ?></p><?php endif; ?>
<?php if (isset($_GET["editar"])): ?><section class="sheet pad-lg edit-box"><span class="lab ac">Minha conta</span><h2>Editar informações</h2><form method="post"><label><span class="lab">Nome</span><input name="nome" value="<?php echo htmlspecialchars($nome,ENT_QUOTES,"UTF-8"); ?>" required></label><label><span class="lab">E-mail</span><input type="email" name="email" value="<?php echo htmlspecialchars($email,ENT_QUOTES,"UTF-8"); ?>" required></label><label><span class="lab">Telefone</span><input name="telefone" value="<?php echo htmlspecialchars($telefone ?? "",ENT_QUOTES,"UTF-8"); ?>"></label><label><span class="lab">Nova senha (opcional)</span><input type="password" name="senha" autocomplete="new-password"></label><button class="btn" name="salvar" value="1">Salvar alterações</button><a class="btn out" href="area_cliente.php">Cancelar</a></form></section><?php endif; ?>
<div class="dash">
<section class="sheet pad-lg"><span class="lab ac">Nova solicitação</span><h2>Conte a sua ideia</h2><p class="hint">Anexe até 5 arquivos JPG, PNG, WebP ou PDF (máximo de 10 MB por arquivo).</p><form method="post" enctype="multipart/form-data"><label><span class="lab">Tipo de projeto</span><select id="tipoProjeto" name="tipo_projeto" required><option value="">Selecione uma opção</option><option value="Residência (obra nova)">Residência (obra nova)</option><option value="Reforma e interiores">Reforma e interiores</option><option value="Espaço comercial">Espaço comercial</option><option value="Consultoria avulsa">Consultoria avulsa</option></select></label><label><span class="lab">Cidade ou endereço</span><input id="localProjeto" name="endereco" placeholder="Cidade · Estado ou endereço completo" required maxlength="255"></label><label><span class="lab">Descreva o que você imagina</span><textarea name="descricao" required maxlength="2000" placeholder="O que gostaria de construir, reformar ou melhorar?"></textarea></label><label><span class="lab">Anexos (opcional)</span><input type="file" name="anexos[]" accept="image/jpeg,image/png,image/webp,application/pdf" multiple></label><button class="btn wide lg" name="solicitar_servico" value="1">Enviar solicitação</button></form></section>
<section><div class="head-2"><span class="lab ac">Acompanhamento</span><h2>Minhas solicitações</h2><p>Confira o status e os arquivos enviados.</p></div>
<?php if (pg_num_rows($rServicos)>0): while($s=pg_fetch_assoc($rServicos)): ?><article class="req"><div class="r1"><span class="lab">Solicitação #<?php echo (int)$s["id_servicos"]; ?></span><span class="st <?php echo htmlspecialchars(strtolower($s["status"])); ?>"><?php echo htmlspecialchars(str_replace("_"," ",$s["status"])); ?></span></div><p class="meta"><b>Tipo de projeto:</b> <?php echo htmlspecialchars($s["tipo_projeto"] ?? "Não informado",ENT_QUOTES,"UTF-8"); ?><br><b>Endereço:</b> <?php echo htmlspecialchars($s["endereco"] ?? "Não informado",ENT_QUOTES,"UTF-8"); ?></p><p class="desc"><?php echo nl2br(htmlspecialchars($s["descricao"])); ?></p><div class="att"><span class="lab">Arquivos anexados</span><div class="attachment-links"><?php if (!empty($anexosPorServico[$s["id_servicos"]])): foreach($anexosPorServico[$s["id_servicos"]] as $a): if ($a["url"]): ?><a href="<?php echo htmlspecialchars($a["url"],ENT_QUOTES,"UTF-8"); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($a["nome"],ENT_QUOTES,"UTF-8"); ?></a><?php else: ?><span><?php echo htmlspecialchars($a["nome"]); ?> (link indisponível)</span><?php endif; ?><br><?php endforeach; else: ?>Nenhum anexo.<?php endif; ?></div></div><?php if ($s["status"]==="SOLICITADO" || $s["status"]==="EM_ANALISE"): ?><form method="post" onsubmit="return confirm('Deseja cancelar esta solicitação?')"><input type="hidden" name="id_servico" value="<?php echo (int)$s["id_servicos"]; ?>"><button class="btn out sm" name="cancelar_servico" value="1">Cancelar solicitação</button></form><?php endif; ?></article><?php endwhile; else: ?><div class="empty"><b>Você ainda não enviou solicitações</b><p>Preencha o formulário ao lado para começar.</p></div><?php endif; ?>
</section></div></div></main>
<footer><div class="wrap footer-grid"><div><a class="brand" href="index.php"><b>Um Pé de IDEIA</b></a><p>Área exclusiva para clientes do escritório.</p></div><div><h4>Navegar</h4><a href="index.php">Início</a><a href="index.php#projetos">Galeria</a></div><div><h4>Contato</h4><a href="mailto:contato@umpedeideia.com.br">contato@umpedeideia.com.br</a></div></div></footer><script src="script.js"></script></body></html>
