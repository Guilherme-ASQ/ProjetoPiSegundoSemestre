
/* ==========================================================================
   Um Pé de IDEIA — JavaScript adaptado para o sistema PHP

   Este arquivo cuida das interações visuais da página inicial:
   01. Utilidades
   02. Interface base
   03. Lightbox
   04. Galeria, filtros e comparador
   05. Abas de login e cadastro
   06. Mostrar e ocultar senha

   IMPORTANTE:
   Login, cadastro, sessões e dados devem ser processados pelo PHP.
   Os dados dos usuários ficam no PostgreSQL (Neon).
   Os anexos são tratados pelo servidor e pelo Supabase.
   ========================================================================== */


/* 01. UTILIDADES ========================================================== */

const $ = (seletor, raiz = document) =>
  raiz.querySelector(seletor);

const $$ = (seletor, raiz = document) =>
  [...raiz.querySelectorAll(seletor)];

const esc = texto =>
  String(texto ?? '').replace(/[&<>"']/g, caractere =>
    ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    }[caractere])
  );

const page = document.body.dataset.page;


/* Mensagens temporárias */

const toast = (texto, milissegundos = 3600) => {
  const elemento = $('#toast');

  if (!elemento) return;

  elemento.innerHTML = texto;
  elemento.classList.add('show');

  clearTimeout(toast._temporizador);

  toast._temporizador = setTimeout(() => {
    elemento.classList.remove('show');
  }, milissegundos);
};


/* 02. INTERFACE BASE ====================================================== */

(() => {

  /* Atualiza automaticamente o ano do rodapé */

  const ano = $('#ano');

  if (ano) {
    ano.textContent = new Date().getFullYear();
  }


  /* Cabeçalho com sombra ao rolar a página */

  const topo = $('#top');

  const aoRolar = () => {
    if (topo) {
      topo.classList.toggle('stuck', window.scrollY > 8);
    }
  };

  window.addEventListener('scroll', aoRolar, {
    passive: true
  });

  aoRolar();


  /* Menu para dispositivos móveis */

  const botaoMenu = $('#burger');
  const menuMovel = $('#drawer');

  if (botaoMenu && menuMovel) {

    const alternarMenu = abrir => {
      botaoMenu.setAttribute(
        'aria-expanded',
        String(abrir)
      );

      menuMovel.classList.toggle('open', abrir);

      document.body.style.overflow =
        abrir ? 'hidden' : '';
    };

    botaoMenu.onclick = () => {
      const estaAberto =
        botaoMenu.getAttribute('aria-expanded') === 'true';

      alternarMenu(!estaAberto);
    };

    $$('a', menuMovel).forEach(link => {
      link.onclick = () => alternarMenu(false);
    });

    window.addEventListener('keydown', evento => {
      if (evento.key === 'Escape') {
        alternarMenu(false);
      }
    });
  }


  /* Animações suaves dos elementos durante a rolagem */

  const alvos = $$('.rv');

  if (
    alvos.length &&
    !('IntersectionObserver' in window)
  ) {

    /* Navegadores sem suporte: mostra todos os elementos */

    alvos.forEach(elemento => {
      elemento.classList.add('in');
    });

  } else if (alvos.length) {

    const observador = new IntersectionObserver(
      (entradas, observadorAtual) => {

        entradas.forEach((entrada, indice) => {

          if (!entrada.isIntersecting) return;

          setTimeout(() => {
            entrada.target.classList.add('in');
          }, indice * 70);

          observadorAtual.unobserve(entrada.target);
        });

      },
      {
        rootMargin: '0px 0px -8% 0px',
        threshold: 0.12
      }
    );

    alvos.forEach(elemento => {
      observador.observe(elemento);
    });
  }


  /* Contadores animados */

  const numeros = $$('[data-count]');

  if (
    numeros.length &&
    'IntersectionObserver' in window
  ) {

    const observadorNumeros = new IntersectionObserver(
      (entradas, observadorAtual) => {

        entradas.forEach(entrada => {

          if (!entrada.isIntersecting) return;

          observadorAtual.unobserve(entrada.target);

          const alvo = Number(
            entrada.target.dataset.count
          );

          const sufixo =
            entrada.target.dataset.suffix || '';

          const duracao = 1300;
          const inicio = performance.now();

          const animar = tempoAtual => {

            const progresso = Math.min(
              1,
              (tempoAtual - inicio) / duracao
            );

            const valor = Math.round(
              alvo * (1 - Math.pow(1 - progresso, 3))
            );

            entrada.target.textContent =
              valor + sufixo;

            if (progresso < 1) {
              requestAnimationFrame(animar);
            }
          };

          requestAnimationFrame(animar);
        });
      },
      {
        threshold: 0.4
      }
    );

    numeros.forEach(numero => {
      observadorNumeros.observe(numero);
    });

  } else {

    /* Alternativa para navegadores sem IntersectionObserver */

    numeros.forEach(numero => {
      numero.textContent =
        (numero.dataset.count || '0') +
        (numero.dataset.suffix || '');
    });
  }

})();


/* 03. LIGHTBOX ============================================================ */

/*
  O lightbox abre uma imagem em destaque.
  Também permite avançar e voltar entre imagens.
*/

const LB = (() => {

  const caixa = $('#lb');

  if (!caixa) {
    return {
      open: () => {}
    };
  }

  const figura = $('#lbFig');
  const botaoFechar = $('#lbX');
  const botaoAnterior = $('#lbPrev');
  const botaoProximo = $('#lbNext');

  let itens = [];
  let indiceAtual = 0;


  /* Ícone exibido quando não existe pré-visualização */

  const iconeDocumento = `
    <svg
      viewBox="0 0 24 24"
      width="60"
      height="60"
      fill="none"
      stroke="#ffa923"
      stroke-width="1.2"
    >
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
      <path d="M14 2v6h6"/>
    </svg>
  `;


  /* Desenha o item atualmente selecionado */

  const desenhar = () => {

    const item = itens[indiceAtual];

    if (!item) return;

    const legenda = `
      <figcaption>
        <span class="lab">
          ${esc(item.tag || 'Mídia')}
        </span>
        · ${esc(item.cap || '')}
      </figcaption>
    `;

    let conteudo;

    if (!item.src) {

      conteudo = `
        <div
          style="
            background:#fff;
            border-radius:6px;
            padding:60px;
            text-align:center
          "
        >
          ${iconeDocumento}

          <p style="margin-top:14px">
            Sem pré-visualização para este arquivo.
            <br>
            <strong>${esc(item.cap || '')}</strong>
          </p>
        </div>
      `;

    } else if (item.kind === 'video') {

      conteudo = `
        <video
          src="${esc(item.src)}"
          controls
          playsinline
        ></video>
      `;

    } else if (item.kind === 'pdf') {

      conteudo = `
        <iframe
          src="${esc(item.src)}"
          title="${esc(item.cap || '')}"
        ></iframe>
      `;

    } else {

      conteudo = `
        <img
          src="${esc(item.src)}"
          alt="${esc(item.cap || '')}"
        >
      `;
    }

    figura.innerHTML = conteudo + legenda;

    if (botaoAnterior) {
      botaoAnterior.hidden = itens.length < 2;
    }

    if (botaoProximo) {
      botaoProximo.hidden = itens.length < 2;
    }
  };


  /* Abre o visualizador */

  const abrir = (lista, indice = 0) => {

    if (!lista || lista.length === 0) return;

    itens = lista;

    indiceAtual = Math.max(
      0,
      Math.min(indice, itens.length - 1)
    );

    desenhar();

    caixa.classList.add('open');

    document.body.style.overflow = 'hidden';
  };


  /* Fecha o visualizador */

  const fechar = () => {

    caixa.classList.remove('open');

    document.body.style.overflow = '';

    setTimeout(() => {
      figura.innerHTML = '';
    }, 300);
  };


  /* Avança ou volta entre os itens */

  const navegar = direcao => {

    if (itens.length < 2) return;

    indiceAtual =
      (indiceAtual + direcao + itens.length) %
      itens.length;

    desenhar();
  };


  /* Botões de controle */

  if (botaoFechar) {
    botaoFechar.onclick = fechar;
  }

  if (botaoAnterior) {
    botaoAnterior.onclick = () => navegar(-1);
  }

  if (botaoProximo) {
    botaoProximo.onclick = () => navegar(1);
  }


  /* Fecha ao clicar no fundo */

  caixa.onclick = evento => {
    if (evento.target === caixa) {
      fechar();
    }
  };


  /* Teclas de atalho */

  window.addEventListener('keydown', evento => {

    if (!caixa.classList.contains('open')) return;

    if (evento.key === 'Escape') {
      fechar();
    }

    if (evento.key === 'ArrowRight') {
      navegar(1);
    }

    if (evento.key === 'ArrowLeft') {
      navegar(-1);
    }
  });


  return {
    open: abrir
  };

})();


/* 04. PÁGINA INICIAL: GALERIA, FILTROS E COMPARADOR ======================= */

if (page === 'home') {

  /*
    As imagens e categorias abaixo foram mantidas do JavaScript original.
    Se quiser trocar uma imagem, altere o endereço correspondente.
  */

  const categorias = [

    {
      nome: 'Destaques',
      imagens: [
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131151/PERDIZES0140.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126245/RMB_7643-HDR.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126246/Caf%C3%A9_sem_a_mesa.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126244/20171005_134029.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126244/RMB_7622-HDR.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126244/RMB_7744-HDR.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126241/RMB_7732-HDR.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126240/RMB_7460-HDR.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126238/Marcelo_banco_apto1.jpg'
      ]
    },

    {
      nome: 'Salas e Varandas',
      imagens: [
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126244/20171005_134029.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126246/Caf%C3%A9_sem_a_mesa.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131660/Caf%C3%A9_sem_a_mesa2.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131660/Cobog%C3%B3_Marcelo1.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131656/H_M_Jantar_aberto_dia.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126238/Marcelo_banco_apto1.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131658/Marcelo_SALA.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131151/PERDIZES0140.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131660/RMB_7550-HDR.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131661/RMB_7562-HDR.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131661/RMB_7580-HDR.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131665/RMB_7598-HDR.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131662/RMB_7640-HDR.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131665/RMB_7662-HDR.jpg'
      ]
    },

    {
      nome: 'Cozinhas',
      imagens: [
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792132926/RMB_7475-HDR-2.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792132909/RMB_7457-HDR.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792133509/PERDIZES0285.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792133526/PERDIZES0255.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792133508/PERDIZES0288.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792132909/RMB_7466-HDR.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792132908/RMB_7445-HDR.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792132907/RMB_7439-HDR.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792132907/RMB_7415-HDR-2.jpg'
      ]
    },

    {
      nome: 'Paisagismo',
      imagens: [
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792134325/Jardim_vertical_1.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792134390/Port%C3%A3o_corredor.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792134152/Jardim_Vertical.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792134152/Corredor_entrada.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792134143/20151015_114810.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792134129/PERDIZES0254.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126238/Marcelo_banco_apto1.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131661/RMB_7580-HDR.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126245/RMB_7643-HDR.jpg'
      ]
    },

    {
      nome: 'Desenhos',
      imagens: [
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792137326/Vale_do_Anhangaba%C3%BA.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792136630/IMG_1433.png',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792136650/IMG_1437.png',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792136651/001_projeto_Via_Et%C3%ADlica.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792136643/IMG_1435.png',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792136644/IMG_1436.png',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792137060/Hall_1_sem_logo.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792137055/PERDIZES0137.jpg',
        'https://res.cloudinary.com/p2srhjwx/image/upload/v1792137048/PERDIZES0134.jpg'
      ]
    }

  ];


  /* Legendas das imagens */

  const legendas = {

    'PERDIZES0140.jpg': 'Living com poltrona Eames e tijolo aparente',
    'RMB_7643-HDR.jpg': 'Living integrado a um jardim tropical',
    'Café_sem_a_mesa.jpg': 'Estar com poltronas de couro e adega embutida',
    '20171005_134029.jpg': 'Mesa de jantar posta junto à varanda envidraçada',
    'RMB_7622-HDR.jpg': 'Varanda com mesa redonda e ladrilho hexagonal',
    'RMB_7744-HDR.jpg': 'Home office para dois em marcenaria amadeirada',
    'RMB_7732-HDR.jpg': 'Lavabo azul com bancada de madeira maciça',
    'RMB_7460-HDR.jpg': 'Cozinha com ilha gourmet e coifa central',
    'Marcelo_banco_apto1.jpg': 'Varanda lounge com futon e grafite urbano',
    'Café_sem_a_mesa2.jpg': 'Bar e adega com pendentes de filamento',
    'Cobogó_Marcelo1.jpg': 'Parede de cobogós iluminada à noite',
    'H_M_Jantar_aberto_dia.jpg': 'Jantar em madeira com parede de tijolinho',
    'Marcelo_SALA.jpg': 'Escada com guarda-corpo de vidro e ripado iluminado',
    'RMB_7550-HDR.jpg': 'Estar e jantar com poltronas amarelas',
    'RMB_7562-HDR.jpg': 'Lareira revestida em pedra e parede 3D',
    'RMB_7580-HDR.jpg': 'Varanda integrada com jardim vertical',
    'RMB_7598-HDR.jpg': 'Varanda com mesa redonda e vista da cidade',
    'RMB_7640-HDR.jpg': 'Ripado de madeira e estante vazada no living',
    'RMB_7662-HDR.jpg': 'Painel de TV amadeirado e piso que vira ladrilho',
    'RMB_7475-HDR-2.jpg': 'Ilha branca com pendentes de filamento',
    'RMB_7457-HDR.jpg': 'Ilha com cooktop e nicho de café iluminado',
    'PERDIZES0285.jpg': 'Cozinha azul com torre de fornos',
    'PERDIZES0255.jpg': 'Copa com mesa azul integrada à bancada',
    'PERDIZES0288.jpg': 'Cozinha em L com armários azuis',
    'RMB_7466-HDR.jpg': 'Cozinha cinza com ilha e TV embutida',
    'RMB_7445-HDR.jpg': 'Nicho iluminado entre armários cinza',
    'RMB_7439-HDR.jpg': 'Cristaleira, ilha e eletros em inox',
    'RMB_7415-HDR-2.jpg': 'Ilha para refeições com cadeiras de palhinha',
    'Jardim_vertical_1.jpg': 'Jardim vertical em painel de madeira',
    'Portão_corredor.jpg': 'Portão-jardim com antúrios e folhagens',
    'Jardim_Vertical.jpg': 'Jardim vertical em pátio azul',
    'Corredor_entrada.jpg': 'Corredor de entrada com floreiras e parede de pedra',
    '20151015_114810.jpg': 'Pergolado com floreira suspensa',
    'PERDIZES0254.jpg': 'Varanda de leitura com jardim vertical',
    'Vale_do_Anhangabaú.jpg': 'Vale do Anhangabaú, croqui a nanquim',
    'IMG_1433.png': 'Croqui de garagem com Jeep e tijolo aparente',
    'IMG_1437.png': 'Igreja Matriz de Monte Santo (MG) a nanquim',
    '001_projeto_Via_Etílica.jpg': 'Croqui da loja Via Etílica vista da rua',
    'IMG_1435.png': 'Casa com volume suspenso e árvore, croqui',
    'IMG_1436.png': 'Residência em balanço junto à rua em curva',
    'Hall_1_sem_logo.jpg': 'Hall com mural ilustrado à mão',
    'PERDIZES0137.jpg': 'Hall revestido de ilustração urbana',
    'PERDIZES0134.jpg': 'Porta ilustrada abrindo para o hall'

  };


  /* Encontra a legenda correspondente ao nome da imagem */

  const legendaDe = (origem, categoria, indice) => {

    const nomeArquivo =
      decodeURIComponent(origem.split('/').pop());

    return legendas[nomeArquivo] ||
      `${categoria} ${String(indice + 1).padStart(2, '0')}`;
  };


  /* Cria os cartões da galeria */

  const raizCartoes = $('#cards');

  if (raizCartoes) {

    categorias.forEach(({ nome, imagens }) => {

      imagens.forEach((origem, indice) => {

        const legenda = legendaDe(
          origem,
          nome,
          indice
        );

        const cartao = document.createElement('article');

        cartao.className = 'card';
        cartao.dataset.cat = nome;

        cartao.innerHTML = `
          <div
            class="fig"
            data-lb="${esc(origem)}"
            data-cap="${esc(legenda)}"
          >
            <img
              src="${esc(origem)}"
              alt="${esc(legenda)}"
              loading="lazy"
            >

            <span class="leg">
              ${esc(legenda)}
            </span>
          </div>
        `;

        const imagem = $('img', cartao);

        if (imagem) {
          imagem.onload = () => {
            cartao.classList.toggle(
              'tall',
              imagem.naturalHeight > imagem.naturalWidth * 1.1
            );
          };
        }

        raizCartoes.append(cartao);
      });

    });


    /* Filtros da galeria */

    const botoesFiltro = $$('.filters button');
    const cartoes = $$('.card');

    const filtrar = botao => {

      if (!botao) return;

      botoesFiltro.forEach(botaoAtual => {
        botaoAtual.setAttribute(
          'aria-pressed',
          String(botaoAtual === botao)
        );
      });

      cartoes.forEach(cartao => {
        cartao.classList.toggle(
          'hide',
          cartao.dataset.cat !== botao.dataset.f
        );
      });

      cartoes.forEach(cartao => {
        cartao.classList.remove('big');
      });

      cartoes
        .find(cartao => !cartao.classList.contains('hide'))
        ?.classList.add('big');
    };


    botoesFiltro.forEach(botao => {
      botao.onclick = () => filtrar(botao);
    });

    filtrar(
      botoesFiltro.find(
        botao => botao.getAttribute('aria-pressed') === 'true'
      ) || botoesFiltro[0]
    );


    /* Abre a galeria em destaque */

    $$('.fig[data-lb]:not([data-lb=""])').forEach(figura => {

      figura.onclick = () => {

        const imagensVisiveis = $$(
          '.card:not(.hide) .fig[data-lb]:not([data-lb=""])'
        );

        const lista = imagensVisiveis.map(imagem => ({
          kind: 'image',
          src: imagem.dataset.lb,
          cap: imagem.dataset.cap,
          tag: imagem.closest('.card').dataset.cat
        }));

        LB.open(
          lista,
          imagensVisiveis.indexOf(figura)
        );
      };

    });

  }


  /* Comparador croqui × obra */

  const comparador = $('#compare');

  if (comparador) {

    const imagemSuperior = $('#cmpTop');
    const alca = $('#cmpHandle');

    if (imagemSuperior && alca) {

      const posicionar = porcentagem => {

        porcentagem = Math.max(
          0,
          Math.min(100, porcentagem)
        );

        imagemSuperior.style.clipPath =
          `inset(0 ${100 - porcentagem}% 0 0)`;

        alca.style.left = porcentagem + '%';

        alca.setAttribute(
          'aria-valuenow',
          Math.round(porcentagem)
        );
      };


      const moverPonteiro = evento => {

        const retangulo =
          comparador.getBoundingClientRect();

        if (retangulo.width === 0) return;

        const porcentagem =
          ((evento.clientX - retangulo.left) /
            retangulo.width) * 100;

        posicionar(porcentagem);
      };


      let arrastando = false;

      comparador.addEventListener('pointerdown', evento => {

        arrastando = true;

        comparador.setPointerCapture(evento.pointerId);

        moverPonteiro(evento);
      });

      comparador.addEventListener('pointermove', evento => {

        if (arrastando) {
          moverPonteiro(evento);
        }
      });

      window.addEventListener('pointerup', () => {
        arrastando = false;
      });

      alca.addEventListener('keydown', evento => {

        const valorAtual = Number(
          alca.getAttribute('aria-valuenow')
        );

        if (evento.key === 'ArrowLeft') {
          posicionar(valorAtual - 4);
          evento.preventDefault();
        }

        if (evento.key === 'ArrowRight') {
          posicionar(valorAtual + 4);
          evento.preventDefault();
        }
      });

      posicionar(50);
    }
  }

}


/* 05. ABAS DE ENTRAR E CRIAR CONTA ======================================= */

/*
  Este trecho apenas alterna as abas visuais.
  O PHP continua responsável por receber e processar os formulários.
*/

$$('[data-auth-tab]').forEach(botao => {

  botao.addEventListener('click', () => {

    $$('[data-auth-tab]').forEach(outroBotao => {

      const ativo = outroBotao === botao;

      outroBotao.classList.toggle('active', ativo);

      outroBotao.setAttribute(
        'aria-selected',
        String(ativo)
      );
    });


    $$('[data-auth-form]').forEach(formulario => {

      formulario.hidden =
        formulario.dataset.authForm !==
        botao.dataset.authTab;
    });

  });

});


/* 06. MOSTRAR E OCULTAR SENHA ============================================ */

/*
  O botão de olho altera apenas a visualização da senha.
  Ele não impede nem substitui o envio do formulário ao PHP.
*/

$$('.eye').forEach(botao => {

  botao.addEventListener('click', () => {

    const formulario = botao.closest('form');

    const campoSenha = formulario
      ? formulario.querySelector('input[type="password"], input[type="text"]')
      : null;

    if (!campoSenha) return;

    const mostrar = campoSenha.type === 'password';

    campoSenha.type = mostrar ? 'text' : 'password';

    botao.classList.toggle('on', mostrar);

    botao.setAttribute(
      'aria-label',
      mostrar ? 'Ocultar senha' : 'Mostrar senha'
    );

  });

});
