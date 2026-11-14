<?php
session_start();
if (isset($_GET["sair"])) {
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit;
}

$mensagem = "";
$erro = "";
$tokenRedefinicao = trim($_GET["redefinir"] ?? "");
$modoRecuperacao = isset($_GET["recuperar"]);

if (isset($_GET["recuperacao"]) && $_GET["recuperacao"] === "solicitada") {
    $mensagem = "Se o e-mail estiver cadastrado, enviaremos um link para redefinir sua senha.";
    $modoRecuperacao = true;
}
if (isset($_GET["redefinido"]) && $_GET["redefinido"] === "1") {
    $mensagem = "Senha alterada. Agora você já pode entrar com a nova senha.";
}

function obterBaseUrl() {
    $appUrl = rtrim(getenv("APP_URL") ?: "", "/");
    if ($appUrl !== "") {
        return $appUrl;
    }
    $proto = $_SERVER["HTTP_X_FORWARDED_PROTO"] ?? ((!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http");
    $host = $_SERVER["HTTP_X_FORWARDED_HOST"] ?? ($_SERVER["HTTP_HOST"] ?? "");
    if ($host === "") {
        return "";
    }
    $dir = rtrim(str_replace("\\", "/", dirname($_SERVER["SCRIPT_NAME"] ?? "")), "/");
    return $proto . "://" . $host . $dir;
}

function enviarEmailRecuperacao($destinatario, $link) {
    $apiKey = trim(getenv("BREVO_API_KEY") ?: "");
    $remetenteRaw = trim(getenv("MAIL_FROM") ?: "");

    if ($apiKey === "" || $remetenteRaw === "" || !function_exists("curl_init")) {
        return false;
    }

    $senderNome = "Um Pé de IDEIA";
    $senderEmail = $remetenteRaw;
    if (preg_match('/^(.+?)\s*<([^>]+)>$/', $remetenteRaw, $matches)) {
        $senderNome = trim($matches[1], " \"'");
        $senderEmail = trim($matches[2]);
    }

    $curl = curl_init("https://api.brevo.com/v3/smtp/email");
    if (!$curl) {
        return false;
    }

    $corpo = "<p>Recebemos um pedido para redefinir a senha da sua conta <strong>Um Pé de IDEIA</strong>.</p>" .
        "<p><a href=\"" . htmlspecialchars($link, ENT_QUOTES, "UTF-8") . "\" style=\"display:inline-block;padding:12px 20px;background:#231f1c;color:#f7f5f1;text-decoration:none;border-radius:6px;\">Criar uma nova senha</a></p>" .
        "<p>Se o botão não funcionar, copie e cole este link no navegador:<br>" . htmlspecialchars($link, ENT_QUOTES, "UTF-8") . "</p>" .
        "<p>O link expira em 30 minutos. Se você não pediu a redefinição, ignore esta mensagem.</p>";

    $payload = array(
        "sender" => array(
            "name" => $senderNome,
            "email" => $senderEmail
        ),
        "to" => array(
            array("email" => $destinatario)
        ),
        "subject" => "Redefinição de senha · Um Pé de IDEIA",
        "htmlContent" => $corpo
    );

    curl_setopt_array($curl, array(
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array(
            "accept: application/json",
            "api-key: " . $apiKey,
            "content-type: application/json"
        ),
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20
    ));

    $resposta = curl_exec($curl);
    $http = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($resposta === false || $http < 200 || $http >= 300) {
        error_log("Falha Brevo ao enviar e-mail de redefinição: HTTP " . $http . " | CURL: " . curl_error($curl) . " | Resposta: " . (string)$resposta);
        curl_close($curl);
        return false;
    }
    curl_close($curl);
    return true;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $databaseUrl = getenv("DATABASE_URL");
    $conexao = $databaseUrl ? pg_connect($databaseUrl) : false;

    if (!$conexao) {
        $erro = "Não foi possível conectar ao banco de dados.";
    } elseif (isset($_POST["solicitar_redefinicao"])) {
        $email = trim($_POST["email"] ?? "");
        $baseUrl = obterBaseUrl();
        $partesUrl = $baseUrl ? parse_url($baseUrl) : false;
        $configEmailOk = $baseUrl && $partesUrl && isset($partesUrl["scheme"], $partesUrl["host"]) &&
            in_array(strtolower($partesUrl["scheme"]), array("http", "https"), true) &&
            trim(getenv("BREVO_API_KEY") ?: "") !== "" &&
            trim(getenv("MAIL_FROM") ?: "") !== "";

        $criouTabela = pg_query($conexao, "CREATE TABLE IF NOT EXISTS recuperacao_senha (id_recuperacao BIGSERIAL PRIMARY KEY, id_usuario BIGINT NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, expira_em TIMESTAMPTZ NOT NULL, criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, usado BOOLEAN NOT NULL DEFAULT FALSE)");

        if (!$criouTabela || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$configEmailOk) {
            if (!$criouTabela) {
                error_log("Não foi possível preparar a tabela de recuperação: " . pg_last_error($conexao));
            }
            if (!$configEmailOk) {
                error_log("Configure BREVO_API_KEY e MAIL_FROM (e opcionalmente APP_URL) para habilitar a recuperação de senha.");
            }
            $erro = "A recuperação de senha está temporariamente indisponível. Tente novamente mais tarde.";
        } else {
            $resultadoUsuario = pg_query_params($conexao, "SELECT id_usuarios, email FROM usuarios WHERE email = $1", array($email));
            if (!$resultadoUsuario) {
                error_log("Erro ao procurar conta para recuperação: " . pg_last_error($conexao));
                $erro = "A recuperação de senha está temporariamente indisponível. Tente novamente mais tarde.";
            } else {
                if (pg_num_rows($resultadoUsuario) === 1) {
                    $usuario = pg_fetch_assoc($resultadoUsuario);
                    $idUsuarioReset = (int)$usuario["id_usuarios"];
                    pg_query($conexao, "DELETE FROM recuperacao_senha WHERE criado_em < NOW() - INTERVAL '30 days'");
                    $limite = pg_query_params($conexao, "SELECT COUNT(*) AS total FROM recuperacao_senha WHERE id_usuario = $1 AND criado_em > NOW() - INTERVAL '15 minutes'", array($idUsuarioReset));

                    if ($limite && (int)pg_fetch_result($limite, 0, "total") < 3) {
                        $token = bin2hex(random_bytes(32));
                        $tokenHash = hash("sha256", $token);
                        pg_query_params($conexao, "UPDATE recuperacao_senha SET usado = TRUE, expira_em = NOW() WHERE id_usuario = $1 AND usado = FALSE", array($idUsuarioReset));
                        $inserido = pg_query_params($conexao, "INSERT INTO recuperacao_senha (id_usuario, token_hash, expira_em) VALUES ($1, $2, NOW() + INTERVAL '30 minutes')", array($idUsuarioReset, $tokenHash));
                        $link = $baseUrl . "/index.php?redefinir=" . rawurlencode($token) . "#acesso";

                        if (!$inserido || !enviarEmailRecuperacao($usuario["email"], $link)) {
                            if ($inserido) {
                                pg_query_params($conexao, "UPDATE recuperacao_senha SET expira_em = NOW() WHERE token_hash = $1", array($tokenHash));
                            } else {
                                error_log("Não foi possível registrar token de recuperação: " . pg_last_error($conexao));
                            }
                        }
                    } elseif (!$limite) {
                        error_log("Erro ao verificar limite de recuperação: " . pg_last_error($conexao));
                        $erro = "A recuperação de senha está temporariamente indisponível. Tente novamente mais tarde.";
                    }
                }

                if ($erro === "") {
                    header("Location: index.php?recuperacao=solicitada#acesso");
                    exit;
                }
            }
        }
        $modoRecuperacao = true;
    } elseif (isset($_POST["redefinir_senha"])) {
        $token = trim($_POST["token"] ?? "");
        $novaSenha = $_POST["nova_senha"] ?? "";
        $confirmacao = $_POST["confirmar_senha"] ?? "";

        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            $erro = "Este link de redefinição é inválido ou expirou.";
            $tokenRedefinicao = "";
        } elseif (strlen($novaSenha) < 6) {
            $erro = "A senha deve ter pelo menos 6 caracteres.";
            $tokenRedefinicao = $token;
        } elseif ($novaSenha !== $confirmacao) {
            $erro = "As senhas não coincidem.";
            $tokenRedefinicao = $token;
        } else {
            $criouTabela = pg_query($conexao, "CREATE TABLE IF NOT EXISTS recuperacao_senha (id_recuperacao BIGSERIAL PRIMARY KEY, id_usuario BIGINT NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, expira_em TIMESTAMPTZ NOT NULL, criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, usado BOOLEAN NOT NULL DEFAULT FALSE)");
            $tokenHash = hash("sha256", $token);

            if (!$criouTabela || !pg_query($conexao, "BEGIN")) {
                $erro = "Não foi possível redefinir a senha agora. Tente novamente.";
            } else {
                $consultaToken = pg_query_params($conexao, "SELECT id_usuario FROM recuperacao_senha WHERE token_hash = $1 AND expira_em > NOW() AND usado = FALSE FOR UPDATE", array($tokenHash));
                if (!$consultaToken || pg_num_rows($consultaToken) !== 1) {
                    pg_query($conexao, "ROLLBACK");
                    $erro = "Este link de redefinição é inválido ou expirou.";
                    $tokenRedefinicao = "";
                } else {
                    $idUsuarioReset = (int)pg_fetch_result($consultaToken, 0, "id_usuario");
                    $hashSenha = password_hash($novaSenha, PASSWORD_DEFAULT);
                    $atualizou = pg_query_params($conexao, "UPDATE usuarios SET senha = $1 WHERE id_usuarios = $2", array($hashSenha, $idUsuarioReset));
                    $consumiu = $atualizou ? pg_query_params($conexao, "DELETE FROM recuperacao_senha WHERE id_usuario = $1", array($idUsuarioReset)) : false;

                    if ($atualizou && $consumiu && pg_query($conexao, "COMMIT")) {
                        header("Location: index.php?redefinido=1#acesso");
                        exit;
                    }
                    pg_query($conexao, "ROLLBACK");
                    error_log("Erro ao salvar nova senha: " . pg_last_error($conexao));
                    $erro = "Não foi possível redefinir a senha agora. Tente novamente.";
                    $tokenRedefinicao = $token;
                }
            }
        }
    } elseif (isset($_POST["novoUsuario"], $_POST["novaSenha"], $_POST["novoEmail"])) {
        $nome = trim($_POST["novoUsuario"]);
        $senha = $_POST["novaSenha"];
        $email = trim($_POST["novoEmail"]);
        $telefone = trim($_POST["novoTelefone"] ?? "");

        if ($nome === "" || $senha === "" || $email === "") {
            $erro = "Preencha nome, e-mail e senha.";
        } elseif (strlen($senha) < 6) {
            $erro = "A senha deve ter pelo menos 6 caracteres.";
        } else {
            $resultado = pg_query_params(
                $conexao,
                "INSERT INTO usuarios (nome, senha, email, telefone, tipo_usuario) VALUES ($1, $2, $3, $4, 'CLIENTE')",
                array($nome, password_hash($senha, PASSWORD_DEFAULT), $email, $telefone)
            );

            if ($resultado) {
                $mensagem = "Cadastro realizado. Agora você pode entrar.";
            } else {
                $erro = "Não foi possível cadastrar. Verifique se o e-mail já está em uso.";
                error_log("Erro no cadastro de usuário: " . pg_last_error($conexao));
            }
        }
    } elseif (isset($_POST["email"], $_POST["senha"])) {
        $email = trim($_POST["email"]);
        $senha = $_POST["senha"];

        $resultado = pg_query_params(
            $conexao,
            "SELECT nome, senha, email, tipo_usuario FROM usuarios WHERE email = $1",
            array($email)
        );

        if ($resultado && pg_num_rows($resultado) === 1) {
            $usuario = pg_fetch_assoc($resultado);
            $senhaArmazenada = (string)$usuario["senha"];
            $infoSenha = password_get_info($senhaArmazenada);
            $senhaValida = $infoSenha["algo"] !== null
                ? password_verify($senha, $senhaArmazenada)
                : hash_equals($senhaArmazenada, $senha);

            if ($senhaValida) {
                if ($infoSenha["algo"] === null || password_needs_rehash($senhaArmazenada, PASSWORD_DEFAULT)) {
                    $hashSenha = password_hash($senha, PASSWORD_DEFAULT);
                    pg_query_params($conexao, "UPDATE usuarios SET senha = $1 WHERE email = $2", array($hashSenha, $email));
                }
                session_regenerate_id(true);
                $_SESSION["usuario"] = $usuario["nome"];
                $_SESSION["email"] = $usuario["email"];
                $_SESSION["tipo_usuario"] = $usuario["tipo_usuario"];

                if ($usuario["tipo_usuario"] === "CLIENTE") {
                    header("Location: area_cliente.php");
                    exit;
                } elseif ($usuario["tipo_usuario"] === "ADMINISTRADOR") {
                    header("Location: area_administrador.php");
                    exit;
                } else {
                    $erro = "Tipo de usuário inválido.";
                }
            }
        }
        $erro = "E-mail ou senha incorretos.";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Um Pé de IDEIA Arquitetura e Design</title>
<meta name="description" content="Escritório de arquitetura e design formado por três arquitetos: croquis à mão, projeto executivo e acompanhamento de obra. Peça seu orçamento pela área do cliente.">
<meta name="theme-color" content="#f7f5f1">
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 128 128'%3E%3Crect width='128' height='128' rx='22' fill='%23f5efe7'/%3E%3Cpath d='M34 96V32h18v24h18V32h18v64H70V72H52v24H34zm50 0V32h18v64H84z' fill='%23231f1c'/%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=Space+Grotesk:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
<style>
.auth-tabs { display: flex; gap: 8px; margin-bottom: 28px; }
.auth-tabs [data-auth-tab] { flex: 1; justify-content: center; }
.auth-tabs [aria-selected="true"] { background: var(--accent); border-color: var(--accent); color: var(--ink); }
.auth-tabs [aria-selected="false"] { background: transparent; }
[data-auth-panel] [hidden], [data-auth-form][hidden] { display: none !important; }
#auth .tit { margin: 8px 0 10px; }
#auth .sub { margin-bottom: 24px; }
#auth form { display: grid; gap: 18px; }
#auth form label { display: block; }
#auth form input { width: 100%; }
#auth .troca { margin-top: 18px; text-align: center; }
</style>
</head>
<body data-page="home">
<div class="ticker" aria-hidden="true">
  <div>
    <span>Imagens 3D <b>·</b> Croqui à mão <b>·</b> Projeto executivo <b>·</b> Interiores <b>·</b> Acompanhamento de obra <b>·</b> Consultoria <b>·</b></span>
    <span>Imagens 3D <b>·</b> Croqui à mão <b>·</b> Projeto executivo <b>·</b> Interiores <b>·</b> Acompanhamento de obra <b>·</b> Consultoria <b>·</b></span>
  </div>
</div>
<header class="top" id="top">
  <div class="wrap bar">
    <a class="brand" href="index.php" aria-label="Um Pé de IDEIA, página inicial">
      <img src="https://res.cloudinary.com/p2srhjwx/image/upload/v1792142855/Logo_Um_P%C3%89_deIDEIA.jpg" alt="">
      <span><b>Um Pé de IDEIA</b><span class="lab">Arquitetura &amp; Design</span></span>
    </a>
    <nav class="nav" aria-label="Principal">
      <a href="#escritorio">Escritório</a>
      <a href="#equipe">Equipe</a>
      <a href="#projetos">Galeria</a>
      <a href="#feedbacks">Feedbacks</a>
      <a href="#contato">Contato</a>
      <a class="btn sm" href="#acesso">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Login
      </a>
      <button class="burger" id="burger" aria-expanded="false" aria-controls="drawer" aria-label="Abrir menu"><i></i><i></i><i></i></button>
    </nav>
  </div>
</header>
<nav class="drawer" id="drawer" aria-label="Menu móvel">
  <a href="#escritorio">Escritório</a>
  <a href="#equipe">Equipe</a>
  <a href="#projetos">Galeria</a>
  <a href="#feedbacks">Feedbacks</a>
  <a href="#contato">Contato</a>
  <a class="btn wide" href="#acesso">Login</a>
</nav>
<main>
<section class="hero">
  <div class="wrap">
    <div class="rv">
      <span class="lab ac lab-line">Escritório de arquitetura</span>
      <h1>Onde o traço livre<br>dá forma ao <em>espaço<br>construído</em>.</h1>
      <p class="lead">Cada projeto nasce do papel, do nanquim e da escuta atenta dos seus desejos — da primeira faísca no caderno de croquis até a entrega das chaves.</p>
      <div class="acts">
        <a class="btn lg" href="#projetos">Ver projetos</a>
        <a class="btn out lg" href="#acesso">Pedir orçamento</a>
      </div>
      <div class="hero-meta">
        <div><b data-count="50" data-suffix="+">0</b><span>Ambientes transformados</span></div>
        <div><b data-count="35" data-suffix="+">0</b><span>anos de experiência somada</span></div>
        <div><b data-count="100" data-suffix="%">0</b><span>Projetos personalizados</span></div>
      </div>
    </div>
    <div class="compo rv">
      <span class="badge">Folha 01 <b>·</b> Estudo preliminar</span>
      <div class="sheet tape">
        <div class="stamp">
          <div><b>Residência em balanço</b><span class="lab">Autoria: Um Pé de IDEIA</span></div>
          <span class="lab">Nanquim · A3</span>
        </div>
        <div class="paper"><img src="https://res.cloudinary.com/p2srhjwx/image/upload/v1792136644/IMG_1436.png" alt="Croqui a nanquim de casa com volume suspenso ao lado de uma rua em curva" width="828" height="817"></div>
        <div class="scale"><span class="lab">0</span><i></i><span class="lab">5 m · escala gráfica</span></div>
      </div>
      <figure class="photo">
        <img src="https://res.cloudinary.com/p2srhjwx/image/upload/v1792139949/WhatsApp_Image_2026-10-16_at_05.34.37.jpg" alt="Residência moderna iluminada ao entardecer" loading="lazy">
      </figure>
    </div>
  </div>
</section>
<section class="sec" id="escritorio">
  <div class="wrap two">
    <div class="frame rv">
      <img src="https://res.cloudinary.com/p2srhjwx/image/upload/v1792143426/atelier.jpg" alt="Mesa do escritório de arquitetura com prancha, escalímetro e desenho técnico" loading="lazy">
      <figcaption class="cap"><span class="lab">Bancada do escritório</span>Onde toda ideia começa: papel, escalímetro e café.</figcaption>
    </div>
    <div class="rv">
      <span class="lab ac lab-line">O escritório</span>
      <h2>Você conversa direto com quem desenha o seu projeto.</h2>
      <p class="lead">O Um Pé de IDEIA é um escritório formado pela união de dois arquitetos e um designer de interiores. Juntos, acompanhamos cada projeto combinando diálogo direto, sensibilidade criativa e rigor técnico.</p>
      <ul class="pillars">
        <li><span class="n">01</span><div><h3>Sensibilidade manual</h3><p>O croqui à mão registra a intenção e a escala humana antes de qualquer software.</p></div></li>
        <li><span class="n">02</span><div><h3>Rigor técnico</h3><p>O desenho vira pranchas executivas detalhadas e compatibilizadas com as instalações.</p></div></li>
        <li><span class="n">03</span><div><h3>Arquitetura viva</h3><p>Espaços que respiram, captam a luz e conversam com a vegetação.</p></div></li>
      </ul>
    </div>
  </div>
</section>
<section class="sec tight" id="equipe">
  <div class="wrap">
    <div class="head-row">
      <div class="head-2 rv" style="margin-bottom:0">
        <span class="lab ac lab-line">Quem desenha</span>
        <h2>A equipe do escritório</h2>
        <p>Três olhares, um mesmo compromisso: transformar ideias em espaços vivos.</p>
      </div>
      <a class="btn ghost rv" href="#acesso">Falar com a equipe →</a>
    </div>
    <div class="grid-3">
      <article class="pessoa rv">
        <div class="foto"><img src="https://res.cloudinary.com/p2srhjwx/image/upload/v1793920846/Aspi1.jpg" alt="Retrato do Arqt. Aspí" loading="lazy">
        <span class="tag">Estudo e obra</span></div>
        <div class="info">
          <h3>Arqt. Aspí</h3>
          <span class="lab">CAU A57260-8 · Partido e obra</span>
          <p>Desenvolve estudos preliminares a partir do croqui à mão e acompanha as obras no canteiro.</p>
        </div>
      </article>
      <article class="pessoa rv">
        <div class="foto"><img src="https://res.cloudinary.com/p2srhjwx/image/upload/v1792143769/daniel.jpg" alt="Retrato do Arqt. Daniel" loading="lazy">
        <span class="tag">Projeto executivo</span></div>
        <div class="info">
          <h3>Daniel</h3>
          <span class="lab">Designer de Interiores</span>
          <p>Design comercial e corporativo com foco em resultados e design estratégico.</p>
        </div>
      </article>
      <article class="pessoa rv">
        <div class="foto"><img src="https://res.cloudinary.com/p2srhjwx/image/upload/v1792143768/joao-vitor.jpg" alt="Retrato do Arqt. João Vitor" loading="lazy">
        <span class="tag">Interiores e 3D</span></div>
        <div class="info">
          <h3>Arqt. João Vitor</h3>
          <span class="lab">Interiores</span>
          <p>Arquiteto especializado em desenvolvimento de projetos arquitetônicos e vizualização 3D, unindo criatividade técnica para transformar ideias em espaços reais.</p>
        </div>
      </article>
    </div>
  </div>
</section>
<section class="sec dark gal" id="projetos">
  <div class="wrap">
    <div class="head-row">
      <div class="head-2 rv" style="margin-bottom:0">
        <span class="lab lab-line">Projetos realizados</span>
        <h2>Galeria</h2>
        <p>Ambientes que saíram do papel. Escolha uma categoria e clique nas fotos para ampliar.</p>
      </div>
    </div>
    <div class="filters rv" role="group" aria-label="Filtrar projetos">
      <button aria-pressed="true" data-f="Destaques">Destaques</button>
      <button aria-pressed="false" data-f="Salas e Varandas">Salas e Varandas</button>
      <button aria-pressed="false" data-f="Cozinhas">Cozinhas</button>
      <button aria-pressed="false" data-f="Banheiros">Banheiros</button>
      <button aria-pressed="false" data-f="Quartos">Quartos</button>
      <button aria-pressed="false" data-f="Paisagismo">Paisagismo</button>
      <button aria-pressed="false" data-f="Desenhos">Desenhos</button>
    </div>
    <div class="cards" id="cards" aria-live="polite"></div>
  </div>
</section>
<section class="sec tight">
  <div class="wrap two">
    <div class="rv">
      <span class="lab ac lab-line">Do papel ao concreto</span>
      <h2>Arraste e veja o croqui virar obra</h2>
      <p class="lead">O desenho à mão é o documento onde a ideia é decidida. Compare o estudo preliminar com o resultado construído.</p>
    </div>
    <div class="compare rv" id="compare">
      <img src="https://res.cloudinary.com/p2srhjwx/image/upload/v1792125087/Via_Etilica-2.jpg" alt="Ambiente construído a partir do croqui" loading="lazy">
      <img class="top-l" id="cmpTop" src="https://res.cloudinary.com/p2srhjwx/image/upload/v1792125056/corte001_projeto_Via_Et%C3%ADlica_-_Copia.jpg" alt="Croqui do mesmo ambiente desenhado a nanquim" loading="lazy">
      <span class="cl l">Croqui</span><span class="cl r">Construído</span>
      <div class="handle" id="cmpHandle" role="slider" tabindex="0" aria-label="Comparar croqui e obra" aria-valuemin="0" aria-valuemax="100" aria-valuenow="50"></div>
    </div>
  </div>
</section>
<section class="sec alt tight" id="servicos">
  <div class="wrap">
    <div class="head-2 rv">
      <span class="lab ac lab-line">Serviços</span>
      <h2>Do estudo à última luminária</h2>
      <p>Você contrata só a etapa que precisa ou o pacote completo, e acompanha tudo pela área do cliente.</p>
    </div>
    <div class="grid-4">
      <article class="svc rv">
        <span class="ico"><svg viewBox="0 0 24 24"><path d="M3 21h18M5 21V8l7-5 7 5v13M9 21v-6h6v6"/></svg></span>
        <h3>Projeto residencial</h3>
        <p>Casas e sobrados de obra nova, do estudo ao executivo.</p>
      </article>
      <article class="svc rv">
        <span class="ico"><svg viewBox="0 0 24 24"><path d="M3 9h18M9 21V9M4 4h16a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z"/></svg></span>
        <h3>Reforma e interiores</h3>
        <p>Layout, marcenaria sob medida, iluminação e revestimentos.</p>
      </article>
      <article class="svc rv">
        <span class="ico"><svg viewBox="0 0 24 24"><path d="M2 20h20M4 20V9l8-6 8 6v11M9 20v-4h6v4M8 12h.01M16 12h.01"/></svg></span>
        <h3>Espaços comerciais</h3>
        <p>Lojas, cafés e consultórios com identidade própria.</p>
      </article>
      <article class="svc rv">
        <span class="ico"><svg viewBox="0 0 24 24"><path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6l1.4 1.4M17 17l1.4 1.4M18.4 5.6L17 7M7 17l-1.4 1.4"/><circle cx="12" cy="12" r="4"/></svg></span>
        <h3>Consultoria avulsa</h3>
        <p>Uma hora de conversa com croquis rápidos para destravar decisões.</p>
      </article>
    </div>
    <ol class="etapas rv" aria-label="Etapas do projeto">
      <li><span>01</span>Conversa inicial</li>
      <li><span>02</span>Estudo preliminar</li>
      <li><span>03</span>Anteprojeto e 3D</li>
      <li><span>04</span>Projeto executivo</li>
      <li><span>05</span>Obra acompanhada</li>
    </ol>
  </div>
</section>
<section class="sec tight" id="feedbacks">
  <div class="wrap">
    <div class="head-2 rv">
      <span class="lab ac lab-line">Experiências compartilhadas</span>
      <h2>Feedbacks dos clientes</h2>
      <p>Relatos de quem transformou uma ideia em espaço com a gente. Nomes, avatares e avaliações são ilustrativos e temporários.</p>
    </div>
    <div class="feedback-carousel">
      <div class="feedback-controls">
        <button class="feedback-arrow" id="feedbackPrev" type="button" aria-label="Ver depoimentos anteriores" aria-controls="feedbackTrack" disabled>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
        </button>
        <button class="feedback-arrow" id="feedbackNext" type="button" aria-label="Ver mais depoimentos" aria-controls="feedbackTrack">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
        </button>
      </div>
      <div class="feedback-track" id="feedbackTrack" role="region" aria-label="Depoimentos de clientes" aria-roledescription="carrossel" tabindex="0">
        <article class="feedback-card">
          <div class="feedback-photo">
            <img src="https://api.dicebear.com/9.x/avataaars/svg?seed=Ana-Rafael&amp;backgroundColor=f1d8b5" alt="Avatar ilustrativo de Ana e Rafael" loading="lazy">
          </div>
          <div class="feedback-stars" role="img" aria-label="Avaliação ilustrativa: 5 de 5 estrelas">★★★★★</div>
          <span class="lab">Feedback de cliente</span>
          <blockquote>Criatividade e bom gosto definem! A Um Pé de IDEIA transformou nosso espaço com soluções super inteligentes e funcionais.</blockquote>
          <cite>Ana e Rafael · nomes ilustrativos</cite>
        </article>
        <article class="feedback-card">
          <div class="feedback-photo">
            <img src="https://api.dicebear.com/9.x/avataaars/svg?seed=Marina&amp;backgroundColor=d1e4dc" alt="Avatar ilustrativo de Marina" loading="lazy">
          </div>
          <div class="feedback-stars" role="img" aria-label="Avaliação ilustrativa: 5 de 5 estrelas">★★★★★</div>
          <span class="lab">Feedback de cliente</span>
          <blockquote>Atendimento impecável e entrega no prazo. Superaram todas as nossas expectativas!</blockquote>
          <cite>Marina · nome ilustrativo</cite>
        </article>
        <article class="feedback-card">
          <div class="feedback-photo">
            <img src="https://api.dicebear.com/9.x/avataaars/svg?seed=Camila&amp;backgroundColor=d8d5ef" alt="Avatar ilustrativo de Camila" loading="lazy">
          </div>
          <div class="feedback-stars" role="img" aria-label="Avaliação ilustrativa: 5 de 5 estrelas">★★★★★</div>
          <span class="lab">Feedback de cliente</span>
          <blockquote>Profissionais atenciosos que unem estética e praticidade como poucos. Recomendo de olhos fechados!</blockquote>
          <cite>Camila · nome ilustrativo</cite>
        </article>
        <article class="feedback-card">
          <div class="feedback-photo">
            <img src="https://api.dicebear.com/9.x/avataaars/svg?seed=Fernanda&amp;backgroundColor=f2d2ce" alt="Avatar ilustrativo de Fernanda" loading="lazy">
          </div>
          <div class="feedback-stars" role="img" aria-label="Avaliação ilustrativa: 5 de 5 estrelas">★★★★★</div>
          <span class="lab">Feedback de cliente</span>
          <blockquote>Projeto moderno, aconchegante e com a nossa cara. O ambiente ganhou outra vida!</blockquote>
          <cite>Fernanda · nome ilustrativo</cite>
        </article>
        <article class="feedback-card">
          <div class="feedback-photo">
            <img src="https://api.dicebear.com/9.x/avataaars/svg?seed=Lucas&amp;backgroundColor=d6e3f3" alt="Avatar ilustrativo de Lucas" loading="lazy">
          </div>
          <div class="feedback-stars" role="img" aria-label="Avaliação ilustrativa: 5 de 5 estrelas">★★★★★</div>
          <span class="lab">Feedback de cliente</span>
          <blockquote>Excelente experiência! Dá para sentir o cuidado e a paixão pelo design em cada detalhe.</blockquote>
          <cite>Lucas · nome ilustrativo</cite>
        </article>
      </div>
    </div>
  </div>
</section>
<section class="sec" id="contato">
  <div class="wrap contact">
    <div class="rv">
      <span class="lab ac lab-line">Contato</span>
      <h2>Vamos tomar um café e desenhar a primeira ideia juntos?</h2>
      <p class="lead">Conte o que você quer construir ou reformar e anexe fotos, vídeos e PDFs pela área do cliente. A proposta chega em até 48 horas úteis, sem compromisso.</p>
      <div class="faq" style="margin-top:26px">
        <details><summary>Quanto custa um projeto?</summary><p>O valor depende da área, da complexidade e das etapas contratadas. Depois de receber sua solicitação, enviamos uma proposta detalhada.</p></details>
        <details><summary>Em quanto tempo fica pronto?</summary><p>Um estudo preliminar leva de 2 a 3 semanas. O projeto executivo completo, de 8 a 12 semanas, conforme o porte da obra.</p></details>
      </div>
    </div>
    <div class="rv">
      <div class="sheet pad-lg">
        <span class="lab ac">Fale com o escritório</span>
        <ul class="cinfo">
          <li><span class="ico"><svg viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/><path d="m4 6 8 6 8-6"/></svg></span>
          <div><b>aspi@umpedeideia.com</b><span>Resposta em até 1 dia útil</span></div></li>
          <li><span class="ico"><svg viewBox="0 0 24 24"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1.4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.2a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/></svg></span>
          <div><b>(11) 98291-6734</b><span>WhatsApp seg a sex, 9h às 18h</span></div></li>
          <li><span class="ico"><svg viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></svg></span>
          <div><b>Santana Zona Norte de São Paulo/SP</b><span>Visitas ao escritório com hora marcada</span></div></li>
        </ul>
        <a class="btn wide lg" href="#acesso" style="margin-top:26px">Pedir meu orçamento</a>
      </div>
    </div>
  </div>
</section>
<section class="sec tight alt" id="acesso">
  <div class="wrap">
    <div class="login">
      <div class="art">
        <img src="https://res.cloudinary.com/p2srhjwx/image/upload/v1792139949/WhatsApp_Image_2026-10-16_at_05.34.37.jpg" alt="Residência contemporânea do portfólio" loading="lazy">
        <div class="ov">
          <span class="lab"></span>
          <h2>Acompanhe seu projeto em cada etapa.</h2>
          <p>Envie sua solicitação, compartilhe arquivos e acompanhe as respostas da equipe.</p>
          <ul>
            <li>Fotos, vídeos e documentos no mesmo pedido</li>
            <li>Status e respostas reunidos na sua conta</li>
            <li>Acesso seguro com e-mail e senha</li>
          </ul>
        </div>
      </div>
      <div class="form" id="auth">
        <?php if ($mensagem !== ""): ?>
          <p class="msg ok" role="status"><?php echo htmlspecialchars($mensagem, ENT_QUOTES, "UTF-8"); ?></p>
        <?php endif; ?>
        <?php if ($erro !== ""): ?>
          <p class="msg err" role="alert"><?php echo htmlspecialchars($erro, ENT_QUOTES, "UTF-8"); ?></p>
        <?php endif; ?>
        <?php if ($tokenRedefinicao !== ""): ?>
          <span class="lab ac lab-line">Redefinir senha</span>
          <h2 class="tit">Crie uma nova senha</h2>
          <p class="sub">Escolha uma senha com pelo menos 6 caracteres.</p>
          <form method="post">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($tokenRedefinicao, ENT_QUOTES, "UTF-8"); ?>">
            <label>
              <span class="lab">Nova senha</span>
              <span class="pw">
                <input type="password" name="nova_senha" required minlength="6" autocomplete="new-password" placeholder="Mínimo de 6 caracteres">
                <button type="button" class="eye" aria-label="Mostrar senha">
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M2 12s3.8-6.5 10-6.5S22 12 22 12s-3.8 6.5-10 6.5S2 12 2 12z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                  </svg>
                </button>
              </span>
            </label>
            <label>
              <span class="lab">Confirme a nova senha</span>
              <span class="pw">
                <input type="password" name="confirmar_senha" required minlength="6" autocomplete="new-password" placeholder="Repita a nova senha">
                <button type="button" class="eye" aria-label="Mostrar senha">
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M2 12s3.8-6.5 10-6.5S22 12 22 12s-3.8 6.5-10 6.5S2 12 2 12z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                  </svg>
                </button>
              </span>
            </label>
            <button class="btn wide lg" type="submit" name="redefinir_senha" value="1">Salvar nova senha</button>
          </form>
          <p class="troca"><a class="link" href="index.php#acesso">Voltar para entrar</a></p>
        <?php elseif ($modoRecuperacao): ?>
          <span class="lab ac lab-line">Recuperar acesso</span>
          <h2 class="tit">Esqueceu sua senha?</h2>
          <p class="sub">Informe o e-mail da sua conta. Se estiver cadastrado, enviaremos um link para criar uma nova senha.</p>
          <form method="post">
            <label><span class="lab">E-mail</span><input type="email" name="email" required autocomplete="email" placeholder="voce@email.com"></label>
            <button class="btn wide lg" type="submit" name="solicitar_redefinicao" value="1">Enviar link de recuperação</button>
          </form>
          <p class="troca"><a class="link" href="index.php#acesso">Voltar para entrar</a></p>
        <?php else: ?>
        <div class="auth-tabs" role="tablist" aria-label="Acesso à conta">
          <button type="button" class="btn sm" data-auth-tab="entrar" aria-selected="true">Entrar</button>
          <button type="button" class="btn sm out" data-auth-tab="criar" aria-selected="false">Criar conta</button>
        </div>
        <div data-auth-panel="entrar">
          <span class="lab ac lab-line">Entrar</span>
          <h2 class="tit">Bem-vindo de volta</h2>
          <p class="sub">Acesse com o e-mail e a senha cadastrados para abrir o seu painel.</p>
          <form method="post" id="loginForm" data-auth-form="entrar">
            <label>
              <span class="lab">E-mail</span>
              <input type="email" name="email" required autocomplete="username" placeholder="voce@email.com">
            </label>
            <label>
              <span class="lab lab-row">Senha</span>
              <span class="pw">
                <input type="password" name="senha" required autocomplete="current-password" placeholder="Sua senha">
                <button type="button" class="eye" aria-label="Mostrar senha">
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M2 12s3.8-6.5 10-6.5S22 12 22 12s-3.8 6.5-10 6.5S2 12 2 12z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                  </svg>
                </button>
              </span>
            </label>
            <button class="btn wide lg" type="submit">
              Entrar no meu painel
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                <path d="M5 12h14m-6-6 6 6-6 6"></path>
              </svg>
            </button>
          </form>
          <p class="troca"><a class="link" href="index.php?recuperar=1#acesso">Esqueci minha senha</a></p>
          <p class="troca">Ainda não tem conta? <button type="button" class="link" data-go="criar">Criar conta</button></p>
        </div>
        <div data-auth-panel="criar" hidden>
          <span class="lab ac lab-line">Criar conta</span>
          <h2 class="tit">Vamos começar</h2>
          <p class="sub">Preencha seus dados para acompanhar o andamento do seu projeto.</p>
          <form method="post" id="cadastroForm" data-auth-form="criar">
            <label>
              <span class="lab">Seu nome completo</span>
              <input name="novoUsuario" required autocomplete="name" placeholder="Maria Aparecida Souza">
            </label>
            <label>
              <span class="lab">E-mail</span>
              <input type="email" name="novoEmail" required autocomplete="email" placeholder="voce@email.com">
            </label>
            <label>
              <span class="lab">Telefone <span class="opc">(opcional)</span></span>
              <input type="tel" name="novoTelefone" autocomplete="tel" placeholder="(11) 98291-6734">
            </label>
            <label>
              <span class="lab">Criar senha</span>
              <span class="pw">
                <input type="password" name="novaSenha" required minlength="6" autocomplete="new-password" placeholder="Mínimo de 6 caracteres">
                <button type="button" class="eye" aria-label="Mostrar senha">
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M2 12s3.8-6.5 10-6.5S22 12 22 12s-3.8 6.5-10 6.5S2 12 2 12z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                  </svg>
                </button>
              </span>
            </label>
            <button class="btn wide lg" type="submit">Criar minha conta</button>
          </form>
          <p class="troca">Já tem cadastro? <button type="button" class="link" data-go="entrar">Entrar</button></p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
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
        <a href="#escritorio">Escritório</a><a href="#equipe">Equipe</a><a href="#projetos">Galeria</a><a href="#servicos">Serviços</a><a href="#feedbacks">Feedbacks</a><a href="#contato">Contato</a>
      </div>
      <div>
        <h4>Contato</h4>
        <a href="mailto:aspi@umpedeideia.com">aspi@umpedeideia.com</a>
        <a href="tel:+5511982916734">(11) 98291-6734</a>
        <a href="#acesso">Login</a>
      </div>
    </div>
    <div class="end">
      <span>© <span id="ano">2026</span> Um Pé de IDEIA · Todos os direitos reservados</span>
      <span>Três especialistas, um escritório · São Paulo SP</span>
    </div>
  </div>
</footer>
<a class="wa-float" href="https://wa.me/5511982916734?text=Ol%C3%A1!%20Vim%20pelo%20site%20e%20gostaria%20de%20conversar%20sobre%20um%20projeto." target="_blank" rel="noopener" aria-label="Conversar com o escritório pelo WhatsApp">
  <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.16-.17.2-.35.22-.64.08-.3-.15-1.26-.47-2.39-1.48-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.6.13-.14.3-.35.45-.52.15-.18.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.61-.92-2.21-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.8.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.21 3.07c.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.7.63.71.22 1.36.19 1.87.12.57-.09 1.76-.72 2-1.41.25-.7.25-1.29.18-1.41-.08-.13-.27-.2-.57-.35m-5.42 7.4a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.45 4.44-9.88 9.89-9.88a9.83 9.83 0 0 1 9.88 9.89c0 5.45-4.44 9.88-9.89 9.88M20.46 3.49A11.82 11.82 0 0 0 12.05 0C5.5 0 .16 5.34.16 11.89c0 2.1.55 4.14 1.59 5.95L.06 24l6.3-1.65a11.88 11.88 0 0 0 5.69 1.45c6.55 0 11.89-5.34 11.89-11.89 0-3.18-1.24-6.17-3.48-8.42"/></svg>
</a>
<div class="lb" id="lb" role="dialog" aria-modal="true" aria-label="Visualizador de mídia">
  <button class="x" id="lbX" aria-label="Fechar">✕</button>
  <button class="nav-b prev" id="lbPrev" aria-label="Anterior">‹</button>
  <button class="nav-b next" id="lbNext" aria-label="Próximo">›</button>
  <figure id="lbFig"></figure>
</div>
<script src="script.js"></script>
<div vw class="enabled">
  <div vw-access-button class="active"></div>
  <div vw-plugin-wrapper>
    <div class="vw-plugin-top-wrapper"></div>
  </div>
</div>
<script src="https://vlibras.gov.br/app/vlibras-plugin.js"></script>
<script>new window.VLibras.Widget('https://vlibras.gov.br/app');</script>
</body>
</html>
