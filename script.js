/* Um Pé de IDEIA — JavaScript do front-end adaptado ao PHP.
   Este arquivo cuida apenas das interações visuais da página inicial.
   Login, cadastro e dados das solicitações são processados pelo PHP/Neon,
   e os anexos são tratados pelo servidor/Supabase — não pelo localStorage.
*/

/* 01. UTILIDADES ========================================================== */

const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];

const esc = s =>
    String(s ?? '').replace(/[&<>"']/g, c =>
        ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[c])
    );

const page = document.body.dataset.page;

const fmtData = t =>
    new Date(t).toLocaleDateString('pt-BR', {
        day: '2-digit',
        month: 'short',
        year: 'numeric'
    });

const fmtHora = t =>
    new Date(t).toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    });

const bytes = n =>
    n < 1024 ? n + ' B'
        : n < 1048576 ? (n / 1024).toFixed(0) + ' KB'
        : (n / 1048576).toFixed(1) + ' MB';

const toast = (txt, ms = 3600) => {
    const el = $('#toast');

    if (!el) return;

    el.innerHTML = txt;
    el.classList.add('show');

    clearTimeout(toast._t);

    toast._t = setTimeout(
        () => el.classList.remove('show'),
        ms
    );
};


/* 02. INTERFACE BASE (todas as páginas) =================================== */

(() => {
    const ano = $('#ano');

    if (ano) {
        ano.textContent = new Date().getFullYear();
    }

    // Cabeçalho com sombra ao rolar.
    const top = $('#top');

    const onScroll = () => {
        if (top) {
            top.classList.toggle('stuck', scrollY > 8);
        }
    };

    addEventListener('scroll', onScroll, { passive: true });

    onScroll();

    // Menu móvel.
    const burger = $('#burger');
    const drawer = $('#drawer');

    if (burger && drawer) {
        const toggle = open => {
            burger.setAttribute('aria-expanded', open);
            drawer.classList.toggle('open', open);

            document.body.style.overflow = open ? 'hidden' : '';
        };

        burger.onclick = () => {
            toggle(burger.getAttribute('aria-expanded') !== 'true');
        };

        $$('a', drawer).forEach(a => {
            a.onclick = () => toggle(false);
        });

        addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                toggle(false);
            }
        });
    }

    // Revelação suave ao rolar.
    const alvos = $$('.rv');

    if (alvos.length && !('IntersectionObserver' in window)) {
        // Navegadores antigos: mostra tudo.
        alvos.forEach(a => a.classList.add('in'));
    } else if (alvos.length) {
        const io = new IntersectionObserver((ents, obs) => {
            ents.forEach((e, i) => {
                if (!e.isIntersecting) return;

                setTimeout(() => {
                    e.target.classList.add('in');
                }, i * 70);

                obs.unobserve(e.target);
            });
        }, {
            rootMargin: '0px 0px -8% 0px',
            threshold: .12
        });

        alvos.forEach(a => io.observe(a));
    }

    // Contadores animados.
    const nums = $$('[data-count]');

    if (nums.length) {
        const io2 = new IntersectionObserver((ents, obs) => {
            ents.forEach(e => {
                if (!e.isIntersecting) return;

                obs.unobserve(e.target);

                const alvo = +e.target.dataset.count;
                const suf = e.target.dataset.suffix || '';
                const dur = 1300;
                const t0 = performance.now();

                const passo = t => {
                    const p = Math.min(1, (t - t0) / dur);

                    const v = Math.round(
                        alvo * (1 - Math.pow(1 - p, 3))
                    );

                    e.target.textContent = v + suf;

                    if (p < 1) {
                        requestAnimationFrame(passo);
                    }
                };

                requestAnimationFrame(passo);
            });
        }, {
            threshold: .4
        });

        nums.forEach(n => io2.observe(n));
    }
})();


/* 03. LIGHTBOX ============================================================ */

const LB = (() => {
    const box = $('#lb');

    if (!box) {
        return {
            open: () => {}
        };
    }

    const fig = $('#lbFig');
    const bX = $('#lbX');
    const bP = $('#lbPrev');
    const bN = $('#lbNext');

    let itens = [];
    let i = 0;

    const icone = `
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

    const pinta = () => {
        const it = itens[i];

        if (!it) return;

        const cap = `
            <figcaption>
                <span class="lab">${esc(it.tag || 'Mídia')}</span>
                · ${esc(it.cap || '')}
            </figcaption>
        `;

        let corpo;

        if (!it.src) {
            corpo = `
                <div style="background:#fff;border-radius:6px;padding:60px;text-align:center">
                    ${icone}
                    <p style="margin-top:14px">
                        Sem pré-visualização para este arquivo.<br>
                        <strong>${esc(it.cap)}</strong>
                    </p>
                </div>
            `;
        } else if (it.kind === 'video') {
            corpo = `
                <video
                    src="${it.src}"
                    controls
                    autoplay
                    playsinline
                ></video>
            `;
        } else if (it.kind === 'pdf') {
            corpo = `
                <iframe
                    src="${it.src}"
                    title="${esc(it.cap)}"
                ></iframe>
            `;
        } else {
            corpo = `
                <img
                    src="${it.src}"
                    alt="${esc(it.cap || '')}"
                >
            `;
        }

        fig.innerHTML = corpo + cap;

        bP.hidden = bN.hidden = itens.length < 2;
    };

    const abre = (lista, idx = 0) => {
        itens = lista;
        i = idx;

        pinta();

        box.classList.add('open');
        document.body.style.overflow = 'hidden';
    };

    const fecha = () => {
        box.classList.remove('open');
        document.body.style.overflow = '';

        setTimeout(() => {
            fig.innerHTML = '';
        }, 300);
    };

    const anda = d => {
        i = (i + d + itens.length) % itens.length;
        pinta();
    };

    bX.onclick = fecha;
    bP.onclick = () => anda(-1);
    bN.onclick = () => anda(1);

    box.onclick = e => {
        if (e.target === box) {
            fecha();
        }
    };

    addEventListener('keydown', e => {
        if (!box.classList.contains('open')) return;

        if (e.key === 'Escape') {
            fecha();
        }

        if (e.key === 'ArrowRight') {
            anda(1);
        }

        if (e.key === 'ArrowLeft') {
            anda(-1);
        }
    });

    return {
        open: abre
    };
})();


/* 04. HOME: FILTROS, GALERIA E COMPARADOR ================================ */

if (page === 'home') {
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

    // Legenda de cada foto pelo nome do arquivo.
    // A mesma foto pode aparecer em mais de uma categoria.
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

    const legendaDe = (src, nome, i) =>
        legendas[decodeURIComponent(src.split('/').pop())]
        || `${nome} ${String(i + 1).padStart(2, '0')}`;

    const cardsRoot = $('#cards');

    categorias.forEach(({ nome, imagens }) => {
        imagens.forEach((src, i) => {
            const legenda = legendaDe(src, nome, i);

            const card = document.createElement('article');

            card.className = 'card';
            card.dataset.cat = nome;

            card.innerHTML = `
                <div
                    class="fig"
                    data-lb="${esc(src)}"
                    data-cap="${esc(legenda)}"
                >
                    ${
                        src
                            ? `<img src="${esc(src)}" alt="${esc(legenda)}" loading="lazy">`
                            : `<span class="slot">
                                   Imagem ${String(i + 1).padStart(2, '0')}
                                   <small>Substitua o link no script.js</small>
                               </span>`
                    }

                    <span class="leg">${esc(legenda)}</span>
                </div>
            `;

            // Fotos em pé ocupam duas linhas do mosaico.
            const img = $('img', card);

            if (img) {
                img.onload = () => {
                    card.classList.toggle(
                        'tall',
                        img.naturalHeight > img.naturalWidth * 1.1
                    );
                };
            }

            cardsRoot.append(card);
        });
    });

    const btns = $$('.filters button');
    const cards = $$('.card');

    const filtra = b => {
        btns.forEach(x => {
            x.setAttribute('aria-pressed', String(x === b));
        });

        cards.forEach(c => {
            c.classList.toggle('hide', c.dataset.cat !== b.dataset.f);
        });

        // A primeira foto da categoria ganha destaque no mosaico.
        cards.forEach(c => c.classList.remove('big'));

        cards.find(c => !c.classList.contains('hide'))?.classList.add('big');
    };

    btns.forEach(b => {
        b.onclick = () => filtra(b);
    });

    filtra(
        btns.find(b => b.getAttribute('aria-pressed') === 'true')
        || btns[0]
    );

    // Abre as imagens da galeria no visualizador.
    $$('.fig[data-lb]:not([data-lb=""])').forEach(f => {
        f.onclick = () => {
            const vis = $$('.card:not(.hide) .fig[data-lb]:not([data-lb=""])');

            LB.open(
                vis.map(v => ({
                    kind: 'image',
                    src: v.dataset.lb,
                    cap: v.dataset.cap,
                    tag: v.closest('.card').dataset.cat
                })),
                vis.indexOf(f)
            );
        };
    });

    // Comparador croqui × obra.
    const cmp = $('#compare');

    if (cmp) {
        const topo = $('#cmpTop');
        const alca = $('#cmpHandle');

        const põe = p => {
            p = Math.max(0, Math.min(100, p));

            topo.style.clipPath = `inset(0 ${100 - p}% 0 0)`;
            alca.style.left = p + '%';

            alca.setAttribute('aria-valuenow', Math.round(p));
        };

        const doEvento = e => {
            põe(
                ((e.clientX - cmp.getBoundingClientRect().left)
                    / cmp.offsetWidth) * 100
            );
        };

        let ativo = false;

        cmp.addEventListener('pointerdown', e => {
            ativo = true;

            cmp.setPointerCapture(e.pointerId);

            doEvento(e);
        });

        cmp.addEventListener('pointermove', e => {
            if (ativo) {
                doEvento(e);
            }
        });

        addEventListener('pointerup', () => {
            ativo = false;
        });

        alca.addEventListener('keydown', e => {
            const v = +alca.getAttribute('aria-valuenow');

            if (e.key === 'ArrowLeft') {
                põe(v - 4);
                e.preventDefault();
            }

            if (e.key === 'ArrowRight') {
                põe(v + 4);
                e.preventDefault();
            }
        });

        põe(50);
    }
}


/* 05. ABAS DE ENTRAR E CRIAR CONTA ======================================= */

/*
   O processamento dos formulários continua no PHP.
   Este trecho controla apenas a exibição das abas.
*/

function mostrarAbaAutenticacao(aba) {
    $$('[data-auth-tab]').forEach(btn => {
        const ativo = btn.dataset.authTab === aba;

        btn.classList.toggle('active', ativo);
        btn.setAttribute('aria-selected', String(ativo));
    });

    $$('[data-auth-panel]').forEach(painel => {
        painel.hidden = painel.dataset.authPanel !== aba;
    });

    $$('[data-auth-form]').forEach(form => {
        form.hidden = form.dataset.authForm !== aba;
    });
}

$$('[data-auth-tab]').forEach(btn => {
    btn.addEventListener('click', () => {
        mostrarAbaAutenticacao(btn.dataset.authTab);
    });
});

$$('[data-go]').forEach(btn => {
    btn.addEventListener('click', () => {
        const destino = btn.dataset.go;

        if (destino === 'entrar' || destino === 'criar') {
            mostrarAbaAutenticacao(destino);
        }
    });
});

mostrarAbaAutenticacao('entrar');


/* 06. MOSTRAR OU OCULTAR SENHA =========================================== */

$$('.eye').forEach(btn => {
    btn.addEventListener('click', () => {
        const input = btn.parentElement.querySelector('input');

        if (!input) return;

        const mostrar = input.type === 'password';

        input.type = mostrar ? 'text' : 'password';

        btn.classList.toggle('on', mostrar);

        btn.setAttribute(
            'aria-label',
            mostrar ? 'Ocultar senha' : 'Mostrar senha'
        );
    });
});
