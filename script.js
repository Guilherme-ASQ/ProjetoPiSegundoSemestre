/* ==========================================================================
   Um Pé de IDEIA · Código Front-End Integrado
   ========================================================================== */

/* 01. UTILIDADES ========================================================== */
const $  = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
  ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const page = document.body.dataset.page;

const fmtData = t => new Date(t).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' });
const fmtHora = t => new Date(t).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' });
const bytes = n => n < 1024 ? n + ' B'
  : n < 1048576 ? (n / 1024).toFixed(0) + ' KB'
  : (n / 1048576).toFixed(1) + ' MB';

const toast = (txt, ms = 3600) => {
  const el = $('#toast'); if (!el) return;
  el.innerHTML = txt;
  el.classList.add('show');
  clearTimeout(toast._t);
  toast._t = setTimeout(() => el.classList.remove('show'), ms);
};

/* 02. INTERFACE BASE (Header, Menu Móvel, Scroll e Animações) ============== */
(() => {
  const ano = $('#ano'); 
  if (ano) ano.textContent = new Date().getFullYear();

  // Cabeçalho fixo com sombra ao rolar
  const top = $('#top');
  const onScroll = () => top && top.classList.toggle('stuck', scrollY > 8);
  addEventListener('scroll', onScroll, { passive: true }); 
  onScroll();

  // Menu móvel
  const burger = $('#burger'), drawer = $('#drawer');
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

  // Revelação suave ao rolar
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

  // Contadores animados
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

/* Carrossel de Feedbacks ================================================= */
(() => {
  const track = $('#feedbackTrack'), prev = $('#feedbackPrev'), next = $('#feedbackNext');
  if (!track || !prev || !next) return;
  const cards = $$('.feedback-card', track);
  if (!cards.length) return;
  let atual = 0;
  const dotsBox = $('#feedbackDots');
  const dots = dotsBox ? cards.map((_, i) => {
    const d = document.createElement('button');
    d.type = 'button';
    d.className = 'feedback-dot';
    d.setAttribute('role', 'tab');
    d.setAttribute('aria-label', 'Depoimento ' + (i + 1) + ' de ' + cards.length);
    d.addEventListener('click', () => mostra(i));
    dotsBox.appendChild(d);
    return d;
  }) : [];

  const mostra = indice => {
    atual = (indice + cards.length) % cards.length;
    dots.forEach((d, i) => { d.classList.toggle('is-active', i === atual); d.setAttribute('aria-selected', i === atual ? 'true' : 'false'); });
    cards.forEach((card, i) => {
      card.classList.remove('is-active', 'is-prev', 'is-next');
      card.removeAttribute('aria-current');
      if (i === atual) {
        card.classList.add('is-active');
        card.setAttribute('aria-current', 'true');
      } else if (i === (atual - 1 + cards.length) % cards.length) {
        card.classList.add('is-prev');
      } else if (i === (atual + 1) % cards.length) {
        card.classList.add('is-next');
      }
    });
  };

  prev.addEventListener('click', () => mostra(atual - 1));
  next.addEventListener('click', () => mostra(atual + 1));
  cards.forEach((card, i) => card.addEventListener('click', () => {
    if (card.classList.contains('is-prev') || card.classList.contains('is-next')) mostra(i);
  }));
  track.addEventListener('keydown', e => {
    if (e.key === 'ArrowLeft') { mostra(atual - 1); e.preventDefault(); }
    if (e.key === 'ArrowRight') { mostra(atual + 1); e.preventDefault(); }
  });
  mostra(atual);
})();

/* 03. LIGHTBOX INTERATIVO (Fotos, Vídeos e PDFs) ========================== */
const LB = (() => {
  const box = $('#lb'); if (!box) return { open: () => {} };
  const fig = $('#lbFig'), bX = $('#lbX'), bP = $('#lbPrev'), bN = $('#lbNext');
  let itens = [], i = 0;

  const icone = `<svg viewBox="0 0 24 24" width="60" height="60" fill="none" stroke="#ffa923" stroke-width="1.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>`;

  const pinta = () => {
    const it = itens[i]; if (!it) return;
    const cap = `<figcaption><span class="lab">${esc(it.tag || 'Mídia')}</span> · ${esc(it.cap || '')}</figcaption>`;
    let corpo;
    if (!it.src) corpo = `<div style="background:#fff;border-radius:6px;padding:60px;text-align:center">${icone}
        <p style="margin-top:14px">Sem pré-visualização para este arquivo.<br><strong>${esc(it.cap)}</strong></p></div>`;
    else if (it.kind === 'video') corpo = `<video src="${it.src}" controls autoplay playsinline></video>`;
    else if (it.kind === 'pdf')   corpo = `<iframe src="${it.src}" title="${esc(it.cap)}"></iframe>`;
    else corpo = `<img src="${esc(it.src)}" alt="${esc(it.cap || '')}">`;
    fig.innerHTML = corpo + cap;
    bP.hidden = bN.hidden = itens.length < 2;
  };
  const abre = (lista, idx = 0) => {
    itens = lista; i = idx; pinta();
    box.classList.add('open'); document.body.style.overflow = 'hidden';
  };
  const fecha = () => {
    box.classList.remove('open'); document.body.style.overflow = '';
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

/* 04. HOME: filtros, galeria completa e comparador ======================= */
if (page === 'home') {
  const categorias = [
    { nome: 'Destaques', imagens: ['https://res.cloudinary.com/p2srhjwx/image/upload/v1792131151/PERDIZES0140.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126245/RMB_7643-HDR.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126246/Caf%C3%A9_sem_a_mesa.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126244/20171005_134029.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126244/RMB_7622-HDR.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126244/RMB_7744-HDR.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126241/RMB_7732-HDR.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126240/RMB_7460-HDR.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126238/Marcelo_banco_apto1.jpg'] },
    { nome: 'Salas e Varandas', imagens: ['https://res.cloudinary.com/p2srhjwx/image/upload/v1792126244/20171005_134029.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126246/Caf%C3%A9_sem_a_mesa.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131660/Caf%C3%A9_sem_a_mesa2.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131660/Cobog%C3%B3_Marcelo1.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131656/H_M_Jantar_aberto_dia.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793925000/H_M_Jantar_fechado_dia_COM_LOGO.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126238/Marcelo_banco_apto1.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131658/Marcelo_SALA.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131151/PERDIZES0140.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131660/RMB_7550-HDR.jpg',  'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131661/RMB_7562-HDR.jpg',  'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131661/RMB_7580-HDR.jpg',  'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131665/RMB_7598-HDR.jpg',  'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131662/RMB_7640-HDR.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131665/RMB_7662-HDR.jpg' ] },
    { nome: 'Cozinhas', imagens: ['https://res.cloudinary.com/p2srhjwx/image/upload/v1792132926/RMB_7475-HDR-2.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792132909/RMB_7457-HDR.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792133509/PERDIZES0285.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792133526/PERDIZES0255.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792133508/PERDIZES0288.jpg','https://res.cloudinary.com/p2srhjwx/image/upload/v1792132909/RMB_7466-HDR.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792132908/RMB_7445-HDR.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792132907/RMB_7439-HDR.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792132907/RMB_7415-HDR-2.jpg'] },
    { nome: 'Banheiros', imagens: ['https://res.cloudinary.com/p2srhjwx/image/upload/v1793916220/RMB_7342-HDR.jpg' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793916220/RMB_7339-HDR.jpg' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793916221/RMB_7403-HDR.jpg' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793916232/20170818_190743.jpg' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793916235/20170725_173927.jpg' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793916453/PERDIZES0035.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793916917/PERDIZES0319.jpg' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793916637/PERDIZES0106.jpg' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793916524/PERDIZES0093.jpg'] },
    { nome: 'Quartos', imagens: ['https://res.cloudinary.com/p2srhjwx/image/upload/v1793918594/Suite_3_sem_logo.jpg' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793918506/Closet_1_sem_logo.jpg' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793918499/PERDIZES0009.jpg' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793918486/PERDIZES0026.jpg' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793918483/RMB_7375-HDR.jpg' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793918482/RMB_7372-HDR.jpg' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793918481/RMB_7366-HDR.jpg' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793918480/RMB_7363-HDR.jpg' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793918466/RMB_7309-HDR.jpg' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793918465/RMB_7297-HDR.jpg' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793918464/RMB_7288-HDR.jpg' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793918463/RMB_7273-HDR.jpg' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793918462/PERDIZES0075.jpg' ,'https://res.cloudinary.com/p2srhjwx/image/upload/v1793918450/RMB_7393-HDR.jpg' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793918449/H_M_%C3%81rvore_Brinquedoteca.jpg' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793918449/H_M_%C3%81rvore_Brinquedoteca_2.jpg' ] },
    { nome: 'Paisagismo', imagens: [ 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792134325/Jardim_vertical_1.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792134390/Port%C3%A3o_corredor.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792134152/Jardim_Vertical.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792134152/Corredor_entrada.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792134143/20151015_114810.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792134129/PERDIZES0254.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126238/Marcelo_banco_apto1.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131661/RMB_7580-HDR.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126245/RMB_7643-HDR.jpg',] },
    { nome: 'Desenhos', imagens: ['https://res.cloudinary.com/p2srhjwx/image/upload/v1792137326/Vale_do_Anhangaba%C3%BA.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792136630/IMG_1433.png', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792136650/IMG_1437.png', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792136651/001_projeto_Via_Et%C3%ADlica.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792136643/IMG_1435.png', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792136644/IMG_1436.png', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792137060/Hall_1_sem_logo.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792137055/PERDIZES0137.jpg', 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792137048/PERDIZES0134.jpg' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793925401/Perspectiva_2.jpg' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793925576/IMG_002.jpg' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793925644/IMG.jpg' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792136630/IMG_1431.png' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792136629/IMG_1430.png' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793926315/IMG_011a.jpg' , 'https://res.cloudinary.com/p2srhjwx/image/upload/v1793926315/IMG_013a.jpg' ] }
  ];

  const legendas = {
    'PERDIZES0140.jpg': 'Living com poltrona Eames e tijolo aparente',
    'RMB_7643-HDR.jpg': 'Living integrado a um jardim tropical',
    'Café_sem_a_mesa.jpg': 'Estar com poltronas de couro e adega embutida',
    '20171005_134029.jpg': 'Mesa de jantar posta junto à varanda envidraçada',
    'RMB_7622-HDR.jpg': 'Varanda com mesa redonda e ladrilho hexagonal',
    'RMB_7744-HDR.jpg': 'Home office para dois em marcenaria amadeirada',
    'RMB_7732-HDR.jpg': 'Lavabo azul com bancada de madeira maciça',
    'Suite_3_sem_logo.jpg': 'Suíte com cabeceira escura, cortinas amplas e iluminação indireta',
    'Closet_1_sem_logo.jpg': 'Portas espelhadas e marcenaria planejada',
    'PERDIZES0009.jpg': 'Cama de casal, cabeceira estofada e piso de madeira',
    'PERDIZES0026.jpg': 'Suíte com marcenaria sob medida',
    'RMB_7375-HDR.jpg': 'Quarto infantil com cama de estampa divertida e parede ilustrada',
    'RMB_7372-HDR.jpg': 'Bancada de estudos e armários coloridos',
    'RMB_7366-HDR.jpg': 'Bancada de estudos integrada à marcenaria colorida do quarto infantil',
    'RMB_7363-HDR.jpg': 'Camas baixas, painel de madeira e armários coloridos',
    'RMB_7309-HDR.jpg': 'Suíte com cabeceira estofada, painel ripado e armários espelhados',
    'RMB_7297-HDR.jpg': 'Cama em tons suaves e armários espelhados',
    'RMB_7288-HDR.jpg': 'Quarto de casal com cabeceira estofada e painel ripado de madeira',
    'RMB_7273-HDR.jpg': 'Suíte com painel ripado, closet espelhado e cortinas do piso ao teto',
    'PERDIZES0075.jpg': 'Quarto infantil claro com cômoda branca e armário espelhado',
    'RMB_7393-HDR.jpg': 'Camas e marcenaria azul e amadeirada',
    'H_M_Árvore_Brinquedoteca.jpg': 'Quarto infantil com mural de árvore, nichos e cama integrada',
    'H_M_Árvore_Brinquedoteca_2.jpg': 'Quarto infantil com cama, armários coloridos e iluminação embutida',
    'RMB_7342-HDR.jpg': 'Box em mármore, nicho embutido e metais acobreados',
    'RMB_7339-HDR.jpg': 'Bancada ampla, espelho e box revestido em mármore claro',
    'RMB_7403-HDR.jpg': 'Gabinete cinza e revestimento azul no box',
    '20170818_190743.jpg': 'Lavabo com bancada de madeira, revestimento texturizado e iluminação indireta',
    '20170725_173927.jpg': 'Bancada de mármore escuro com espelho iluminado e revestimento bege',
    'PERDIZES0035.jpg': 'Bancada escura, gabinete amadeirado e box com revestimento geométrico',
    'PERDIZES0319.jpg': 'Gabinete rosa, bancada branca e parede de pastilhas',
    'PERDIZES0106.jpg': 'Lavabo com bancada de mármore escuro e iluminação indireta',
    'PERDIZES0093.jpg': 'Banheiro claro com bancada branca, gabinete amadeirado e box de vidro',
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
    'IMG_1433.png': 'Garagem com Jeep e tijolo aparente',
    'IMG_1437.png': 'Igreja Matriz de Monte Santo (MG) a nanquim',
    '001_projeto_Via_Etílica.jpg': 'Croqui da loja Via Etílica vista da rua',
    'IMG_1435.png': 'Casa com volume suspenso e árvore, croqui',
    'IMG_1436.png': 'Residência em balanço junto à rua em curva',
    'Perspectiva_2.jpg': 'Sala integrada com planta baixa e perspectiva do ambiente',
    'IMG_002.jpg': 'Pátio com cozinha externa, bancada e balanços',
    'IMG.jpg': 'Cozinha integrada à sala de jantar com mesa e pendentes',
    'IMG_1431.png': 'Sala de estar integrada à cozinha com sofá e bancada',
    'IMG_1430.png': 'Sala de jantar com mesa e estante divisória',
    'IMG_011a.jpg': 'Bar com balcão, banquetas e vista para o estádio',
    'IMG_013a.jpg': 'Interior comercial com balcão, mesas e iluminação',
    'Hall_1_sem_logo.jpg': 'Hall com mural ilustrado à mão',
    'PERDIZES0137.jpg': 'Hall revestido de ilustração urbana',
    'PERDIZES0134.jpg': 'Porta ilustrada abrindo para o hall'
  };

  const legendaDe = (src, nome, i) =>
    legendas[decodeURIComponent(src.split('/').pop())] || `${nome} ${String(i + 1).padStart(2, '0')}`;

  const cardsRoot = $('#cards');
  if (cardsRoot) {
    categorias.forEach(({ nome, imagens }) => imagens.forEach((src, i) => {
      const legenda = legendaDe(src, nome, i);
      const card = document.createElement('article');
      card.className = 'card';
      card.dataset.cat = nome;
      card.innerHTML = `<div class="fig" data-lb="${esc(src)}" data-cap="${esc(legenda)}">
        ${src ? `<img src="${esc(src)}" alt="${esc(legenda)}" loading="lazy">` : `<span class="slot">Imagem ${String(i + 1).padStart(2, '0')}<small>Substitua o link no script.js</small></span>`}
        <span class="leg">${esc(legenda)}</span>
      </div>`;
      
      const img = $('img', card);
      if (img) img.onload = () => card.classList.toggle('tall', img.naturalHeight > img.naturalWidth * 1.1);
      cardsRoot.append(card);
    }));

    const btns = $$('.filters button'), cards = $$('.card');
    const filtra = b => {
      btns.forEach(x => x.setAttribute('aria-pressed', String(x === b)));
      cards.forEach(c => c.classList.toggle('hide', c.dataset.cat !== b.dataset.f));
      cards.forEach(c => c.classList.remove('big'));
      cards.find(c => !c.classList.contains('hide'))?.classList.add('big');
    };
    btns.forEach(b => b.onclick = () => filtra(b));
    if (btns.length) filtra(btns.find(b => b.getAttribute('aria-pressed') === 'true') || btns[0]);

    $$('.fig[data-lb]:not([data-lb=""])').forEach(f => f.onclick = () => {
      const vis = $$('.card:not(.hide) .fig[data-lb]:not([data-lb=""])');
      LB.open(vis.map(v => ({ kind: 'image', src: v.dataset.lb, cap: v.dataset.cap, tag: v.closest('.card').dataset.cat })), vis.indexOf(f));
    });
  }

  // Comparador Croqui × Obra
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
    alca.addEventListener('keydown', e => {
      const v = +alca.getAttribute('aria-valuenow');
      if (e.key === 'ArrowLeft') { põe(v - 4); e.preventDefault(); }
      if (e.key === 'ArrowRight') { põe(v + 4); e.preventDefault(); }
    });
    põe(50);
  }
}

/* 05. PERSISTÊNCIA E ANEXOS (localStorage) ================================= */
const KEY = 'pedi_solicitacoes_v2';
const ST = { nova: 'Recebida', analise: 'Em análise', respondida: 'Respondida' };
const ORDEM = { nova: 1, analise: 2, respondida: 3 };

const DEMO_IMAGE_URLS = {
  'terreno-frente.jpg': 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792131151/PERDIZES0140.jpg',
  'referencia-escada.jpg': 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126245/RMB_7643-HDR.jpg',
  'vizinhanca-fundos.jpg': 'https://res.cloudinary.com/p2srhjwx/image/upload/v1792126246/Caf%C3%A9_sem_a_mesa.jpg'
};

const load = () => {
  try {
    return (JSON.parse(localStorage.getItem(KEY)) || []).map(r => ({
      ...r,
      anexos: (r.anexos || []).map(a => {
        if (!a.seed || !a.src || a.src.startsWith('https://')) return a;
        const src = DEMO_IMAGE_URLS[a.nome];
        return src ? { ...a, src } : { ...a, src: null, sessao: true };
      })
    }));
  } catch { return []; }
};

const save = lista => {
  try { localStorage.setItem(KEY, JSON.stringify(lista)); return true; }
  catch {
    const leve = lista.map(r => ({ ...r, anexos: (r.anexos || []).map(a => a.seed ? a : { ...a, src: null, sessao: true }) }));
    try { localStorage.setItem(KEY, JSON.stringify(leve)); toast('Não foi possível guardar a prévia de alguns anexos.', 5000); return true; }
    catch { toast('Não foi possível salvar a solicitação.', 5000); return false; }
  }
};

const EX = {
  foto1: { id: 'x1', nome: 'terreno-frente.jpg', kind: 'image', mime: 'image/jpeg', tam: 268000, src: DEMO_IMAGE_URLS['terreno-frente.jpg'], seed: 1 },
  foto2: { id: 'x2', nome: 'referencia-escada.jpg', kind: 'image', mime: 'image/jpeg', tam: 117000, src: DEMO_IMAGE_URLS['referencia-escada.jpg'], seed: 1 },
  foto3: { id: 'x3', nome: 'vizinhanca-fundos.jpg', kind: 'image', mime: 'image/jpeg', tam: 138000, src: DEMO_IMAGE_URLS['vizinhanca-fundos.jpg'], seed: 1 }
};
const dia = d => Date.now() - d * 864e5;

(function semear() {
  if (localStorage.getItem(KEY)) return;
  save([
    { id: 1, nome: 'Marina Alcântara', email: 'marina@exemplo.com', fone: '(15) 99811-0022',
      tipo: 'Residência (obra nova)', local: 'Sorocaba · SP', area: '240 m²', prazo: 'Nos próximos 3 meses',
      verba: 'R$ 150 a 400 mil', data: dia(9), status: 'respondida',
      desc: 'Terreno de 12x30 em leve aclive. Queremos 3 quartos (1 suíte), varanda gourmet nos fundos e muita luz natural. Gostamos de tijolo aparente e madeira.',
      anexos: [EX.foto1, EX.foto3],
      resposta: 'Marina, obrigado pelas fotos e pela planta! O aclive ajuda a soltar o volume social do terreno. Proponho começarmos pelo estudo preliminar em 3 semanas, com dois partidos diferentes para comparar. Envio a proposta comercial detalhada ainda hoje por e-mail.',
      respostaData: dia(7) },
    { id: 2, nome: 'Rodrigo Serra', email: 'rodrigo@exemplo.com', fone: '(15) 99700-4410',
      tipo: 'Reforma e interiores', local: 'Tatuí · SP', area: '96 m²', prazo: 'O quanto antes',
      verba: 'Até R$ 50 mil', data: dia(3), status: 'analise',
      desc: 'Apartamento dos anos 90 para integrar cozinha, sala e varanda. Gravei um vídeo andando pelo apartamento para vocês entenderem o pé-direito e as vigas.',
      anexos: [EX.foto2], resposta: '', respostaData: 0 },
    { id: 3, nome: 'Helena Prado', email: 'helena@exemplo.com', fone: '(15) 99123-7788',
      tipo: 'Espaço comercial', local: 'Itapetininga · SP', area: '120 m²', prazo: 'Neste ano',
      verba: 'R$ 50 a 150 mil', data: dia(1), status: 'nova',
      desc: 'Cafeteria em ponto de esquina, com mezanino. Preciso de projeto de layout, marcenaria e iluminação. Anexei o memorial que a construtora entregou.',
      anexos: [], resposta: '', respostaData: 0 }
  ]);
})();

/* Manipulação de Arquivos e Mídias */
const MAX = 25 * 1024 * 1024; // 25 MB
const urlsSessao = new Map();

const tipoDe = f =>
  f.type.startsWith('image/') ? 'image' :
  f.type.startsWith('video/') ? 'video' :
  f.type === 'application/pdf' ? 'pdf' : null;

const reduzImagem = (file) => new Promise(ok => {
  const url = URL.createObjectURL(file), im = new Image();
  im.onload = () => {
    const lado = 1400, e = Math.min(1, lado / Math.max(im.width, im.height));
    const c = document.createElement('canvas');
    c.width = Math.round(im.width * e); c.height = Math.round(im.height * e);
    c.getContext('2d').drawImage(im, 0, 0, c.width, c.height);
    URL.revokeObjectURL(url);
    ok(c.toDataURL('image/jpeg', .74));
  };
  im.onerror = () => { URL.revokeObjectURL(url); ok(null); };
  im.src = url;
});

const leArquivo = (file, onProg) => new Promise(ok => {
  const fr = new FileReader();
  fr.onprogress = e => e.lengthComputable && onProg && onProg(e.loaded / e.total);
  fr.onload = () => ok(fr.result);
  fr.onerror = () => ok(null);
  fr.readAsDataURL(file);
});

async function fazAnexo(file, onProg) {
  const kind = tipoDe(file);
  if (!kind) throw new Error(`"${file.name}" não é foto, vídeo nem PDF.`);
  if (file.size > MAX) throw new Error(`"${file.name}" tem ${bytes(file.size)} — o limite é 25 MB.`);

  const id = 'a' + Date.now() + Math.random().toString(36).slice(2, 7);
  const base = { id, nome: file.name, kind, mime: file.type, tam: file.size };

  if (kind === 'image') {
    onProg && onProg(.4);
    const src = await reduzImagem(file);
    onProg && onProg(1);
    return { ...base, src, sessao: false };
  }
  if (kind === 'pdf' && file.size <= 2 * 1024 * 1024) {
    const src = await leArquivo(file, onProg);
    return { ...base, src, sessao: false };
  }
  const url = URL.createObjectURL(file);
  urlsSessao.set(id, url);
  onProg && onProg(1);
  return { ...base, src: null, sessao: true, _url: url };
}

const srcDe = a => a.src || a._url || urlsSessao.get(a.id) || null;

const svgDoc = `<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>`;
const svgFilm = `<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 4v16M17 4v16M3 10h4M17 10h4M3 15h4M17 15h4"/></svg>`;
const svgWa = `<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.16-.17.2-.35.22-.64.08-.3-.15-1.26-.47-2.39-1.48-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.6.13-.14.3-.35.45-.52.15-.18.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.61-.92-2.21-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.8.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.21 3.07c.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.7.63.71.22 1.36.19 1.87.12.57-.09 1.76-.72 2-1.41.25-.7.25-1.29.18-1.41-.08-.13-.27-.2-.57-.35m-5.42 7.4a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.45 4.44-9.88 9.89-9.88a9.83 9.83 0 0 1 9.88 9.89c0 5.45-4.44 9.88-9.89 9.88M20.46 3.49A11.82 11.82 0 0 0 12.05 0C5.5 0 .16 5.34.16 11.89c0 2.1.55 4.14 1.59 5.95L.06 24l6.3-1.65a11.88 11.88 0 0 0 5.69 1.45c6.55 0 11.89-5.34 11.89-11.89 0-3.18-1.24-6.17-3.48-8.42"/></svg>`;

const foneWa = f => {
  const d = String(f || '').replace(/\D/g, '');
  return d.length === 10 || d.length === 11 ? '55' + d : d.length >= 12 ? d : null;
};

function cardAnexo(a, { remover = false } = {}) {
  const src = srcDe(a);
  const ext = (a.nome.split('.').pop() || '').toUpperCase().slice(0, 4);
  let thumb;
  if (a.kind === 'image' && src) thumb = `<img src="${src}" alt="${esc(a.nome)}">`;
  else if (a.kind === 'video' && src) thumb = `<video src="${src}" muted preload="metadata"></video><span class="play"><i></i></span>`;
  else thumb = `<div class="ph">${a.kind === 'video' ? svgFilm : svgDoc}<span>${esc(ext || a.kind)}</span>
      ${!src && a.sessao ? '<span style="font-size:8.5px;letter-spacing:0;text-transform:none">sem prévia</span>' : ''}</div>`;

  return `<figure class="file ${a.kind === 'pdf' ? 'doc' : ''}" data-id="${a.id}" title="${esc(a.nome)} · ${bytes(a.tam)}">
    <span class="kind">${a.kind === 'image' ? 'Foto' : a.kind === 'video' ? 'Vídeo' : 'PDF'}</span>
    ${remover ? '<button class="rm" type="button" aria-label="Remover anexo">✕</button>' : ''}
    <div class="thumb">${thumb}</div>
    <figcaption class="nm">${esc(a.nome)}</figcaption>
  </figure>`;
}

function abreAnexos(lista, idx) {
  LB.open(lista.map(a => ({
    kind: a.kind, src: srcDe(a), cap: a.nome + ' · ' + bytes(a.tam),
    tag: a.kind === 'image' ? 'Foto do cliente' : a.kind === 'video' ? 'Vídeo do cliente' : 'Documento PDF'
  })), idx);
}

/* 06. ÁREA DO CLIENTE & SISTEMA DE AUTENTICAÇÃO =========================== */
function mostrarAbaAutenticacao(aba) {
  $$('[data-auth-tab]').forEach(btn => {
    const ativo = btn.dataset.authTab === aba;
    btn.classList.toggle('active', ativo);
    btn.setAttribute('aria-selected', String(ativo));
  });
  $$('[data-auth-panel]').forEach(painel => {
    if (painel) painel.hidden = painel.dataset.authPanel !== aba;
  });
  $$('[data-auth-form]').forEach(form => {
    if (form) form.hidden = form.dataset.authForm !== aba;
  });

  // Compatibilidade com atributo data-v no protótipo base
  $$('#auth > div[data-v]').forEach(d => {
    d.hidden = d.dataset.v !== aba;
  });

  const foco = $(`#auth input:not([hidden])`);
  if (foco) setTimeout(() => foco.focus(), 60);
}

// Configuração Global de Olho de Senha e Navegação de Abas
(() => {
  $$('[data-auth-tab]').forEach(btn => {
    btn.addEventListener('click', () => mostrarAbaAutenticacao(btn.dataset.authTab));
  });

  $$('.eye').forEach(btn => {
    btn.addEventListener('click', () => {
      const input = btn.parentElement ? btn.parentElement.querySelector('input') : btn.previousElementSibling;
      if (!input) return;
      const mostrar = input.type === 'password';
      input.type = mostrar ? 'text' : 'password';
      btn.classList.toggle('on', mostrar);
      btn.setAttribute('aria-label', mostrar ? 'Ocultar senha' : 'Mostrar senha');
    });
  });
})();

if (page === 'cliente') {
  const U = 'pedi_cliente_v2';
  const getU = () => { try { return JSON.parse(localStorage.getItem(U)); } catch { return null; } };
  let pendentes = []; // anexos do formulário ainda não enviados

  /* --- Upload de Anexos --- */
  const drop = $('#drop'), inp = $('#file'), cx = $('#files'), prog = $('#prog'), fErr = $('#fileErr');

  const pintaPendentes = () => {
    if (cx) cx.innerHTML = pendentes.map(a => cardAnexo(a, { remover: true })).join('');
  };

  async function recebe(files) {
    if (!fErr || !prog) return;
    fErr.textContent = '';
    const barra = prog.firstElementChild;
    prog.hidden = false;
    let n = 0;
    for (const f of files) {
      try {
        const a = await fazAnexo(f, p => barra.style.width = Math.round(((n + p) / files.length) * 100) + '%');
        pendentes.push(a); n++;
        barra.style.width = Math.round((n / files.length) * 100) + '%';
        pintaPendentes();
      } catch (err) { n++; fErr.textContent = err.message; }
    }
    setTimeout(() => { prog.hidden = true; barra.style.width = '0'; }, 500);
    if (pendentes.length) toast(`<b>${pendentes.length}</b> arquivo(s) prontos para enviar`);
  }

  if (inp) inp.onchange = () => { recebe([...inp.files]); inp.value = ''; };
  if (drop) {
    ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('over'); }));
    ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('over'); }));
    drop.addEventListener('drop', e => recebe([...e.dataTransfer.files]));
  }

  if (cx) {
    cx.onclick = e => {
      const fig = e.target.closest('.file'); if (!fig) return;
      if (e.target.closest('.rm')) {
        pendentes = pendentes.filter(a => a.id !== fig.dataset.id);
        pintaPendentes(); return;
      }
      abreAnexos(pendentes, pendentes.findIndex(a => a.id === fig.dataset.id));
    };
  }

  /* --- Renderização da Lista de Solicitações --- */
  const trilha = s => {
    const n = ORDEM[s];
    return `<div class="track">
      <div class="ln">${[1, 2, 3].map(i => `<i class="${i <= n ? 'on' : ''}"></i>`).join('')}</div>
      <div class="tx">${['Recebida', 'Em análise', 'Respondida'].map((t, i) =>
        `<span class="${i < n ? 'on' : ''}">${t}</span>`).join('')}</div>
    </div>`;
  };

  const minhas = () => {
    const u = getU(); if (!u) return [];
    return load().filter(r => r.email === u.email).sort((a, b) => b.data - a.data);
  };

  const desenha = () => {
    const u = getU();
    if ($('#login')) $('#login').hidden = !!u;
    if ($('#painel')) $('#painel').hidden = !u;
    if (!u) return;

    if ($('#ola')) $('#ola').textContent = 'Olá, ' + u.nome.split(' ')[0];
    if ($('#mail')) $('#mail').textContent = u.email;
    if ($('#ini')) $('#ini').textContent = u.nome.trim().split(/\s+/).slice(0, 2).map(p => p[0]).join('').toUpperCase();

    const todas = minhas();
    const listaEl = $('#lista');

    if (listaEl) {
      listaEl.innerHTML = todas.length ? todas.map(r => `
        <article class="req" data-id="${r.id}">
          <div class="r1">
            <span class="lab">${fmtData(r.data)} · ${esc(r.tipo)}</span>
            <span class="st ${r.status}">${ST[r.status]}</span>
          </div>
          <h3>${esc(r.local)}${r.area ? ` <span class="lab" style="margin-left:6px">${esc(r.area)}</span>` : ''}</h3>
          <p class="desc">${esc(r.desc)}</p>
          ${trilha(r.status)}
          ${(r.anexos || []).length ? `<div class="att">
              <span class="lab">${r.anexos.length} anexo(s) enviados</span>
              <div class="files">${r.anexos.map(a => cardAnexo(a)).join('')}</div>
            </div>` : ''}
          ${r.whats ? `<div class="answer wa-box">
              <span class="lab">${svgWa} WhatsApp · ${fmtData(r.whats)}</span>
              <p>Um arquiteto da nossa equipe vai entrar em contato pelo seu WhatsApp${r.fone ? ` ${esc(r.fone)}` : ''} para conversar sobre o projeto. Fique de olho nas mensagens!</p>
            </div>` : ''}
          ${r.resposta ? `<div class="answer">
              <span class="lab ac">Resposta da equipe · ${fmtData(r.respostaData || r.data)}</span>
              <p>${esc(r.resposta)}</p>
            </div>` : r.whats ? '' : `<p class="hint" style="margin-top:14px">Sua solicitação está na fila do escritório. O retorno costuma sair em até 48 horas úteis.</p>`}
          <div class="acts2"><button class="btn out sm" data-a="del" type="button">Excluir solicitação</button></div>
        </article>`).join('')
        : `<div class="empty">
            <span class="ico"><svg viewBox="0 0 24 24"><path d="M4 4h10l6 6v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><path d="M14 4v6h6M8 14h8M8 18h5"/></svg></span>
            <b>Você ainda não enviou nenhuma solicitação</b>
            <p>Preencha o formulário para enviar seu pedido e acompanhar o andamento por aqui.</p>
          </div>`;
    }
  };

  const listaEl = $('#lista');
  if (listaEl) {
    listaEl.addEventListener('click', e => {
      const art = e.target.closest('.req'); if (!art) return;
      const r = minhas().find(x => String(x.id) === art.dataset.id); if (!r) return;

      if (e.target.closest('button[data-a="del"]')) {
        if (!confirm('Excluir esta solicitação? O escritório deixará de vê-la e a ação não pode ser desfeita.')) return;
        save(load().filter(x => String(x.id) !== String(r.id)));
        desenha(); toast('Solicitação excluída');
        return;
      }
      const fig = e.target.closest('.file'); if (!fig) return;
      abreAnexos(r.anexos, r.anexos.findIndex(a => a.id === fig.dataset.id));
    });
  }

  /* --- Gerenciamento de Contas e Formulários de Autenticação --- */
  const C = 'pedi_contas';
  const contas = () => { try { return JSON.parse(localStorage.getItem(C)) || {}; } catch { return {}; } };
  const gravaContas = c => localStorage.setItem(C, JSON.stringify(c));
  const cifra = t => btoa(unescape(encodeURIComponent(t)));
  const emailOk = e => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(e);
  const forcaDe = t => (t.length >= 10 && /[^a-zA-Z0-9]/.test(t) ? 3 : t.length >= 8 || /\d/.test(t) && t.length >= 6 ? 2 : 1);
  const NOMES_FORCA = { 1: 'Fraca', 2: 'Média', 3: 'Forte' };

  let codigo = '', alvoEmail = '';

  $$('[data-go]').forEach(b => b.onclick = () => {
    const destino = b.dataset.go;
    if (destino === 'recuperar') {
      const fEntrar = $('#fEntrar');
      const e = fEntrar && fEntrar.email ? fEntrar.email.value.trim() : '';
      if (e && $('#fRecuperar')) $('#fRecuperar').email.value = e;
    }
    mostrarAbaAutenticacao(destino);
  });

  const barra = $('#forca');
  const fCriar = $('#fCriar');
  if (fCriar && fCriar.senha) {
    fCriar.senha.oninput = e => {
      const t = e.target.value, n = t ? forcaDe(t) : 0;
      if (barra) {
        barra.dataset.n = n;
        const b = $('b', barra);
        if (b) b.textContent = n ? NOMES_FORCA[n] : 'Força da senha';
      }
    };
  }

  const entra = (conta, msg) => {
    localStorage.setItem(U, JSON.stringify({ nome: conta.nome, email: conta.email, fone: conta.fone || '' }));
    desenha(); scrollTo({ top: 0, behavior: 'smooth' });
    toast(msg);
  };

  const fEntrar = $('#fEntrar');
  if (fEntrar) {
    fEntrar.onsubmit = e => {
      e.preventDefault();
      const f = new FormData(e.target);
      const email = (f.get('email') || '').trim().toLowerCase(), senha = f.get('senha') || '';
      const erro = $('#entrarErr'); if (erro) erro.textContent = '';
      const okMsg = $('#entrarOk'); if (okMsg) okMsg.textContent = '';
      if (!emailOk(email)) { if (erro) erro.textContent = 'Informe um e-mail válido.'; return; }
      const c = contas()[email];
      if (!c) { if (erro) erro.textContent = 'Não encontramos uma conta com esse e-mail. Crie a sua abaixo.'; return; }
      if (c.senha !== cifra(senha)) { if (erro) erro.textContent = 'Senha incorreta. Tente de novo ou recupere o acesso.'; return; }
      e.target.reset();
      entra(c, `Bem-vindo de volta, <b>${esc(c.nome.split(' ')[0])}</b>`);
    };
  }

  if (fCriar) {
    fCriar.onsubmit = e => {
      e.preventDefault();
      const f = new FormData(e.target);
      const nome = (f.get('nome') || '').trim(), email = (f.get('email') || '').trim().toLowerCase();
      const senha = f.get('senha') || '', senha2 = f.get('senha2') || '';
      const erro = $('#criarErr'); if (erro) erro.textContent = '';
      if (nome.split(/\s+/).filter(Boolean).length < 2) { if (erro) erro.textContent = 'Informe o nome e o sobrenome.'; return; }
      if (!emailOk(email)) { if (erro) erro.textContent = 'Informe um e-mail válido.'; return; }
      if (contas()[email]) { if (erro) erro.textContent = 'Já existe uma conta com esse e-mail. Entre ou recupere a senha.'; return; }
      if (senha.length < 6) { if (erro) erro.textContent = 'A senha precisa ter ao menos 6 caracteres.'; return; }
      if (senha !== senha2) { if (erro) erro.textContent = 'As duas senhas não são iguais.'; return; }
      const c = contas();
      c[email] = { nome, email, fone: (f.get('fone') || '').trim(), senha: cifra(senha), criada: Date.now() };
      gravaContas(c);
      e.target.reset();
      if (barra) { barra.dataset.n = 0; const b = $('b', barra); if (b) b.textContent = 'Força da senha'; }
      entra(c[email], `Conta criada. Bem-vindo, <b>${esc(nome.split(' ')[0])}</b>`);
    };
  }

  const mandaCodigo = email => {
    codigo = String(Math.floor(100000 + Math.random() * 900000));
    alvoEmail = email;
    if ($('#codigo')) $('#codigo').textContent = codigo;
    if ($('#destino')) $('#destino').textContent = email;
    if ($('#fRedefinir')) {
      $('#fRedefinir').reset();
      if ($('#usuarioRed')) $('#usuarioRed').value = email;
    }
  };

  const fRecuperar = $('#fRecuperar');
  if (fRecuperar) {
    fRecuperar.onsubmit = e => {
      e.preventDefault();
      const email = (new FormData(e.target).get('email') || '').trim().toLowerCase();
      const erro = $('#recErr'); if (erro) erro.textContent = '';
      if (!emailOk(email)) { if (erro) erro.textContent = 'Informe um e-mail válido.'; return; }
      if (!contas()[email]) { if (erro) erro.textContent = 'Não encontramos nenhuma conta com esse e-mail.'; return; }
      mandaCodigo(email); mostrarAbaAutenticacao('redefinir');
      toast('Código de verificação enviado');
    };
  }

  if ($('#reenviar')) $('#reenviar').onclick = () => { mandaCodigo(alvoEmail); toast('Novo código gerado'); };

  const fRedefinir = $('#fRedefinir');
  if (fRedefinir) {
    fRedefinir.onsubmit = e => {
      e.preventDefault();
      const f = new FormData(e.target);
      const cod = (f.get('codigo') || '').replace(/\D/g, '');
      const senha = f.get('senha') || '', senha2 = f.get('senha2') || '';
      const erro = $('#redErr'); if (erro) erro.textContent = '';
      if (cod !== codigo) { if (erro) erro.textContent = 'Código incorreto. Confira os 6 dígitos enviados.'; return; }
      if (senha.length < 6) { if (erro) erro.textContent = 'A nova senha precisa ter ao menos 6 caracteres.'; return; }
      if (senha !== senha2) { if (erro) erro.textContent = 'As duas senhas não são iguais.'; return; }
      const c = contas();
      c[alvoEmail].senha = cifra(senha);
      gravaContas(c);
      codigo = '';
      e.target.reset(); mostrarAbaAutenticacao('entrar');
      if ($('#fEntrar')) $('#fEntrar').email.value = alvoEmail;
      if ($('#entrarOk')) $('#entrarOk').textContent = 'Senha alterada. Entre com a nova senha.';
      toast('Senha alterada com sucesso');
    };
  }

  if ($('#sair')) $('#sair').onclick = () => { localStorage.removeItem(U); pendentes = []; pintaPendentes(); desenha(); mostrarAbaAutenticacao('entrar'); };

  const fReq = $('#fReq');
  if (fReq) {
    fReq.onsubmit = e => {
      e.preventDefault();
      const f = new FormData(e.target), u = getU();
      const local = (f.get('local') || '').trim(), desc = (f.get('desc') || '').trim();
      const okEl = $('#ok');
      if (!local || desc.length < 12) {
        if (okEl) {
          okEl.className = 'msg err';
          okEl.textContent = 'Informe a cidade e descreva sua ideia com um pouco mais de detalhe.';
        }
        return;
      }
      const l = load();
      l.push({
        id: Date.now(), nome: u.nome, email: u.email, fone: u.fone || '',
        tipo: f.get('tipo'), local, area: '', prazo: '', verba: '', desc,
        data: Date.now(), status: 'nova', resposta: '', respostaData: 0,
        anexos: pendentes.map(({ _url, ...a }) => a)
      });
      save(l);
      const n = pendentes.length;
      pendentes = []; pintaPendentes(); e.target.reset();
      if (okEl) {
        okEl.className = 'msg ok';
        okEl.textContent = 'Solicitação enviada! O escritório responde em até 48 horas úteis.';
      }
      desenha();
      toast(`Solicitação enviada${n ? ` com <b>${n}</b> anexo(s)` : ''}`);
    };
  }

  addEventListener('storage', desenha);
  desenha();
}

/* 07. ÁREA DO ARQUITETO =================================================== */
if (page === 'arquiteto') {
  const USUARIO = 'atelier';
  const SENHA = 'aspi123';
  const dentro = () => sessionStorage.getItem('pedi_arq') === '1';
  let filtro = 'all';

  const dados = () => load().sort((a, b) =>
    (ORDEM[a.status] - ORDEM[b.status]) || (b.data - a.data));

  const linkWa = r => {
    const n = foneWa(r.fone);
    if (!n) return `<button class="btn sm wa" type="button" disabled title="O cliente não informou telefone">${svgWa} Sem WhatsApp</button>`;
    const msg = `Olá, ${r.nome.split(' ')[0]}! Aqui é da equipe do escritório Um Pé de IDEIA. Recebemos sua solicitação (${r.tipo} · ${r.local}) e gostaríamos de conversar sobre o projeto.`;
    return `<a class="btn sm wa" data-a="wa" href="https://wa.me/${n}?text=${encodeURIComponent(msg)}" target="_blank" rel="noopener">${svgWa} WhatsApp do cliente</a>`;
  };

  const desenha = () => {
    const on = dentro();
    if ($('#gate')) $('#gate').hidden = on;
    if ($('#painel')) $('#painel').hidden = !on;
    if ($('#sair')) $('#sair').hidden = !on;
    if (!on) return;

    const todas = dados();
    if ($('#k1')) $('#k1').textContent = todas.length;
    if ($('#k2')) $('#k2').textContent = todas.filter(r => r.status === 'nova').length;
    if ($('#k3')) $('#k3').textContent = todas.filter(r => r.status === 'analise').length;
    if ($('#k4')) $('#k4').textContent = todas.filter(r => r.status === 'respondida').length;

    const vis = todas.filter(r => filtro === 'all' || r.status === filtro);
    const listaEl = $('#lista');

    if (listaEl) {
      listaEl.innerHTML = vis.length ? vis.map(r => `
        <article class="req" data-id="${r.id}">
          <div class="r1">
            <span class="lab">${fmtHora(r.data)} · ${esc(r.tipo)}</span>
            <span class="st ${r.status}">${ST[r.status]}</span>
          </div>
          <h3>${esc(r.nome)}</h3>
          <div class="meta-l" style="display:flex;flex-wrap:wrap;gap:14px;margin:6px 0 12px">
            <span class="lab">${esc(r.email)}</span>
            ${r.fone ? `<span class="lab">${esc(r.fone)}</span>` : ''}
            <span class="lab">${esc(r.local)}</span>
            ${r.area ? `<span class="lab">${esc(r.area)}</span>` : ''}
            ${r.prazo ? `<span class="lab">Prazo: ${esc(r.prazo)}</span>` : ''}
            ${r.verba ? `<span class="lab">Verba: ${esc(r.verba)}</span>` : ''}
          </div>
          <p class="desc">${esc(r.desc)}</p>
          ${(r.anexos || []).length ? `<div class="att">
              <span class="lab">Anexos do cliente (${r.anexos.length})</span>
              <div class="files">${r.anexos.map(a => cardAnexo(a)).join('')}</div>
            </div>` : ''}
          <label style="margin-top:18px"><span class="lab">Resposta ao cliente</span>
            <textarea placeholder="Escreva o retorno técnico, o prazo do estudo preliminar e os próximos passos...">${esc(r.resposta)}</textarea></label>
          <div class="acts2">
            <button class="btn soft sm" data-a="analise" type="button">Marcar em análise</button>
            <button class="btn sm" data-a="resp" type="button">Enviar resposta</button>
            ${linkWa(r)}
            <button class="btn out sm" data-a="del" type="button">Excluir</button>
          </div>
          ${r.whats ? `<p class="hint" style="margin-top:10px">Contato pelo WhatsApp iniciado em ${fmtHora(r.whats)} · o cliente foi avisado na área dele.</p>` : ''}
        </article>`).join('')
        : `<div class="empty">
            <span class="ico"><svg viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/><path d="m4 7 8 6 8-6"/></svg></span>
            <b>Nenhuma solicitação neste filtro</b>
            <p>As solicitações enviadas pela área do cliente aparecem aqui automaticamente.</p>
          </div>`;
    }
  };

  const listaEl = $('#lista');
  if (listaEl) {
    listaEl.onclick = e => {
      const art = e.target.closest('.req'); if (!art) return;

      const fig = e.target.closest('.file');
      if (fig) {
        const r = load().find(x => String(x.id) === art.dataset.id);
        abreAnexos(r.anexos, r.anexos.findIndex(a => a.id === fig.dataset.id));
        return;
      }
      const b = e.target.closest('[data-a]'); if (!b) return;
      const ta = $('textarea', art), txt = ta ? ta.value.trim() : '';
      let l = load();
      const r = l.find(x => String(x.id) === art.dataset.id);

      if (b.dataset.a === 'wa') {
        r.whats = Date.now(); r.resposta = txt;
        if (r.status === 'nova') r.status = 'analise';
        save(l); setTimeout(desenha);
        toast('Cliente avisado: <b>contato pelo WhatsApp</b>');
        return;
      }

      if (b.dataset.a === 'del') {
        if (!confirm('Excluir esta solicitação? A ação não pode ser desfeita.')) return;
        l = l.filter(x => x !== r); save(l); desenha(); toast('Solicitação excluída'); return;
      }
      if (b.dataset.a === 'resp') {
        if (!txt) { if (ta) ta.focus(); toast('Escreva a resposta antes de enviar'); return; }
        r.resposta = txt; r.status = 'respondida'; r.respostaData = Date.now();
        toast('Resposta enviada ao cliente');
      } else {
        r.status = 'analise'; r.resposta = txt;
        toast('Solicitação marcada como <b>em análise</b>');
      }
      save(l); desenha();
    };
  }

  $$('.filters button').forEach(b => b.onclick = () => {
    $$('.filters button').forEach(x => x.setAttribute('aria-pressed', String(x === b)));
    filtro = b.dataset.s; desenha();
  });

  const fGate = $('#fGate');
  if (fGate) {
    fGate.onsubmit = e => {
      e.preventDefault();
      const f = new FormData(e.target);
      if ((f.get('usuario') || '').trim().toLowerCase() === USUARIO && f.get('senha') === SENHA) {
        sessionStorage.setItem('pedi_arq', '1'); if ($('#err')) $('#err').textContent = '';
        desenha(); toast('Bem-vindo ao <b>painel do escritório</b>');
      } else {
        if ($('#err')) $('#err').textContent = 'Usuário ou senha incorretos. Tente novamente.';
      }
    };
  }

  const sair = () => { sessionStorage.removeItem('pedi_arq'); desenha(); };
  if ($('#sair')) $('#sair').onclick = sair;
  if ($('#sair2')) $('#sair2').onclick = sair;

  addEventListener('storage', desenha);
  desenha();
}
