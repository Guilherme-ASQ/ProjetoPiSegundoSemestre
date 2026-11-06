/* Um Pé de IDEIA - Interatividade Front-end */

const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
  ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const page = document.body.dataset.page;

/* 01. INTERFACE BASE */
(() => {
  const ano = $('#ano');
  if (ano) ano.textContent = new Date().getFullYear();

  const top = $('#top');
  const onScroll = () => top && top.classList.toggle('stuck', scrollY > 8);
  addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  const burger = $('#burger');
  const drawer = $('#drawer');
  if (burger && drawer) {
    const toggle = open => {
      burger.setAttribute('aria-expanded', open);
      drawer.classList.toggle('open', open);
      document.body.style.overflow = open ? 'hidden' : '';
    };
    burger.onclick = () => toggle(burger.getAttribute('aria-expanded') !== 'true');
    $$('a', drawer).forEach(a => a.onclick = () => toggle(false));
    addEventListener('keydown', e => e.key === 'Escape' && toggle(false));
  }

  const alvos = $$('.rv');
  if (alvos.length && !('IntersectionObserver' in window)) {
    alvos.forEach(a => a.classList.add('in'));
  } else if (alvos.length) {
    const io = new IntersectionObserver((ents, obs) => {
      ents.forEach((e, i) => {
        if (!e.isIntersecting) return;
        setTimeout(() => e.target.classList.add('in'), i * 70);
        obs.unobserve(e.target);
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: .12 });
    alvos.forEach(a => io.observe(a));
  }

  const nums = $$('[data-count]');
  if (nums.length) {
    const io2 = new IntersectionObserver((ents, obs) => ents.forEach(e => {
      if (!e.isIntersecting) return;
      obs.unobserve(e.target);
      const alvo = +e.target.dataset.count, suf = e.target.dataset.suffix || '';
      const dur = 1300, t0 = performance.now();
      const passo = t => {
        const p = Math.min(1, (t - t0) / dur), v = Math.round(alvo * (1 - Math.pow(1 - p, 3)));
        e.target.textContent = v + suf;
        if (p < 1) requestAnimationFrame(passo);
      };
      requestAnimationFrame(passo);
    }), { threshold: .4 });
    nums.forEach(n => io2.observe(n));
  }
})();

/* 02. LIGHTBOX */
const LB = (() => {
  const box = $('#lb');
  if (!box) return { open: () => {} };
  const fig = $('#lbFig'), bX = $('#lbX'), bP = $('#lbPrev'), bN = $('#lbNext');
  let itens = [], i = 0;

  const pinta = () => {
    const it = itens[i];
    if (!it) return;
    const cap = `<figcaption><span class="lab">${esc(it.tag || 'Mídia')}</span> ${esc(it.cap || '')}</figcaption>`;
    let corpo = `<img src="${esc(it.src)}" alt="${esc(it.cap || '')}">`;
    fig.innerHTML = corpo + cap;
    bP.hidden = bN.hidden = itens.length < 2;
  };

  const abre = (lista, idx = 0) => {
    itens = lista; i = idx; pinta();
    box.classList.add('open');
    document.body.style.overflow = 'hidden';
  };

  const fecha = () => {
    box.classList.remove('open');
    document.body.style.overflow = '';
    setTimeout(() => fig.innerHTML = '', 300);
  };

  const anda = d => { i = (i + d + itens.length) % itens.length; pinta(); };
  bX.onclick = fecha;
  bP.onclick = () => anda(-1);
  bN.onclick = () => anda(1);
  box.onclick = e => e.target === box && fecha();
  addEventListener('keydown', e => {
    if (!box.classList.contains('open')) return;
    if (e.key === 'Escape') fecha();
    if (e.key === 'ArrowRight') anda(1);
    if (e.key === 'ArrowLeft') anda(-1);
  });
  return { open: abre };
})();

/* 03. HOME: FILTROS E COMPARADOR */
if (page === 'home') {
  const categorias = [
    { nome: 'Destaques', imagens: ['https://res.cloudinary.com/p2srhjwx/image/upload/v1792131151/PERDIZES0140.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126245/RMB_7643-HDR.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126246/Caf%C3%A9_sem_a_mesa.jpg'] },
    { nome: 'Salas e Varandas', imagens: ['https://res.cloudinary.com/p2srhjwx/image/upload/v1792126244/20171005_134029.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131660/Cobog%C3%B3_Marcelo1.jpg'] },
    { nome: 'Cozinhas', imagens: ['https://res.cloudinary.com/p2srhjwx/image/upload/v1792132926/RMB_7475-HDR-2.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792133509/PERDIZES0285.jpg'] },
    { nome: 'Banheiros', imagens: ['https://res.cloudinary.com/p2srhjwx/image/upload/v1793916220/RMB_7342-HDR.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793916453/PERDIZES0035.jpg'] },
    { nome: 'Quartos', imagens: ['https://res.cloudinary.com/p2srhjwx/image/upload/v1793918594/Suite_3_sem_logo.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793918499/PERDIZES0009.jpg'] },
    { nome: 'Paisagismo', imagens: ['https://res.cloudinary.com/p2srhjwx/image/upload/v1792134325/Jardim_vertical_1.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792134152/Jardim_Vertical.jpg'] },
    { nome: 'Desenhos', imagens: ['https://res.cloudinary.com/p2srhjwx/image/upload/v1792137326/Vale_do_Anhangaba%C3%BA.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792136630/IMG_1433.png'] }
  ];

  const cardsRoot = $('#cards');
  if (cardsRoot) {
    categorias.forEach(({ nome, imagens }) => {
      imagens.forEach((src, i) => {
        const card = document.createElement('article');
        card.className = 'card';
        card.dataset.cat = nome;
        card.innerHTML = `<div class="fig" data-lb="${esc(src)}" data-cap="${esc(nome)}"><img src="${esc(src)}" alt="${esc(nome)}" loading="lazy"></div>`;
        cardsRoot.append(card);
      });
    });

    const btns = $$('.filters button');
    const cards = $$('.card');
    const filtra = b => {
      btns.forEach(x => x.setAttribute('aria-pressed', String(x === b)));
      cards.forEach(c => c.classList.toggle('hide', c.dataset.cat !== b.dataset.f));
      cards.forEach(c => c.classList.remove('big'));
      cards.find(c => !c.classList.contains('hide'))?.classList.add('big');
    };
    btns.forEach(b => b.onclick = () => filtra(b));
    if (btns.length) filtra(btns.find(b => b.getAttribute('aria-pressed') === 'true') || btns[0]);

    $$('.fig[data-lb]').forEach(f => {
      f.onclick = () => {
        const vis = $$('.card:not(.hide) .fig[data-lb]');
        LB.open(vis.map(v => ({ kind: 'image', src: v.dataset.lb, cap: v.dataset.cap, tag: v.closest('.card').dataset.cat })), vis.indexOf(f));
      };
    });
  }

  const cmp = $('#compare');
  if (cmp) {
    const topo = $('#cmpTop'), alca = $('#cmpHandle');
    const põe = p => {
      p = Math.max(0, Math.min(100, p));
      topo.style.clipPath = `inset(0 ${100 - p}% 0 0)`;
      alca.style.left = p + '%';
      alca.setAttribute('aria-valuenow', Math.round(p));
    };
    const doEvento = e => põe(((e.clientX - cmp.getBoundingClientRect().left) / cmp.offsetWidth) * 100);
    let ativo = false;
    cmp.addEventListener('pointerdown', e => { ativo = true; cmp.setPointerCapture(e.pointerId); doEvento(e); });
    cmp.addEventListener('pointermove', e => ativo && doEvento(e));
    addEventListener('pointerup', () => ativo = false);
    põe(50);
  }
}

/* 04. ABAS E OLHO DE SENHA */
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
  btn.addEventListener('click', () => mostrarAbaAutenticacao(btn.dataset.authTab));
});

$$('[data-go]').forEach(btn => {
  btn.addEventListener('click', () => {
    const destino = btn.dataset.go;
    if (destino === 'entrar' || destino === 'criar') mostrarAbaAutenticacao(destino);
  });
});

$$('.eye').forEach(btn => {
  btn.addEventListener('click', () => {
    const input = btn.parentElement.querySelector('input');
    if (!input) return;
    const mostrar = input.type === 'password';
    input.type = mostrar ? 'text' : 'password';
    btn.classList.toggle('on', mostrar);
    btn.setAttribute('aria-label', mostrar ? 'Ocultar senha' : 'Mostrar senha');
  });
});
