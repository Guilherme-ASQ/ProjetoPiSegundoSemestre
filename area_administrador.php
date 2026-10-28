<?php
session_start();
if (isset($_GET["sair"])) { session_unset(); session_destroy(); header("Location: index.php"); exit; }
if (!isset($_SESSION["usuario"],$_SESSION["email"]) || !isset($_SESSION["tipo_usuario"]) || $_SESSION["tipo_usuario"] !== "ADMINISTRADOR") { header("Location: index.php"); exit; }
$databaseUrl = getenv("DATABASE_URL");
$conexao = $databaseUrl ? pg_connect($databaseUrl) : false;
if (!$conexao) die("Erro ao conectar ao banco de dados.");
$supabaseUrl = rtrim(getenv("SUPABASE_URL") ?: "", "/");
$supabaseKey = getenv("SUPABASE_SERVICE_ROLE_KEY") ?: "";
$nomeBucket = "anexo-servicos";

function adminUrlAssinada($base,$key,$bucket,$path) {
    if (!$base || !$key || !$path) return null;
    $path = implode("/",array_map("rawurlencode",explode("/",$path)));
    $url = $base."/storage/v1/object/sign/".rawurlencode($bucket)."/".$path;
    $c = curl_init($url);
    if (!$c) return null;
    curl_setopt_array($c,array(CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>array("apikey: ".$key,"Authorization: Bearer ".$key,"Content-Type: application/json"),CURLOPT_POSTFIELDS=>json_encode(array("expiresIn"=>3600)),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>30));
    $res=curl_exec($c); $http=curl_getinfo($c,CURLINFO_HTTP_CODE); $err=curl_error($c); curl_close($c);
    $data=is_string($res)?json_decode($res,true):null;
    if ($http<200 || $http>=300 || !is_array($data) || empty($data["signedURL"])) { error_log("Falha ao assinar URL do anexo: HTTP ".$http." cURL ".$err." resposta ".(is_string($res)?$res:"")); return null; }
    $link=$data["signedURL"];
    if (strpos($link,"http://")===0 || strpos($link,"https://")===0) return $link;
    if (strpos($link,"/storage/v1/")===0) return $base.$link;
    return $base."/storage/v1/".ltrim($link,"/");
}
$usuarioLogado=$_SESSION["email"];
$rUser=pg_query_params($conexao,"SELECT id_usuarios,nome,email,senha,telefone FROM usuarios WHERE email=$1 AND tipo_usuario='ADMINISTRADOR'",array($usuarioLogado));
if (!$rUser || pg_num_rows($rUser)!==1) die("Administrador não encontrado.");
$u=pg_fetch_assoc($rUser); $idUsuario=(int)$u["id_usuarios"]; $nome=$u["nome"]; $email=$u["email"]; $senha=$u["senha"]; $telefone=$u["telefone"];
$erro="";

if ($_SERVER["REQUEST_METHOD"]==="POST" && isset($_POST["alterar_status"])) {
    $id=filter_var($_POST["id_servico"]??"",FILTER_VALIDATE_INT);
    $novo=$_POST["novo_status"]??"";
    $permitidos=array("EM_ANALISE"=>"SOLICITADO","CONCLUIDO"=>"EM_ANALISE","CANCELADO"=>"EM_ANALISE");
    if (!$id || !isset($permitidos[$novo])) die("Alteração de status inválida.");
    $r=pg_query_params($conexao,"UPDATE servicos SET status=$1 WHERE id_servicos=$2 AND status=$3",array($novo,$id,$permitidos[$novo]));
    if (!$r) { error_log("Erro ao alterar status: ".pg_last_error($conexao)); die("Erro ao alterar status do serviço."); }
    header("Location: area_administrador.php"); exit;
}
if ($_SERVER["REQUEST_METHOD"]==="POST" && isset($_POST["salvar"])) {
    $novoNome=trim($_POST["nome"]??""); $novoEmail=trim($_POST["email"]??""); $novaSenha=$_POST["senha"]??""; $novoTelefone=trim($_POST["telefone"]??"");
    if ($novoTelefone==="") $novoTelefone=$telefone;
    if ($novoNome==="" || $novoEmail==="") $erro="Nome e e-mail são obrigatórios.";
    else {
        if ($novaSenha==="") $r=pg_query_params($conexao,"UPDATE usuarios SET nome=$1,email=$2,telefone=$3 WHERE id_usuarios=$4 AND tipo_usuario='ADMINISTRADOR'",array($novoNome,$novoEmail,$novoTelefone,$idUsuario));
        else $r=pg_query_params($conexao,"UPDATE usuarios SET nome=$1,email=$2,senha=$3,telefone=$4 WHERE id_usuarios=$5 AND tipo_usuario='ADMINISTRADOR'",array($novoNome,$novoEmail,$novaSenha,$novoTelefone,$idUsuario));
        if ($r) { $_SESSION["usuario"]=$novoNome; $_SESSION["email"]=$novoEmail; header("Location: area_administrador.php"); exit; }
        $erro="Não foi possível atualizar os dados."; error_log("Erro ao atualizar admin: ".pg_last_error($conexao));
    }
}
function buscarServicos($conexao,$status) {
    return pg_query_params($conexao,"SELECT s.id_servicos AS id_servico,u.nome,u.email,u.telefone,s.tipo_projeto,s.endereco,s.descricao AS solicitacao,s.status FROM servicos s INNER JOIN usuarios u ON u.id_usuarios=s.id_usuario WHERE u.tipo_usuario='CLIENTE' AND s.status=$1 ORDER BY s.id_servicos ASC",array($status));
}
function buscarAnexosAdmin($conexao,$idServico,$base,$key,$bucket) {
    $lista=array(); $r=pg_query_params($conexao,"SELECT nome_arquivo,caminho_arquivo FROM anexos_servicos WHERE id_servico=$1 ORDER BY id_anexo",array($idServico));
    if (!$r) { error_log("Erro ao consultar anexos: ".pg_last_error($conexao)); return $lista; }
    while($a=pg_fetch_assoc($r)) $lista[]=array("nome"=>$a["nome_arquivo"],"url"=>adminUrlAssinada($base,$key,$bucket,$a["caminho_arquivo"]));
    return $lista;
}
$rSolicitados=buscarServicos($conexao,"SOLICITADO"); $rAnalise=buscarServicos($conexao,"EM_ANALISE"); $rConcluidos=buscarServicos($conexao,"CONCLUIDO");
if (!$rSolicitados || !$rAnalise || !$rConcluidos) die("Erro ao carregar os serviços.");
function mostraTabelaAdmin($resultado,$titulo,$tipo,$conexao,$base,$key,$bucket) {
    echo '<section class="admin-col"><h2>'.$titulo.'</h2><div class="admin-list">';
    if (pg_num_rows($resultado)===0) { echo '<div class="empty"><p>Nenhum serviço nesta etapa.</p></div>'; }
    while($s=pg_fetch_assoc($resultado)) {
        echo '<article class="req"><div class="r1"><span class="lab">Solicitação #'.(int)$s["id_servico"].'</span><span class="st">'.htmlspecialchars(str_replace("_"," ",$s["status"])).'</span></div>';
        echo '<h3>'.htmlspecialchars($s["nome"]).'</h3><p class="meta">'.htmlspecialchars($s["email"]).($s["telefone"] ? ' · '.htmlspecialchars($s["telefone"]) : '').'</p><p class="meta"><b>Tipo de projeto:</b> '.htmlspecialchars($s["tipo_projeto"]??"Não informado").'<br><b>Endereço:</b> '.htmlspecialchars($s["endereco"]??"Não informado").'</p><p class="desc">'.nl2br(htmlspecialchars($s["solicitacao"])).'</p>';
        echo '<div class="att"><span class="lab">Anexos do cliente</span><div class="attachment-links">';
        $anexos=buscarAnexosAdmin($conexao,$s["id_servico"],$base,$key,$bucket);
        if (!$anexos) echo 'Nenhum arquivo anexado.';
        foreach($anexos as $a) { if ($a["url"]) echo '<a target="_blank" rel="noopener noreferrer" href="'.htmlspecialchars($a["url"],ENT_QUOTES,"UTF-8").'">'.htmlspecialchars($a["nome"]).'</a><br>'; else echo '<span>'.htmlspecialchars($a["nome"]).' (link indisponível)</span><br>'; }
        echo '</div></div>';
        if ($tipo==="SOLICITADO") echo '<form method="post"><input type="hidden" name="id_servico" value="'.(int)$s["id_servico"].'"><button class="btn wide sm" name="alterar_status" value="1" type="submit"><input type="hidden" name="novo_status" value="EM_ANALISE">Iniciar análise</button></form>';
        if ($tipo==="EM_ANALISE") echo '<div class="acts2"><form method="post"><input type="hidden" name="id_servico" value="'.(int)$s["id_servico"].'"><input type="hidden" name="novo_status" value="CONCLUIDO"><button class="btn sm" name="alterar_status" value="1">Concluir serviço</button></form><form method="post" onsubmit="return confirm(\'Cancelar este serviço?\')"><input type="hidden" name="id_servico" value="'.(int)$s["id_servico"].'"><input type="hidden" name="novo_status" value="CANCELADO"><button class="btn out sm" name="alterar_status" value="1">Cancelar serviço</button></form></div>';
        echo '</article>';
    }
    echo '</div></section>';
}
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Painel do arquiteto · Um Pé de IDEIA</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,400&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=Space+Grotesk:wght@500&display=swap" rel="stylesheet"><link rel="stylesheet" href="style.css"></head><body data-page="admin">
<header class="top"><div class="wrap bar"><a class="brand" href="index.php"><img src="https://res.cloudinary.com/p2srhjwx/image/upload/v1792142855/Logo_Um_P%C3%89_deIDEIA.jpg" alt=""><span><b>Um Pé de IDEIA</b><span class="lab">Painel do arquiteto</span></span></a><nav class="nav"><a class="btn sm out keep" href="index.php">← Voltar para a home</a><a class="btn sm dk keep" href="area_administrador.php?sair=1">Sair</a></nav></div></header>
<main class="page"><div class="wrap">
<div class="panel-top"><div class="user"><span class="avatar"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($nome,0,1,"UTF-8"),"UTF-8")); ?></span><div><b><?php echo htmlspecialchars($nome); ?></b><span class="lab"><?php echo htmlspecialchars($email); ?></span></div></div><a class="btn out sm" href="area_administrador.php?editar=1">Editar dados</a></div>
<?php if($erro): ?><p class="msg err"><?php echo htmlspecialchars($erro); ?></p><?php endif; ?>
<?php if(isset($_GET["editar"])): ?><section class="sheet pad-lg edit-box"><h2>Editar informações</h2><form method="post"><label><span class="lab">Nome</span><input name="nome" required value="<?php echo htmlspecialchars($nome,ENT_QUOTES,"UTF-8"); ?>"></label><label><span class="lab">E-mail</span><input type="email" name="email" required value="<?php echo htmlspecialchars($email,ENT_QUOTES,"UTF-8"); ?>"></label><label><span class="lab">Telefone</span><input name="telefone" value="<?php echo htmlspecialchars($telefone??"",ENT_QUOTES,"UTF-8"); ?>"></label><label><span class="lab">Nova senha (opcional)</span><input type="password" name="senha"></label><button class="btn" name="salvar" value="1">Salvar</button><a class="btn out" href="area_administrador.php">Cancelar</a></form></section><?php endif; ?>
<div class="head-2"><span class="lab ac lab-line">Caixa de entrada</span><h1>Solicitações recebidas</h1><p>Consulte os pedidos e os arquivos enviados pelos clientes.</p></div>
<div class="kpis"><div class="kpi ac"><span class="lab">Total recebido</span><b><?php echo pg_num_rows($rSolicitados)+pg_num_rows($rAnalise)+pg_num_rows($rConcluidos); ?></b></div><div class="kpi"><span class="lab">Novas</span><b><?php echo pg_num_rows($rSolicitados); ?></b></div><div class="kpi"><span class="lab">Em análise</span><b><?php echo pg_num_rows($rAnalise); ?></b></div><div class="kpi"><span class="lab">Concluídas</span><b><?php echo pg_num_rows($rConcluidos); ?></b></div></div>
<?php if(!$supabaseUrl || !$supabaseKey): ?><p class="msg err">Atenção: configure SUPABASE_URL e SUPABASE_SERVICE_ROLE_KEY no Render para exibir anexos.</p><?php endif; ?>
<div class="admin-board"><?php mostraTabelaAdmin($rSolicitados,"Solicitado","SOLICITADO",$conexao,$supabaseUrl,$supabaseKey,$nomeBucket); mostraTabelaAdmin($rAnalise,"Em análise","EM_ANALISE",$conexao,$supabaseUrl,$supabaseKey,$nomeBucket); mostraTabelaAdmin($rConcluidos,"Concluído","CONCLUIDO",$conexao,$supabaseUrl,$supabaseKey,$nomeBucket); ?></div>
</div></main><footer><div class="wrap footer-grid"><div><a class="brand" href="index.php"><b>Um Pé de IDEIA</b></a><p>Painel interno do escritório.</p></div><div><h4>Contato</h4><a href="mailto:contato@umpedeideia.com.br">contato@umpedeideia.com.br</a></div></div></footer><script src="script.js"></script></body></html>
