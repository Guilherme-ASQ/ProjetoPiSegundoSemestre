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

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $databaseUrl = getenv("DATABASE_URL");
    $conexao = $databaseUrl ? pg_connect($databaseUrl) : false;

    if (!$conexao) {
        $erro = "Não foi possível conectar ao banco de dados.";
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
            // Mantém a estrutura existente do banco. Recomendação: migrar senhas para password_hash().
            $resultado = pg_query_params(
                $conexao,
                "INSERT INTO usuarios (nome, senha, email, telefone, tipo_usuario)
                 VALUES ($1, $2, $3, $4, 'CLIENTE')",
                array($nome, $senha, $email, $telefone)
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
            // Compatibilidade com os registros atuais; usar password_verify() após migração para hashes.
            if (hash_equals((string)$usuario["senha"], (string)$senha)) {
                session_regenerate_id(true);
                $_SESSION["usuario"] = $usuario["nome"];
                $_SESSION["email"] = $usuario["email"];
                $_SESSION["tipo_usuario"] = $usuario["tipo_usuario"];
                if ($usuario["tipo_usuario"] === "CLIENTE") {
                    header("Location: area_cliente.php");
                } elseif ($usuario["tipo_usuario"] === "ADMINISTRADOR") {
                    header("Location: area_administrador.php");
                } else {
                    $erro = "Tipo de usuário inválido.";
                }
                exit;
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
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Um Pé de IDEIA · Arquitetura e Design</title>
<meta name="description" content="Arquitetura e design: croquis, projetos executivos, interiores e acompanhamento de obra.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,400;9..144,500;9..144,300&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=Space+Grotesk:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body data-page="home">
<div class="ticker"><div><span>Imagens 3D <b>·</b> Croqui à mão <b>·</b> Projeto executivo <b>·</b> Interiores <b>·</b> Acompanhamento de obra <b>·</b> Consultoria <b>·</b></span><span>Imagens 3D <b>·</b> Croqui à mão <b>·</b> Projeto executivo <b>·</b> Interiores <b>·</b> Acompanhamento de obra <b>·</b> Consultoria <b>·</b></span></div></div>
<header class="top" id="top"><div class="wrap bar">
<a class="brand" href="index.php"><img src="https://res.cloudinary.com/p2srhjwx/image/upload/v1792142855/Logo_Um_P%C3%89_deIDEIA.jpg" alt=""><span><b>Um Pé de IDEIA</b><span class="lab">Arquitetura &amp; Design</span></span></a>
<nav class="nav"><a href="#escritorio">Escritório</a><a href="#equipe">Equipe</a><a href="#projetos">Galeria</a><a href="#contato">Contato</a><a class="btn sm" href="#acesso">Área do cliente</a><button class="burger" id="burger" aria-expanded="false" aria-label="Abrir menu"><i></i><i></i><i></i></button></nav>
</div></header>
<nav class="drawer" id="drawer"><a href="#escritorio">Escritório</a><a href="#equipe">Equipe</a><a href="#projetos">Galeria</a><a href="#contato">Contato</a><a class="btn wide" href="#acesso">Área do cliente</a></nav>
<main>
<section class="hero"><div class="wrap hero-grid">
<div class="rv"><span class="lab ac lab-line">Escritório de arquitetura</span><h1>Onde o traço livre dá forma ao <em>espaço construído.</em></h1><p class="lead">Cada projeto nasce do papel, do nanquim e da escuta atenta dos seus desejos — da primeira faísca no caderno de croquis até a entrega das chaves.</p><div class="acts"><a class="btn lg" href="#projetos">Ver projetos</a><a class="btn out lg" href="#acesso">Pedir orçamento</a></div><div class="hero-meta"><div><b>140</b><span>projetos entregues</span></div><div><b>35+</b><span>anos de experiência somada</span></div><div><b>32</b><span>cidades atendidas</span></div></div></div>
<div class="compo rv"><span class="badge">Folha 01 · Estudo preliminar</span><div class="sheet tape"><div class="stamp"><div><b>Residência em balanço</b><span class="lab">Autoria: Um Pé de IDEIA</span></div><span class="lab">Nanquim · A3</span></div><div class="paper"><img src="https://res.cloudinary.com/p2srhjwx/image/upload/v1792136644/IMG_1436.png" alt="Croqui de residência"></div><div class="scale"><span class="lab">0</span><i></i><span class="lab">5 m · escala gráfica</span></div></div><figure class="photo"><img src="https://res.cloudinary.com/p2srhjwx/image/upload/v1792139949/WhatsApp_Image_2026-10-16_at_05.34.37.jpg" alt="Residência contemporânea"></figure></div>
</div></section>
<section class="sec" id="escritorio"><div class="wrap two"><div class="frame rv"><img src="https://res.cloudinary.com/p2srhjwx/image/upload/v1792143426/atelier.jpg" alt="Mesa de trabalho do escritório"><figcaption class="cap"><span class="lab">Bancada do escritório</span>Onde toda ideia começa: papel, escalímetro e café.</figcaption></div><div class="rv"><span class="lab ac lab-line">O escritório</span><h2>Você conversa direto com quem desenha o seu projeto.</h2><p class="lead">O Um Pé de IDEIA é um escritório de arquitetura formado por três arquitetos. Acompanhamos cada projeto do primeiro croqui à obra, com diálogo direto e integração entre criação e rigor técnico.</p><ul class="pillars"><li><span class="n">01</span><div><h3>Sensibilidade manual</h3><p>O croqui à mão registra a intenção e a escala humana antes de qualquer software.</p></div></li><li><span class="n">02</span><div><h3>Rigor técnico</h3><p>O desenho vira pranchas executivas detalhadas e compatibilizadas.</p></div></li><li><span class="n">03</span><div><h3>Arquitetura viva</h3><p>Espaços que respiram, captam a luz e conversam com a vegetação.</p></div></li></ul></div></div></section>
<section class="sec tight alt" id="equipe"><div class="wrap"><div class="head-2"><span class="lab ac lab-line">Quem desenha</span><h2>A equipe do escritório</h2><p>Três arquitetos, um mesmo compromisso: do primeiro croqui ao detalhe da marcenaria.</p></div><div class="grid-3"><article class="pessoa"><img src="https://res.cloudinary.com/p2srhjwx/image/upload/v1792143768/aspi.jpg" alt="Arquiteto Aspí"><div class="info"><h3>Arqt. Aspí</h3><span class="lab">Estudo e obra</span><p>Desenvolve estudos preliminares e acompanha as obras.</p></div></article><article class="pessoa"><img src="https://res.cloudinary.com/p2srhjwx/image/upload/v1792143769/daniel.jpg" alt="Arquiteto Daniel"><div class="info"><h3>Arqt. Daniel</h3><span class="lab">Projeto executivo</span><p>Transforma o desenho em pranchas executivas compatibilizadas.</p></div></article><article class="pessoa"><img src="https://res.cloudinary.com/p2srhjwx/image/upload/v1792143768/joao-vitor.jpg" alt="Arquiteto João Vitor"><div class="info"><h3>Arqt. João Vitor</h3><span class="lab">Interiores e 3D</span><p>Cuida do layout, marcenaria e visualização do projeto.</p></div></article></div></div></section>
<section class="sec dark gal" id="projetos"><div class="wrap"><div class="head-2"><span class="lab">Projetos realizados</span><h2>Galeria</h2><p>Ambientes que saíram do papel. Clique nas fotos para ampliar.</p></div><div class="filters" role="group" aria-label="Filtrar projetos"><button aria-pressed="true" data-f="Destaques">Destaques</button><button aria-pressed="false" data-f="Interiores">Interiores</button><button aria-pressed="false" data-f="Paisagismo">Paisagismo</button><button aria-pressed="false" data-f="Desenhos">Desenhos</button></div><div class="cards" id="cards"></div></div></section>
<section class="sec tight"><div class="wrap two"><div><span class="lab ac lab-line">Do papel ao concreto</span><h2>Arraste e veja o croqui virar obra</h2><p class="lead">Compare o estudo preliminar com o resultado construído.</p></div><div class="compare" id="compare"><img src="https://res.cloudinary.com/p2srhjwx/image/upload/v1792125087/Via_Etilica-2.jpg" alt="Ambiente construído"><img class="top-l" id="cmpTop" src="https://res.cloudinary.com/p2srhjwx/image/upload/v1792125056/corte001_projeto_Via_Etilica_-_Copia.jpg" alt="Croqui do ambiente"><span class="cl l">Croqui</span><span class="cl r">Construído</span><div class="handle" id="cmpHandle" role="slider" tabindex="0" aria-valuemin="0" aria-valuemax="100" aria-valuenow="50"></div></div></div></section>
<section class="sec alt tight" id="servicos"><div class="wrap"><div class="head-2"><span class="lab ac lab-line">Serviços</span><h2>Do estudo à última luminária</h2><p>Você contrata só a etapa que precisa ou o pacote completo.</p></div><div class="grid-4"><article class="svc"><h3>Projeto residencial</h3><p>Casas e sobrados, do estudo ao executivo.</p></article><article class="svc"><h3>Reforma e interiores</h3><p>Layout, marcenaria, iluminação e revestimentos.</p></article><article class="svc"><h3>Espaços comerciais</h3><p>Lojas, cafés e consultórios com identidade própria.</p></article><article class="svc"><h3>Consultoria avulsa</h3><p>Conversa e croquis rápidos para destravar decisões.</p></article></div><ol class="etapas"><li><span>01</span>Conversa inicial</li><li><span>02</span>Estudo preliminar</li><li><span>03</span>Anteprojeto e 3D</li><li><span>04</span>Projeto executivo</li><li><span>05</span>Obra acompanhada</li></ol></div></section>
<section class="sec" id="contato"><div class="wrap contact"><div><span class="lab ac lab-line">Contato</span><h2>Vamos tomar um café e desenhar a primeira ideia juntos?</h2><p class="lead">Conte o que quer construir ou reformar e envie fotos e documentos pela área do cliente.</p><div class="faq"><details><summary>Quanto custa um projeto?</summary><p>O valor depende da área, da complexidade e das etapas contratadas. Enviamos uma proposta detalhada após receber sua solicitação.</p></details><details><summary>Em quanto tempo fica pronto?</summary><p>O prazo depende do porte da obra e das etapas contratadas.</p></details></div></div><div class="sheet pad-lg"><span class="lab ac">Fale com o escritório</span><ul class="cinfo"><li><b>contato@umpedeideia.com.br</b><span>Resposta em até 1 dia útil</span></li><li><b>(11) 98291-6734</b><span>WhatsApp · seg a sex, 9h às 18h</span></li><li><b>Santana · Zona Norte de São Paulo/SP</b><span>Visitas com hora marcada</span></li></ul><a class="btn wide lg" href="#acesso">Pedir meu orçamento</a></div></div></section>
<section class="sec tight alt" id="acesso"><div class="wrap auth-grid"><div><span class="lab ac lab-line">Área do cliente</span><h2>Acompanhe seu projeto em cada etapa.</h2><p class="lead">Entre com seu e-mail e senha ou crie uma conta para enviar solicitações e acompanhar o retorno da equipe.</p><p class="hint">Os dados são registrados no sistema, não apenas no navegador.</p></div><div class="sheet pad-lg">
<?php if ($mensagem): ?><p class="msg ok"><?php echo htmlspecialchars($mensagem); ?></p><?php endif; ?>
<?php if ($erro): ?><p class="msg err"><?php echo htmlspecialchars($erro); ?></p><?php endif; ?>
<div class="auth-tabs"><button type="button" class="tab active" data-auth-tab="entrar">Entrar</button><button type="button" class="tab" data-auth-tab="criar">Criar conta</button></div>
<form method="post" id="loginForm" data-auth-form="entrar"><label><span class="lab">E-mail</span><input type="email" name="email" required autocomplete="username" placeholder="voce@email.com"></label><label><span class="lab">Senha</span><span class="pw"><input type="password" name="senha" required autocomplete="current-password" placeholder="Sua senha"><button type="button" class="eye" aria-label="Mostrar senha">◉</button></span></label><button class="btn wide lg" type="submit">Entrar no meu painel</button></form>
<form method="post" id="cadastroForm" data-auth-form="criar" hidden><label><span class="lab">Seu nome completo</span><input name="novoUsuario" required autocomplete="name" placeholder="Nome e sobrenome"></label><label><span class="lab">E-mail</span><input type="email" name="novoEmail" required autocomplete="email" placeholder="voce@email.com"></label><label><span class="lab">Telefone</span><input name="novoTelefone" type="tel" autocomplete="tel" placeholder="(11) 99999-9999"></label><label><span class="lab">Criar senha</span><span class="pw"><input type="password" name="novaSenha" required minlength="6" autocomplete="new-password" placeholder="Mínimo de 6 caracteres"><button type="button" class="eye" aria-label="Mostrar senha">◉</button></span></label><button class="btn wide lg" type="submit">Criar minha conta</button></form>
</div></div></section>
</main>
<footer><div class="wrap footer-grid"><div><a class="brand" href="index.php"><img src="https://res.cloudinary.com/p2srhjwx/image/upload/v1792142855/Logo_Um_P%C3%89_deIDEIA.jpg" alt=""><span><b>Um Pé de IDEIA</b><span class="lab">Arquitetura &amp; Design</span></span></a><p>Croquis à mão, projeto técnico e obra acompanhada de perto.</p></div><div><h4>Navegar</h4><a href="#escritorio">Escritório</a><a href="#equipe">Equipe</a><a href="#projetos">Galeria</a><a href="#servicos">Serviços</a></div><div><h4>Contato</h4><a href="mailto:contato@umpedeideia.com.br">contato@umpedeideia.com.br</a><a href="tel:+5511982916734">(11) 98291-6734</a><a href="https://wa.me/5511982916734" target="_blank" rel="noopener">WhatsApp</a></div></div><div class="wrap end">© <span id="ano">2026</span> Um Pé de IDEIA · Santana, São Paulo</div></footer>
<div class="lb" id="lb" role="dialog" aria-modal="true" aria-label="Visualizador de imagem"><button class="x" id="lbX" aria-label="Fechar">×</button><figure id="lbFig"></figure></div>
<script src="script.js"></script>
</body></html>
