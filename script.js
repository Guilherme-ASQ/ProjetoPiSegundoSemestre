/* Interações visuais. Dados e autenticação reais são tratados pelo PHP/Neon. */
(() => {
  const $ = (s, root=document) => root.querySelector(s);
  const $$ = (s, root=document) => [...root.querySelectorAll(s)];
  const ano = $("#ano"); if (ano) ano.textContent = new Date().getFullYear();
  const top = $("#top");
  addEventListener("scroll", () => top && top.classList.toggle("stuck", scrollY > 8), {passive:true});
  const burger=$("#burger"), drawer=$("#drawer");
  if (burger && drawer) {
    burger.addEventListener("click", () => {
      const open=burger.getAttribute("aria-expanded")!=="true";
      burger.setAttribute("aria-expanded",String(open)); drawer.classList.toggle("open",open);
    });
    $$("a",drawer).forEach(a=>a.addEventListener("click",()=>{drawer.classList.remove("open");burger.setAttribute("aria-expanded","false");}));
  }
  $$("[data-auth-tab]").forEach(btn=>btn.addEventListener("click",()=>{
    $$("[data-auth-tab]").forEach(b=>b.classList.toggle("active",b===btn));
    $$("[data-auth-form]").forEach(f=>f.hidden=f.dataset.authForm!==btn.dataset.authTab);
  }));
  $$(".eye").forEach(btn=>btn.addEventListener("click",()=>{
    const input=btn.parentElement.querySelector("input");
    if(input){input.type=input.type==="password"?"text":"password";btn.setAttribute("aria-label",input.type==="password"?"Mostrar senha":"Ocultar senha");}
  }));
  if (document.body.dataset.page==="home") {
    const categories=[
      {name:"Destaques",images:[
        ["https://res.cloudinary.com/p2srhjwx/image/upload/v1792131151/PERDIZES0140.jpg","Living com tijolo aparente"],
        ["https://res.cloudinary.com/p2srhjwx/image/upload/v1792126245/RMB_7643-HDR.jpg","Living integrado ao jardim"],
        ["https://res.cloudinary.com/p2srhjwx/image/upload/v1792126246/Caf%C3%A9_sem_a_mesa.jpg","Estar com adega embutida"],
        ["https://res.cloudinary.com/p2srhjwx/image/upload/v1792126244/20171005_134029.jpg","Jantar junto à varanda"]
      ]},
      {name:"Interiores",images:[
        ["https://res.cloudinary.com/p2srhjwx/image/upload/v1792132926/RMB_7475-HDR-2.jpg","Cozinha com ilha"],
        ["https://res.cloudinary.com/p2srhjwx/image/upload/v1792132909/RMB_7457-HDR.jpg","Nicho de café iluminado"],
        ["https://res.cloudinary.com/p2srhjwx/image/upload/v1792131658/Marcelo_SALA.jpg","Escada e ripado"],
        ["https://res.cloudinary.com/p2srhjwx/image/upload/v1792131661/RMB_7580-HDR.jpg","Varanda com jardim vertical"]
      ]},
      {name:"Paisagismo",images:[
        ["https://res.cloudinary.com/p2srhjwx/image/upload/v1792134325/Jardim_vertical_1.jpg","Jardim vertical"],
        ["https://res.cloudinary.com/p2srhjwx/image/upload/v1792134390/Port%C3%A3o_corredor.jpg","Portão-jardim"],
        ["https://res.cloudinary.com/p2srhjwx/image/upload/v1792134152/Corredor_entrada.jpg","Corredor com floreiras"]
      ]},
      {name:"Desenhos",images:[
        ["https://res.cloudinary.com/p2srhjwx/image/upload/v1792137326/Vale_do_Anhangaba%C3%BA.jpg","Vale do Anhangabaú"],
        ["https://res.cloudinary.com/p2srhjwx/image/upload/v1792136630/IMG_1433.png","Croqui de garagem"],
        ["https://res.cloudinary.com/p2srhjwx/image/upload/v1792136644/IMG_1436.png","Residência em balanço"]
      ]}
    ];
    const cards=$("#cards");
    if(cards){
      categories.forEach(cat=>cat.images.forEach(([src,caption])=>{
        const el=document.createElement("article");el.className="card";el.dataset.cat=cat.name;
        const img=document.createElement("img");img.src=src;img.alt=caption;img.loading="lazy";
        const cap=document.createElement("span");cap.className="leg";cap.textContent=caption;
        el.append(img,cap);el.addEventListener("click",()=>{const lb=$("#lb"),fig=$("#lbFig");fig.replaceChildren(img.cloneNode());lb.classList.add("open");});cards.append(el);
      }));
      const buttons=$$("[data-f]");
      const filter=b=>{buttons.forEach(x=>x.setAttribute("aria-pressed",String(x===b)));$$(".card",cards).forEach(c=>c.hidden=c.dataset.cat!==b.dataset.f);};
      buttons.forEach(b=>b.addEventListener("click",()=>filter(b)));
      if(buttons[0])filter(buttons[0]);
    }
    const compare=$("#compare"),overlay=$("#cmpTop"),handle=$("#cmpHandle");
    if(compare&&overlay&&handle){
      const set=p=>{p=Math.max(0,Math.min(100,p));overlay.style.clipPath=`inset(0 ${100-p}% 0 0)`;handle.style.left=p+"%";handle.setAttribute("aria-valuenow",String(Math.round(p)));};
      const eventMove=e=>{const r=compare.getBoundingClientRect();set((e.clientX-r.left)/r.width*100);};
      let active=false;compare.addEventListener("pointerdown",e=>{active=true;compare.setPointerCapture(e.pointerId);eventMove(e);});compare.addEventListener("pointermove",e=>{if(active)eventMove(e);});compare.addEventListener("pointerup",()=>active=false);handle.addEventListener("keydown",e=>{const n=Number(handle.getAttribute("aria-valuenow"));if(e.key==="ArrowLeft")set(n-4);if(e.key==="ArrowRight")set(n+4);});set(50);
    }
  }
  const lb=$("#lb");if(lb){const close=()=>lb.classList.remove("open");const x=$("#lbX");if(x)x.addEventListener("click",close);lb.addEventListener("click",e=>{if(e.target===lb)close();});addEventListener("keydown",e=>{if(e.key==="Escape")close();});}
})();
