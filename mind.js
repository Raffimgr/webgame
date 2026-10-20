/* Ridge Games: mind games. Eighteen quick brain games with levels 1 to 5.
   MindGames.play(el, {game, level, team, color, onDone(win, score), onFinish({win, score, left}), autostart}) draws a game inside el. */
(function(){
const CSS=`
.mg{position:relative;flex:1;display:flex;flex-direction:column;gap:14px;min-height:0;color:var(--fg,#eef1f6);font-family:var(--font,"Inter",system-ui,sans-serif)}
.mg-top{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.mg-top .ttl{font:600 20px var(--play,"Fredoka",system-ui);flex:1;min-width:0}
.mg-top .ttl small{display:block;font:500 12.5px var(--font,system-ui);color:var(--mut,#9aa3b3)}
.mg-chip{font-weight:700;font-size:13px;padding:7px 12px;border-radius:99px;background:var(--well,#202328);box-shadow:var(--well-sh,none);font-variant-numeric:tabular-nums}
.mg-clock{font:700 22px var(--play,"Fredoka",system-ui);color:#ffd23f;min-width:54px;text-align:center;font-variant-numeric:tabular-nums}
.mg-lv{display:inline-flex;gap:3px;vertical-align:middle;margin-left:6px}
.mg-lv i{width:8px;height:8px;border-radius:50%;background:#3a3f48}
.mg-lv i.on{background:#ffd23f;box-shadow:0 0 6px #ffd23f}
.mg-body{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18px;text-align:center;min-height:0}
.mg-q{background:#fff;color:#15181e;border-radius:26px;padding:22px 28px;min-width:min(520px,100%);box-shadow:0 8px 0 #c9cfd8,0 18px 36px rgba(0,0,0,.35);font:700 clamp(30px,6vw,52px)/1.1 var(--play,"Fredoka",system-ui)}
.mg-q small{display:block;font:700 12px var(--font,system-ui);letter-spacing:.12em;text-transform:uppercase;color:#6b7383;margin-bottom:6px}
.mg-opts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;width:min(560px,100%)}
.mg-opts.c3{grid-template-columns:repeat(3,minmax(0,1fr))}
.mg-b{border:0;cursor:pointer;border-radius:20px;padding:18px 10px;font:700 24px var(--play,"Fredoka",system-ui);color:#15181e;background:#eef1f6;box-shadow:0 6px 0 #b8bfca;transition:transform .08s}
.mg-b:active{transform:translateY(3px);box-shadow:0 3px 0 #b8bfca}
.mg-b:focus-visible{outline:3px solid #ffd23f;outline-offset:3px}
.mg-b.ok{background:#7be08a}.mg-b.no{background:#ff9c9c}
.mg-b.sm{font-size:18px;padding:12px 8px}
.mg-keys{display:grid;grid-template-columns:repeat(3,72px);gap:10px}
.mg-keys .mg-b{padding:14px 0}
.mg-typed{font:700 40px var(--play,"Fredoka",system-ui);letter-spacing:.15em;min-height:52px}
.mg-tiles{display:flex;flex-wrap:wrap;justify-content:center;gap:10px}
.mg-tiles .mg-b{width:62px;padding:14px 0;text-transform:uppercase}
.mg-tiles .mg-b:disabled{opacity:.25;cursor:default}
.mg-grid{display:grid;gap:8px;width:min(380px,90vw)}
.mg-cell{aspect-ratio:1;border-radius:14px;border:0;cursor:pointer;background:#3a3f48;box-shadow:inset 0 -4px 0 rgba(0,0,0,.3);transition:background .15s}
.mg-cell.lit{background:#ffd23f;box-shadow:0 0 16px #ffd23f}
.mg-cell.hit{background:#7be08a}.mg-cell.bad{background:#ff8f8f}
.mg-hint{font-size:13.5px;color:var(--mut,#9aa3b3);max-width:52ch;margin:0}
.mg-lives{letter-spacing:4px;color:#ff8f8f}
.mg-shake{animation:mgshake .3s}
@keyframes mgshake{25%{transform:translateX(-8px)}75%{transform:translateX(8px)}}
@media (prefers-reduced-motion:reduce){.mg-shake{animation:none}}
.mg-cd{font:700 96px var(--play,"Fredoka",system-ui);color:#ffd23f}
.mg-start{border:0;cursor:pointer;border-radius:18px;padding:16px 28px;font:700 18px var(--font,system-ui);color:#15181e;background:var(--x,#7ee8f0);box-shadow:0 0 16px color-mix(in srgb,var(--x,#7ee8f0) 40%,transparent)}
.mg-end{position:absolute;inset:0;display:grid;place-items:center;background:rgba(10,12,16,.72);z-index:3;border-radius:20px}
.mg-end .box{background:var(--sheet,#2a2e35);border-radius:26px;padding:24px;width:min(420px,calc(100% - 24px));display:flex;flex-direction:column;gap:12px;text-align:center}
.mg-end .big{font:700 30px var(--play,"Fredoka",system-ui)}
.mg button{touch-action:manipulation;-webkit-tap-highlight-color:transparent}
.mg-b:disabled{opacity:.4;cursor:default}
.mg-sh{padding:15%;background:#2b2f37}
.mg-tubes{display:flex;flex-wrap:wrap;justify-content:center;align-items:flex-end;gap:28px 10px;padding-top:18px}
.mg-tube{width:var(--w);height:calc(var(--w)*4.4);flex:none;cursor:pointer;padding:3px;display:flex;flex-direction:column-reverse;gap:2px;border:3px solid rgba(238,241,246,.55);border-top-width:0;border-radius:4px 4px calc(var(--w)/2) calc(var(--w)/2);background:rgba(255,255,255,.05);transition:transform .15s,border-color .15s}
.mg-tube i{display:block;flex:none;height:calc(var(--w)*.95);border-radius:3px}
.mg-tube i:first-child{border-radius:3px 3px calc(var(--w)/2 - 5px) calc(var(--w)/2 - 5px)}
.mg-tube.sel{transform:translateY(-16px);border-color:#ffd23f;box-shadow:0 0 14px rgba(255,210,63,.35)}
.mg-pad{border:0;cursor:pointer;border-radius:22px;background:var(--c);opacity:.3;box-shadow:inset 0 -6px 0 rgba(0,0,0,.25);transition:opacity .12s,transform .12s,box-shadow .12s}
.mg-pad.lit{opacity:1;transform:scale(1.04);box-shadow:0 0 26px var(--c)}
.mg-field{position:relative;border-radius:22px;background:var(--well,#202328);box-shadow:var(--well-sh,none);max-width:100%}
.mg-field span{position:absolute}
.mg-qs{display:inline-block;width:64px;height:64px;padding:10px;border-radius:16px;background:#2b2f37;vertical-align:middle}
.mg-num{border:0;cursor:pointer;border-radius:12px;background:#eef1f6;color:#15181e;font-weight:700;font-family:var(--play,"Fredoka",system-ui);box-shadow:0 4px 0 #b8bfca;padding:0;transition:opacity .15s}
.mg-num.done{opacity:.15}.mg-num.no{background:#ff9c9c!important}
.mg-tw{background:#fff;border-radius:22px;padding:14px;box-shadow:0 6px 0 #c9cfd8}
.mg-poly{position:relative;display:block;margin:auto}
.mg-poly i{position:absolute;box-sizing:border-box;border:2px solid rgba(0,0,0,.28);border-radius:4px}
.mg-card{border:0;cursor:pointer;padding:16%;border-radius:14px;background:repeating-linear-gradient(45deg,#3a3f48 0 8px,#32363e 8px 16px);box-shadow:inset 0 -4px 0 rgba(0,0,0,.3)}
.mg-card span{display:block;width:100%;height:100%;opacity:0}
.mg-card.up{background:#202328;box-shadow:inset 0 0 0 2px #4a505b;animation:mgflip .18s}
.mg-card.up span{opacity:1}
.mg-card.gone{opacity:.3;cursor:default}
@keyframes mgflip{from{transform:scaleX(.2)}to{transform:scaleX(1)}}
.mg-slide{position:relative;border-radius:16px;max-width:100%}
.mg-tile{position:absolute;left:0;top:0;border:0;cursor:pointer;border-radius:14px;color:#15181e;font-weight:700;font-family:var(--play,"Fredoka",system-ui);box-shadow:inset 0 -5px 0 rgba(0,0,0,.18);transition:transform .14s}
.mg-arrows{display:flex;gap:6px;justify-content:center;padding:14px 0}
.mg-arrows span{display:block}
.mg-dpad{display:grid;gap:10px;grid-template-areas:"left right";grid-template-columns:repeat(2,120px)}
.mg-dpad.four{grid-template-areas:". up ." "left . right" ". down .";grid-template-columns:repeat(3,86px)}
.mg-dpad .mg-b{padding:14px 0}
.mg-ai{display:block;width:38px;height:38px;margin:auto}
.mg-seq div{display:flex;flex-wrap:wrap;justify-content:center;gap:4px 18px;font-size:clamp(24px,5vw,44px)}
.mg-miss{color:#e5484d}
@media (prefers-reduced-motion:reduce){.mg-card.up{animation:none}.mg-tile,.mg-tube{transition:none}}
`;
function css(){if(document.getElementById("mg-css"))return;const s=document.createElement("style");s.id="mg-css";s.textContent=CSS;document.head.appendChild(s)}
const R=(a,b)=>a+Math.floor(Math.random()*(b-a+1));
const pick=a=>a[Math.floor(Math.random()*a.length)];
const shuffle=a=>{a=a.slice();for(let i=a.length-1;i>0;i--){const j=Math.random()*(i+1)|0;[a[i],a[j]]=[a[j],a[i]]}return a};
const esc=s=>String(s).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));

// ---------- shared bits for the newer games ----------
// shapes drawn as small SVGs (no image files)
const SH={circle:'<circle cx="50" cy="50" r="42"/>',square:'<rect x="9" y="9" width="82" height="82" rx="12"/>',triangle:'<polygon points="50,7 95,89 5,89"/>',diamond:'<polygon points="50,3 96,50 50,97 4,50"/>',
  star:'<polygon points="50,4 61,37 96,37 68,58 79,92 50,71 21,92 32,58 4,37 39,37"/>',hexagon:'<polygon points="50,4 90,27 90,73 50,96 10,73 10,27"/>',
  cross:'<polygon points="36,6 64,6 64,36 94,36 94,64 64,64 64,94 36,94 36,64 6,64 6,36 36,36"/>',heart:'<path d="M50 90C22 68 5 52 5 32 5 17 17 7 30 7c9 0 16 5 20 13C54 12 61 7 70 7c13 0 25 10 25 25 0 20-17 36-45 58z"/>',
  arrow:'<path d="M50 4 90 46H64v50H36V46H10z"/>'};
const SHAPES=["circle","square","triangle","diamond","star","hexagon","cross","heart"];
const PAL=["#ff5d6c","#ffd23f","#4f8dff","#3ccf6e","#a66bff","#ff9a3c","#ff7ad9","#45e0d8","#c9f04a","#f2f2f2"];
const svg=(k,c,rot)=>`<svg viewBox="0 0 100 100" width="100%" height="100%" style="display:block${rot?`;transform:rotate(${rot}deg)`:""}" aria-hidden="true"><g fill="${c}">${SH[k]}</g></svg>`;

// Liquid Lab: tubes are arrays bottom → top of color numbers; 4 units per tube
const LQ=["#ff5d6c","#4f8dff","#3ccf6e","#ffd23f","#a66bff","#ff9a3c","#ff7ad9","#39d5e6","#9a6233","#eef1f6","#b8f04a","#5b5fe0"];
function lqPour(t,i,j,cap){const a=t[i],b=t[j];if(i===j||!a.length||b.length>=cap)return 0;const c=a[a.length-1];if(b.length&&b[b.length-1]!==c)return 0;let n=0;while(a.length&&a[a.length-1]===c&&b.length<cap){b.push(a.pop());n++}return n}
function lqDone(t,cap){return t.every(x=>!x.length||(x.length===cap&&x.every(v=>v===x[0])))}
// depth-first solver with memory; returns the number of states looked at, or -1 when no solution was found within the limit
function lqSolve(start,cap,limit){const seen=new Set();let nodes=0;const key=t=>t.map(x=>x.join("")).sort().join("|");
  const dfs=t=>{if(lqDone(t,cap))return true;if(++nodes>limit)return false;const k=key(t);if(seen.has(k))return false;seen.add(k);
    for(let i=0;i<t.length;i++){const a=t[i];if(!a.length)continue;const uni=a.every(v=>v===a[0]);
      for(let j=0;j<t.length;j++){if(i===j)continue;const b=t[j];if(b.length>=cap||(b.length&&b[b.length-1]!==a[a.length-1])||(!b.length&&uni))continue;
        const c=t.map(x=>x.slice());lqPour(c,i,j,cap);if(dfs(c))return true}}return false};
  return dfs(start.map(x=>x.slice()))?nodes:-1}
// level 1: 5 colors in 7 tubes … level 5: 12 colors in 14 tubes (always 2 empty tubes; Sako found the old 3-7 colors too easy).
// Harder puzzles have few same-color pairs already touching, so the mixes are tighter; only puzzles the solver can finish are used.
function liquidPuzzle(L){const c=[5,7,9,10,12][L-1],cap=4,maxPairs=[2,2,2,2,3][L-1];let t,fallback=null;
  const pairs=t=>t.reduce((n,x)=>n+x.filter((v,i)=>i&&v===x[i-1]).length,0);
  for(let g=0;g<400;g++){const u=shuffle([].concat(...[...Array(c).keys()].map(i=>Array(cap).fill(i))));t=[...Array(c)].map((_,i)=>u.slice(i*cap,i*cap+cap));t.push([],[]);
    if(t.some(x=>x.length===cap&&x.every(v=>v===x[0])))continue;
    const tight=pairs(t)<=maxPairs;if(!tight&&fallback)continue;
    if(lqSolve(t,cap,60000)>=0){if(tight)return t;fallback=t}}
  return fallback||t}

// Turn Twin: little shapes made of squares, as lists of [x,y]
const DIRS=[[1,0],[-1,0],[0,1],[0,-1]];
const pNorm=c=>{const mx=Math.min(...c.map(p=>p[0])),my=Math.min(...c.map(p=>p[1]));return c.map(p=>[p[0]-mx,p[1]-my]).sort((a,b)=>a[0]-b[0]||a[1]-b[1])};
const pKey=c=>pNorm(c).map(p=>p.join(",")).join(";");
const pRot=c=>pNorm(c.map(([x,y])=>[-y,x]));
const pMir=c=>pNorm(c.map(([x,y])=>[-x,y]));
const pRots=c=>{const o=[];let r=pNorm(c);for(let i=0;i<4;i++){o.push(r);r=pRot(r)}return o};
const pBox=c=>{const n=pNorm(c);return Math.max(...n.map(p=>p[0]),...n.map(p=>p[1]))+1};
const pHas=(c,p)=>c.some(q=>q[0]===p[0]&&q[1]===p[1]);
const pConn=c=>{const seen=[c[0]],q=[c[0]];while(q.length){const [x,y]=q.pop();for(const [dx,dy] of DIRS){const p=[x+dx,y+dy];if(pHas(c,p)&&!pHas(seen,p)){seen.push(p);q.push(p)}}}return seen.length===c.length};
const pGrow=(k,n)=>{for(;;){let c=[[0,0]];for(let g=0;c.length<k&&g<300;g++){const [x,y]=pick(c),[dx,dy]=pick(DIRS),p=[x+dx,y+dy];if(pHas(c,p))continue;const t=c.concat([p]);if(pBox(t)<=n)c=t}if(c.length===k)return pNorm(c)}};
const pAlter=(c,n)=>{for(let g=0;g<200;g++){const i=R(0,c.length-1),rest=c.filter((_,j)=>j!==i);if(!pConn(rest))continue;const [x,y]=pick(rest),[dx,dy]=pick(DIRS),p=[x+dx,y+dy];
  if(pHas(rest,p)||(p[0]===c[i][0]&&p[1]===c[i][1]))continue;const t=rest.concat([p]);if(pBox(t)<=n)return pNorm(t)}return null};
const pDraw=(c,n,px,col)=>{const w=Math.max(...c.map(p=>p[0]))+1,h=Math.max(...c.map(p=>p[1]))+1;
  return `<span class="mg-poly" style="width:${px}px;height:${px}px">${c.map(([x,y])=>`<i style="left:${(x+(n-w)/2)/n*100}%;top:${(y+(n-h)/2)/n*100}%;width:${100/n}%;height:${100/n}%;background:${col}"></i>`).join("")}</span>`};

// Word Sort: groups per level
const WS=[
  [["Animal",["Dog","Cat","Horse","Cow","Lion","Tiger","Rabbit","Sheep","Goat","Fox","Bear","Mouse"]],["Food",["Bread","Pizza","Cheese","Pasta","Rice","Soup","Cake","Burger","Salad","Butter","Cookie","Taco"]]],
  [["Fruit",["Apple","Banana","Mango","Pear","Peach","Cherry","Grape","Lemon","Plum","Melon","Papaya","Apricot"]],["Vegetable",["Carrot","Potato","Onion","Spinach","Cabbage","Lettuce","Broccoli","Celery","Radish","Leek","Garlic","Zucchini"]],["Animal",["Zebra","Camel","Otter","Moose","Panda","Koala","Fox","Wolf","Deer","Badger","Hippo","Llama"]]],
  [["Fruit",["Fig","Lime","Guava","Lychee","Mango","Pomelo","Date","Quince","Papaya","Apricot"]],["Vegetable",["Kale","Beet","Turnip","Leek","Okra","Celery","Parsnip","Radish","Artichoke","Fennel"]],["Animal",["Lynx","Yak","Ferret","Bison","Gazelle","Tapir","Hyena","Mink","Ibex","Lemur"]],["Country",["Peru","Spain","Kenya","Norway","Egypt","Japan","Canada","Brazil","Greece","Portugal","Nepal","Iceland"]]],
  [["Mammal",["Whale","Bat","Dolphin","Seal","Otter","Hedgehog","Beaver","Walrus","Squirrel","Mole"]],["Bird",["Penguin","Ostrich","Owl","Eagle","Parrot","Swan","Robin","Pelican","Flamingo","Emu"]],["Fish",["Shark","Salmon","Tuna","Cod","Trout","Seahorse","Eel","Sardine","Carp","Herring"]],["Insect",["Ant","Bee","Beetle","Moth","Wasp","Ladybug","Cricket","Grasshopper","Dragonfly","Termite"]]],
  [["Mammal",["Whale","Bat","Platypus","Pangolin","Manatee","Dolphin","Walrus","Narwhal","Orca","Hedgehog"]],["Bird",["Penguin","Kiwi","Puffin","Ostrich","Emu","Kingfisher","Albatross","Toucan","Heron","Cassowary"]],["Fish",["Seahorse","Stingray","Shark","Eel","Pufferfish","Swordfish","Clownfish","Barracuda","Anchovy","Mackerel"]],
   ["Reptile",["Gecko","Iguana","Cobra","Turtle","Tortoise","Crocodile","Chameleon","Python","Alligator","Komodo dragon"]],["Amphibian",["Frog","Toad","Newt","Salamander","Axolotl","Bullfrog","Tree frog","Caecilian"]],["Insect",["Cicada","Mantis","Firefly","Flea","Earwig","Ladybug","Termite","Locust","Moth","Hornet"]]]
];

// ---------- the first six games ----------
const GAMES={
quickmath:{name:"Quick Math",skill:"Math speed",how:"Solve each problem and tap the right answer. A wrong answer costs 3 seconds.",
  time:45,target:L=>[8,9,9,10,10][L-1],
  setup(api){const L=api.level;
    const make=()=>{let q,a;const t=L===1?pick(["+","-"]):L===2?pick(["+","-","+"]):L===3?pick(["+","-","×"]):L===4?pick(["×+","×-","÷"]):pick(["%","××","×+","÷"]);
      if(t==="+"){const x=R(2,L===1?12:60),y=R(2,L===1?9:40);q=`${x} + ${y}`;a=x+y}
      else if(t==="-"){const x=R(L===1?6:20,L===1?19:99),y=R(1,x-1);q=`${x} − ${y}`;a=x-y}
      else if(t==="×"){const x=R(2,10),y=R(2,10);q=`${x} × ${y}`;a=x*y}
      else if(t==="×+"){const x=R(2,9),y=R(2,9),z=R(2,30);q=`${x} × ${y} + ${z}`;a=x*y+z}
      else if(t==="×-"){const x=R(3,9),y=R(3,9),z=R(1,x*y-1);q=`${x} × ${y} − ${z}`;a=x*y-z}
      else if(t==="÷"){const y=R(2,L===5?12:9),a0=R(2,L===5?15:10);q=`${y*a0} ÷ ${y}`;a=a0}
      else if(t==="%"){const p=pick([10,20,25,50,15,30,75]),n=pick([20,40,60,80,120,200,160]);q=`${p}% of ${n}`;a=p*n/100}
      else{const x=R(11,25),y=R(3,9);q=`${x} × ${y}`;a=x*y}
      const opts=new Set([a]);while(opts.size<4){const d=pick([1,2,3,5,10,-1,-2,-3,-5,-10]);if(a+d>=0)opts.add(a+d)}
      api.body.innerHTML=`<div class="mg-q"><small>Solve</small>${q}</div><div class="mg-opts">${shuffle([...opts]).map(o=>`<button class="mg-b" data-v="${o}">${o}</button>`).join("")}</div>`;
      const bs=api.body.querySelectorAll(".mg-b");bs.forEach(b=>b.onclick=()=>{if(+b.dataset.v===a){bs.forEach(x=>x.onclick=null);b.classList.add("ok");api.score(1);setTimeout(()=>api.alive()&&make(),180)}else{b.classList.add("no");api.penalty(3);api.shake()}})};
    make()}},

colorclash:{name:"Color Clash",skill:"Focus",how:"Tap the COLOR the word is painted in, not what the word says. A wrong tap costs 3 seconds.",
  time:45,target:L=>[12,14,15,16,17][L-1],
  setup(api){const L=api.level;const C=[["RED","#e5484d"],["BLUE","#3b82f6"],["GREEN","#22a35a"],["YELLOW","#e8b400"],["PURPLE","#8e5cf7"],["ORANGE","#f07a22"]];const n=[3,4,4,5,6][L-1];const set=C.slice(0,n);let order=set;
    const make=()=>{const word=pick(set);let ink=pick(set);while(ink===word)ink=pick(set);const flip=L===5&&Math.random()<.3;if(L>=3)order=shuffle(set);
      api.body.innerHTML=`<p class="mg-hint" style="font-weight:700;color:${flip?"#ffd23f":"inherit"}">${flip?"Careful: tap what the WORD says this time":"Tap the ink color"}</p><div class="mg-q" style="color:${ink[1]};letter-spacing:.04em">${word[0]}</div>
        <div class="mg-opts ${n>4?"c3":""}">${order.map(c=>`<button class="mg-b sm" data-c="${c[0]}" style="background:${c[1]};color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.55);box-shadow:0 6px 0 rgba(0,0,0,.35)">${L>=4?"&nbsp;":c[0]}</button>`).join("")}</div>`;
      const want=flip?word[0]:ink[0];
      api.body.querySelectorAll(".mg-b").forEach(b=>b.onclick=()=>{if(b.dataset.c===want){api.score(1);make()}else{api.penalty(3);api.shake()}})};
    make()}},

numbermemory:{name:"Number Memory",skill:"Memory",how:"A number flashes on the screen. Remember it, then type it back. Each correct answer makes the next one longer. 3 mistakes and you're out.",
  time:90,target:L=>4,lives:3,
  setup(api){let len=[3,4,5,6,6][api.level-1];
    const round=()=>{const num=Array.from({length:len},(_, i)=>i===0?R(1,9):R(0,9)).join("");
      api.body.innerHTML=`<p class="mg-hint">Remember this number</p><div class="mg-q" style="letter-spacing:.12em">${num}</div>`;
      setTimeout(()=>{if(!api.alive())return;let typed="",lock=false;
        const draw=()=>{api.body.innerHTML=`<p class="mg-hint">Type the ${len}-digit number</p><div class="mg-typed">${typed.padEnd(len,"·")}</div><div class="mg-keys">${[1,2,3,4,5,6,7,8,9,"⌫",0,"✓"].map(k=>`<button class="mg-b" data-k="${k}">${k}</button>`).join("")}</div>`;
          api.body.querySelectorAll(".mg-b").forEach(b=>b.onclick=()=>{if(lock)return;const k=b.dataset.k;if(k==="⌫")typed=typed.slice(0,-1);else if(k==="✓"){if(typed.length<len)return;check()}else if(typed.length<len)typed+=k;if(k!=="✓")draw();if(typed.length===len&&k!=="⌫"&&k!=="✓")check()})};
        const check=()=>{lock=true;if(typed===num){api.score(1);len++;api.flash(true);setTimeout(()=>api.alive()&&round(),500)}else{api.flash(false,num);api.loseLife()&&setTimeout(()=>api.alive()&&round(),1200)}};
        draw()},900+len*450)};
    round()}},

wordscramble:{name:"Word Scramble",skill:"Words",how:"Tap the letters in the right order to spell the hidden word. Skip costs 5 seconds.",
  time:60,target:L=>[6,5,5,4,4][L-1],
  setup(api){const W={1:["TENT","TREE","ROCK","HILL","KING","FISH","DUCK","JUMP","CAMP","BOOK","FROG","CAVE"],2:["CABIN","RIVER","CROWN","QUEEN","BRAVE","MAGIC","PLANT","TORCH","BEACH","GHOST"],3:["CAMPER","JUNGLE","BRIDGE","CANYON","SUMMIT","PUZZLE","LEGEND","KNIGHT","VALLEY"],4:["COMPASS","LANTERN","MOUNTAIN","VICTORY","KINGDOM","BACKPACK","JOURNEY","TREASURE"],5:["WATERFALL","ADVENTURE","CHAMPION","SHENANDOAH","BLUEBERRY","CAMPFIRE","STRATEGY"]};
    const pool=shuffle(W[api.level]);let wi=0;
    const make=()=>{const word=pool[wi++%pool.length];let letters=shuffle(word.split(""));while(letters.join("")===word)letters=shuffle(word.split(""));let built="",wait=false;const used=new Set();
      const draw=()=>{api.body.innerHTML=`<p class="mg-hint">Unscramble the word</p><div class="mg-typed">${(built||"").padEnd(word.length,"_").split("").join(" ")}</div>
        <div class="mg-tiles">${letters.map((l,i)=>`<button class="mg-b" data-i="${i}" ${used.has(i)?"disabled":""}>${l}</button>`).join("")}</div>
        <div style="display:flex;gap:10px"><button class="mg-b sm" data-a="undo">Undo</button><button class="mg-b sm" data-a="skip">Skip</button></div>`;
        api.body.querySelectorAll(".mg-b").forEach(b=>b.onclick=()=>{if(wait)return;if(b.dataset.a==="undo"){if(!built)return;built=built.slice(0,-1);used.delete([...used].pop());draw();return}
          if(b.dataset.a==="skip"){api.penalty(5);make();return}const i=+b.dataset.i;used.add(i);built+=letters[i];
          if(built.length===word.length){if(built===word){wait=true;api.score(1);setTimeout(()=>api.alive()&&make(),400)}else{api.shake();built="";used.clear();wait=true;setTimeout(()=>{wait=false;api.alive()&&draw()},300)}return}draw()})};
      draw()};
    make()}},

biggerorsmaller:{name:"Bigger or Smaller",skill:"Estimation",how:"Two values appear. Tap the bigger one as fast as you can. A wrong tap costs 3 seconds.",
  time:45,target:L=>[10,11,11,12,12][L-1],
  setup(api){const L=api.level;
    const val=()=>{const t=L===1?"n":L===2?pick(["sum","prod"]):L===3?pick(["frac","prod"]):L===4?pick(["pct","frac","dec"]):pick(["pct","frac","dec","prod","sq"]);
      if(t==="n"){const v=R(10,99);return [String(v),v]}
      if(t==="sum"){const a=R(10,60),b=R(5,40);return [`${a} + ${b}`,a+b]}
      if(t==="prod"){const a=R(3,L===5?19:12),b=R(3,12);return [`${a} × ${b}`,a*b]}
      if(t==="frac"){const d=R(3,L===5?13:9),n=R(1,d-1);return [`${n}/${d}`,n/d]}
      if(t==="dec"){const v=R(5,95)/100;return [String(v),v]}
      if(t==="pct"){const p=R(5,95);return [`${p}%`,p/100]}
      const a=R(4,15);return [`${a}²`,a*a]};
    const make=()=>{let a=val(),b=val(),g=0;const sameKind=x=>x[1]<=1;while((a[1]===b[1]||sameKind(a)!==sameKind(b)||(L>=4&&Math.abs(a[1]-b[1])>Math.max(a[1],b[1])*.35))&&g++<60)b=val();
      if(a[1]===b[1])b=[String(+(b[1]+1).toFixed(2)),b[1]+1];
      api.body.innerHTML=`<p class="mg-hint">Which is bigger?</p><div class="mg-opts">${[a,b].map((x,i)=>`<button class="mg-b" data-i="${i}" style="font-size:clamp(28px,5vw,44px);padding:34px 10px">${x[0]}</button>`).join("")}</div>`;
      const big=a[1]>b[1]?0:1;api.body.querySelectorAll(".mg-b").forEach(btn=>btn.onclick=()=>{if(+btn.dataset.i===big){api.score(1);make()}else{api.penalty(3);api.shake()}})};
    make()}},

gridrecall:{name:"Grid Recall",skill:"Visual memory",how:"Some squares light up for a moment. Tap the same squares from memory. Every round adds one more square. 3 mistakes and you're out.",
  time:120,target:L=>5,lives:3,
  setup(api){const L=api.level;const n=[3,4,4,5,5][L-1];let k=[3,4,5,6,7][L-1];
    const round=()=>{const cells=shuffle([...Array(n*n).keys()]).slice(0,Math.min(k,n*n-1));const hit=new Set();let ready=false;
      api.body.innerHTML=`<p class="mg-hint">Remember the ${cells.length} yellow squares</p><div class="mg-grid" style="grid-template-columns:repeat(${n},1fr)">${[...Array(n*n).keys()].map(i=>`<button class="mg-cell${cells.includes(i)?" lit":""}" data-i="${i}" aria-label="Square ${i+1}"></button>`).join("")}</div>`;
      setTimeout(()=>{if(!api.alive())return;api.body.querySelectorAll(".mg-cell").forEach(c=>c.classList.remove("lit"));api.body.querySelector(".mg-hint").textContent="Now tap them";ready=true},1000+cells.length*250);
      api.body.querySelectorAll(".mg-cell").forEach(c=>c.onclick=()=>{if(!ready)return;const i=+c.dataset.i;if(hit.has(i))return;
        if(cells.includes(i)){hit.add(i);c.classList.add("hit");if(hit.size===cells.length){ready=false;api.score(1);k++;setTimeout(()=>api.alive()&&round(),600)}}
        else{c.classList.add("bad");ready=false;cells.forEach(j=>api.body.querySelector(`[data-i="${j}"]`).classList.add("lit"));api.loseLife()&&setTimeout(()=>api.alive()&&round(),1100)}})};
    round()}},

// ---------- twelve more games (V2) ----------
liquidsort:{name:"Liquid Lab",skill:"Logic",how:"Tap a tube, then tap another to pour. Liquid only pours onto the same color or into an empty tube. Get one color in every tube. Undo is free.",
  time:L=>[150,210,270,330,420][L-1],target:L=>1,goal:()=>"Sort every tube before the timer runs out to win.",
  setup(api){const L=api.level,cap=4;let t=liquidPuzzle(L);const start=t.map(x=>x.slice());let hist=[],sel=-1,moves=0,done=false;const n=t.length;
    const S=api.size();let best={w:0,r:1};for(let r=1;r<=3;r++){const k=Math.ceil(n/r),w=Math.min(64,(S.w-(k-1)*10-4)/k,(S.h-120-(r-1)*28-18)/(r*4.4));if(w>best.w+2)best={w,r}}
    const w=Math.floor(best.w),per=Math.ceil(n/best.r);
    const draw=()=>{api.body.innerHTML=`<p class="mg-hint">${sel<0?"Tap a tube to pick it up":"Now tap the tube to pour into"} · Moves: ${moves}</p>
      <div class="mg-tubes" style="--w:${w}px;max-width:${per*w+(per-1)*10+2}px">${t.map((x,i)=>`<button class="mg-tube${i===sel?" sel":""}" data-i="${i}" aria-label="Tube ${i+1}">${x.map(v=>`<i style="background:${LQ[v]}"></i>`).join("")}</button>`).join("")}</div>
      <div style="display:flex;gap:10px"><button class="mg-b sm" data-a="undo" ${hist.length?"":"disabled"}>Undo</button><button class="mg-b sm" data-a="reset" ${moves?"":"disabled"}>Restart</button></div>`;
      api.body.querySelectorAll("[data-a]").forEach(b=>b.onclick=()=>{if(done)return;if(b.dataset.a==="undo"){if(!hist.length)return;t=hist.pop();moves--}else{if(!moves)return;t=start.map(x=>x.slice());hist=[];moves=0}sel=-1;draw()});
      api.body.querySelectorAll(".mg-tube").forEach(b=>b.onclick=()=>{if(done)return;const i=+b.dataset.i;
        if(sel<0){if(t[i].length)sel=i;draw();return}
        if(sel===i){sel=-1;draw();return}
        const before=t.map(x=>x.slice());if(lqPour(t,sel,i,cap)){hist.push(before);moves++;sel=-1;draw();if(lqDone(t,cap)){done=true;setTimeout(()=>api.alive()&&api.score(1),450)}}
        else{api.shake();sel=t[i].length?i:-1;draw()}})};
    draw()}},

echotiles:{name:"Echo Tiles",skill:"Memory",how:"Watch the tiles light up, then tap them in the same order. Every round adds one more step. 3 mistakes and you're out.",
  time:150,target:L=>[4,5,5,6,6][L-1],lives:3,
  setup(api){const L=api.level,n=[4,4,6,9,9][L-1],cols=[2,2,3,3,3][L-1],on=[700,560,480,420,330][L-1],seq=[];for(let i=0;i<[3,3,4,4,5][L-1];i++)seq.push(R(0,n-1));
    const sz=api.fit(cols,Math.ceil(n/cols),50,140,12);
    api.body.innerHTML=`<p class="mg-hint" id="mgEh">Watch</p><div class="mg-grid" style="grid-template-columns:repeat(${cols},${sz}px);width:auto;gap:12px">${[...Array(n).keys()].map(i=>`<button class="mg-pad" data-i="${i}" style="--c:${PAL[i]};width:${sz}px;height:${sz}px" aria-label="Tile ${i+1}"></button>`).join("")}</div>`;
    const pads=[...api.body.querySelectorAll(".mg-pad")],hint=api.body.querySelector("#mgEh");let ready=false,pos=0;
    const lit=(i,ms)=>{pads[i].classList.add("lit");setTimeout(()=>pads[i].classList.remove("lit"),ms)};
    const show=()=>{ready=false;pos=0;hint.textContent=`Watch ${seq.length} steps`;let k=0;const step=()=>{if(!api.alive())return;if(k>=seq.length){hint.textContent="Your turn";ready=true;return}lit(seq[k++],on);setTimeout(step,on+200)};setTimeout(step,700)};
    pads.forEach((p,i)=>p.onclick=()=>{if(!ready)return;lit(i,220);
      if(i===seq[pos]){pos++;if(pos===seq.length){ready=false;hint.textContent="Nice!";seq.push(R(0,n-1));api.score(1);setTimeout(()=>api.alive()&&show(),700)}}
      else{ready=false;api.shake();hint.textContent="Oops, watch again";api.loseLife()&&setTimeout(()=>api.alive()&&show(),900)}});
    show()}},

oddone:{name:"Odd One Out",skill:"Focus",how:"All the shapes are the same except one. Find it and tap it. A wrong tap costs 3 seconds.",
  time:45,target:L=>[8,9,10,10,10][L-1],
  setup(api){const L=api.level,n=[3,4,5,5,6][L-1],sz=api.fit(n,n,40,96,8);
    const make=()=>{const odd=R(0,n*n-1),t=L===1?"shape":L===2?pick(["shape","hue"]):pick(["light","rot","light"]),h=R(0,359);let base,o1;
      if(t==="shape"){const [a,b]=shuffle(SHAPES),c=pick(PAL.slice(0,9));base=svg(a,c);o1=svg(b,c)}
      else if(t==="hue"){const k=pick(SHAPES);base=svg(k,`hsl(${h},75%,60%)`);o1=svg(k,`hsl(${(h+R(80,140))%360},75%,60%)`)}
      else if(t==="light"){const k=pick(SHAPES),d=[0,0,20,13,8][L-1]*pick([1,-1]);base=svg(k,`hsl(${h},70%,58%)`);o1=svg(k,`hsl(${h},70%,${58+d}%)`)}
      else{const c=`hsl(${h},75%,62%)`,r0=pick([0,90,180,270]),d=[0,0,90,40,20][L-1]*pick([1,-1]);base=svg("arrow",c,r0);o1=svg("arrow",c,r0+d)}
      api.body.innerHTML=`<p class="mg-hint">Tap the odd one out</p><div class="mg-grid" style="grid-template-columns:repeat(${n},${sz}px);width:auto">${[...Array(n*n).keys()].map(i=>`<button class="mg-cell mg-sh" data-i="${i}" style="width:${sz}px;height:${sz}px" aria-label="Shape ${i+1}">${i===odd?o1:base}</button>`).join("")}</div>`;
      api.body.querySelectorAll(".mg-cell").forEach(b=>b.onclick=()=>{if(+b.dataset.i===odd){api.score(1);make()}else{b.classList.add("bad");api.penalty(3);api.shake()}})};
    make()}},

flashcount:{name:"Flash Count",skill:"Estimation",how:"Shapes flash on the screen for a moment. Count them, then pick the right number. 3 mistakes and you're out.",
  time:100,target:L=>[6,6,7,7,8][L-1],lives:3,
  setup(api){const L=api.level,[kinds,lo,hi,ms]=[[1,3,6,1600],[1,6,10,1300],[2,7,11,1300],[3,9,14,1100],[3,11,16,1100]][L-1],fs=api.fit(1,1,60,440,0);
    const round=()=>{const ks=shuffle(SHAPES).slice(0,kinds),cs=shuffle(PAL.slice(0,9)).slice(0,kinds),tot=R(lo,hi),items=[...Array(tot)].map((_,i)=>i<kinds?i:R(0,kinds-1));
      const ask=R(0,kinds-1),ans=items.filter(k=>k===ask).length,s=L<3?14:12,pts=[],col=k=>L===5?cs[0]:cs[k];
      for(const k of items){let p,g=0;do{p=[R(2,98-s),R(2,98-s)]}while(g++<400&&pts.some(q=>Math.hypot(q[0]-p[0],q[1]-p[1])<s*1.2));pts.push(p)}
      api.body.innerHTML=`<p class="mg-hint">${kinds>1?"Count each kind of shape":"Count the shapes"}</p><div class="mg-field" style="width:${fs}px;height:${fs}px">${items.map((k,i)=>`<span style="left:${pts[i][0]}%;top:${pts[i][1]}%;width:${s}%;height:${s}%">${svg(ks[k],col(k),L>=4?R(-30,30):0)}</span>`).join("")}</div>`;
      setTimeout(()=>{if(!api.alive())return;const opts=new Set([ans]);while(opts.size<4){const v=ans+pick([-3,-2,-1,1,2,3]);if(v>=1)opts.add(v)}
        api.body.innerHTML=`<div class="mg-q"><small>${kinds>1?"How many of this shape?":"How many shapes?"}</small><span class="mg-qs">${svg(ks[ask],col(ask))}</span></div><div class="mg-opts">${[...opts].sort((a,b)=>a-b).map(o=>`<button class="mg-b" data-v="${o}">${o}</button>`).join("")}</div>`;
        const bs=api.body.querySelectorAll(".mg-b");bs.forEach(b=>b.onclick=()=>{bs.forEach(x=>x.onclick=null);
          if(+b.dataset.v===ans){b.classList.add("ok");api.score(1);setTimeout(()=>api.alive()&&round(),500)}
          else{b.classList.add("no");api.flash(false,ans);api.loseLife()&&setTimeout(()=>api.alive()&&round(),1300)}})},ms)};
    round()}},

numberhunt:{name:"Number Hunt",skill:"Speed",how:"Tap the numbers in order, 1, 2, 3 and on, as fast as you can. A wrong tap costs 2 seconds.",
  time:L=>[40,45,70,60,90][L-1],target:L=>[18,16,25,25,36][L-1],goal:L=>L===1?"Clear 2 boards before the timer runs out to win.":"Tap every number before the timer runs out to win.",
  setup(api){const L=api.level,n=[3,4,5,5,6][L-1],sz=api.fit(n,n,50,100,6);let next=1;
    const board=()=>{next=1;const nums=shuffle([...Array(n*n)].map((_,i)=>i+1));
      api.body.innerHTML=`<p class="mg-hint">Next: <b id="mgNx" style="color:#ffd23f;font-size:20px">1</b></p><div class="mg-grid" style="grid-template-columns:repeat(${n},${sz}px);width:auto;gap:6px">${nums.map(v=>{const st=L>=4?`color:${pick(["#15181e","#7a2030","#1e3a7a","#245c34","#5a2a7a"])};background:${pick(["#eef1f6","#ffe08a","#c9e3ff","#ffd0dc","#d4f09a"])};font-size:${Math.round(sz*(.28+Math.random()*.24))}px`:`font-size:${Math.round(sz*.42)}px`;return `<button class="mg-num" data-v="${v}" style="width:${sz}px;height:${sz}px;${st}">${v}</button>`}).join("")}</div>`;
      api.body.querySelectorAll(".mg-num").forEach(b=>b.onclick=()=>{const v=+b.dataset.v;
        if(v===next){if(L<5)b.classList.add("done");next++;api.score(1);if(!api.alive())return;const nx=api.body.querySelector("#mgNx");if(nx)nx.textContent=next>n*n?"✓":next;if(next>n*n)setTimeout(()=>api.alive()&&board(),350)}
        else if(v>next){b.classList.add("no");setTimeout(()=>b.classList.remove("no"),300);api.penalty(2);api.shake()}})};
    board()}},

turntwin:{name:"Turn Twin",skill:"Spatial",how:"Which shape is the same as the top one, only turned around? Flipped (mirror) shapes don't count. A wrong tap costs 4 seconds.",
  time:L=>[50,60,60,70,75][L-1],target:L=>[6,7,7,8,8][L-1],
  setup(api){const L=api.level,[n,k,no,mir]=[[3,4,2,0],[3,5,3,0],[4,5,3,1],[4,6,4,2],[5,7,4,3]][L-1],S=api.size(),cols=S.w>620?no:(no===4?2:no);
    const big=Math.floor(Math.min(160,S.h*.26,S.w*.4)),sm=Math.floor(Math.max(56,Math.min(130,(Math.min(S.w,720)-(cols-1)*12)/cols-28,(S.h-big-130)/Math.ceil(no/cols)-40)));
    const make=()=>{for(let g=0;g<500;g++){const o=pGrow(k,n),mk=pKey(pMir(o)),rk=pRots(o).map(pKey);if(mir&&rk.includes(mk))continue;
        const ban=new Set([...rk,...pRots(pMir(o)).map(pKey)]),turns=pRots(o).slice(1),good=turns.filter(x=>pKey(x)!==pKey(o));if(!good.length)continue;const right=pick(good),opts=[right],used=new Set([pKey(right)]);
        for(const m of shuffle(pRots(pMir(o)))){if(opts.length>=1+mir)break;if(!used.has(pKey(m))){used.add(pKey(m));opts.push(m)}}
        for(let h=0;h<200&&opts.length<no;h++){const a=pAlter(o,n);if(!a||ban.has(pKey(a)))continue;const d=pick(pRots(a));if(used.has(pKey(d)))continue;used.add(pKey(d));opts.push(d)}
        if(opts.length<no)continue;const order=shuffle(opts.map((x,i)=>i));
        api.body.innerHTML=`<div class="mg-tw">${pDraw(o,n,big,"#4f8dff")}</div><p class="mg-hint">Tap the same shape, only turned</p><div class="mg-opts" style="grid-template-columns:repeat(${cols},auto);width:auto">${order.map(i=>`<button class="mg-b" data-i="${i}" style="padding:12px">${pDraw(opts[i],n,sm,"#4f8dff")}</button>`).join("")}</div>`;
        api.body.querySelectorAll(".mg-b").forEach(b=>b.onclick=()=>{if(+b.dataset.i===0){api.score(1);make()}else{b.classList.add("no");api.penalty(4);api.shake()}});return}};
    make()}},

pairup:{name:"Pair Up",skill:"Visual memory",how:"Flip two cards at a time. Matching cards stay open. Find every pair.",
  time:L=>[60,75,90,110,130][L-1],target:L=>[6,8,10,12,15][L-1],goal:()=>"Find all the pairs before the timer runs out to win.",
  setup(api){const L=api.level,pairs=[6,8,10,12,15][L-1],S=api.size(),wide=S.w>S.h,[c1,r1]=[[4,3],[4,4],[5,4],[6,4],[6,5]][L-1],cols=wide?c1:r1,rows=wide?r1:c1,sz=api.fit(cols,rows,40,112,8);
    let faces;if(L<=3)faces=[...Array(pairs)].map((_,i)=>[SHAPES[i%8],PAL[i]]);else{faces=[];const cs=shuffle(PAL.slice(0,9)).slice(0,3);shuffle(SHAPES).slice(0,L===4?4:5).forEach(k=>cs.forEach(c=>faces.push([k,c])))}
    const deck=shuffle([...faces.keys(),...faces.keys()]),gone=new Set();let open=[],lock=false;
    api.body.innerHTML=`<p class="mg-hint" id="mgPh">Find the pairs</p><div class="mg-grid" style="grid-template-columns:repeat(${cols},${sz}px);width:auto">${deck.map((f,i)=>`<button class="mg-card" data-i="${i}" style="width:${sz}px;height:${sz}px" aria-label="Card ${i+1}"><span>${svg(faces[f][0],faces[f][1])}</span></button>`).join("")}</div>`;
    const cards=[...api.body.querySelectorAll(".mg-card")],ph=api.body.querySelector("#mgPh"),peek=[2500,1500,0,0,0][L-1];
    if(peek){lock=true;cards.forEach(c=>c.classList.add("up"));ph.textContent="Remember them!";setTimeout(()=>{if(!api.alive())return;cards.forEach(c=>c.classList.remove("up"));lock=false;ph.textContent="Find the pairs"},peek)}
    cards.forEach((c,i)=>c.onclick=()=>{if(lock||gone.has(i)||open.includes(i))return;c.classList.add("up");open.push(i);if(open.length<2)return;const [a,b]=open;open=[];
      if(deck[a]===deck[b]){gone.add(a);gone.add(b);setTimeout(()=>{cards[a].classList.add("gone");cards[b].classList.add("gone")},300);api.score(1)}
      else{lock=true;setTimeout(()=>{cards[a].classList.remove("up");cards[b].classList.remove("up");lock=false},800)}})}},

slidepuzzle:{name:"Slide Puzzle",skill:"Spatial",how:"Slide the tiles back into order 1, 2, 3 and on, with the gap at the end. Tap any tile in the same row or column as the gap to slide it.",
  time:L=>[90,120,150,200,270][L-1],target:L=>1,goal:()=>"Put every tile in order before the timer runs out to win.",
  setup(api){const L=api.level,n=[3,3,3,4,4][L-1],mv=[10,25,80,35,90][L-1],G=6,sz=api.fit(n,n,60,110,G),N=n*n,solved=b=>b.every((v,j)=>v===(j+1)%N);
    let b=[...Array(N).keys()].map(i=>(i+1)%N),gap=N-1,last=-1,moves=0;
    for(let i=0;i<mv||solved(b);i++){const gx=gap%n,gy=gap/n|0,nb=[[gx-1,gy],[gx+1,gy],[gx,gy-1],[gx,gy+1]].filter(([x,y])=>x>=0&&y>=0&&x<n&&y<n).map(([x,y])=>y*n+x).filter(p=>p!==last),p=pick(nb);b[gap]=b[p];b[p]=0;last=gap;gap=p}
    const W=n*sz+(n-1)*G;
    api.body.innerHTML=`<p class="mg-hint" id="mgSm">Moves: 0</p><div class="mg-slide" style="width:${W}px;height:${W}px">${[...Array(N-1)].map((_,i)=>`<button class="mg-tile" data-v="${i+1}" style="width:${sz}px;height:${sz}px;font-size:${Math.round(sz*.4)}px;background:hsl(${((i/n|0)*300/n+20)%360},80%,74%)">${i+1}</button>`).join("")}</div>`;
    const tiles={};api.body.querySelectorAll(".mg-tile").forEach(t=>tiles[t.dataset.v]=t);
    const place=()=>b.forEach((v,p)=>{if(v)tiles[v].style.transform=`translate(${(p%n)*(sz+G)}px,${(p/n|0)*(sz+G)}px)`});place();
    let done=false;Object.values(tiles).forEach(t=>t.onclick=()=>{if(done)return;const p=b.indexOf(+t.dataset.v),px=p%n,py=p/n|0,gx=gap%n,gy=gap/n|0;if(px!==gx&&py!==gy){api.shake();return}
      const step=px===gx?(py>gy?n:-n):(px>gx?1:-1);while(gap!==p){const q=gap+step;b[gap]=b[q];b[q]=0;gap=q}moves++;api.body.querySelector("#mgSm").textContent="Moves: "+moves;place();
      if(solved(b)){done=true;setTimeout(()=>api.alive()&&api.score(1),350)}})}},

arrowstorm:{name:"Arrow Storm",skill:"Focus",how:"Tap the way the MIDDLE arrow points and ignore the arrows around it. A wrong tap costs 3 seconds.",
  time:40,target:L=>[12,13,14,15,15][L-1],
  setup(api){const L=api.level,dirs=L<3?[0,180]:[0,90,180,270],cnt=L<4?5:7,DN={0:"right",90:"down",180:"left",270:"up"},S=api.size(),aw=Math.floor(Math.min(64,(Math.min(S.w,620)-20)/cnt-6));
    const make=()=>{const mid=pick(dirs),outer=L===5&&Math.random()<.3,cong=!outer&&Math.random()<[.6,.3,.3,.25,.25][L-1],o=pick(dirs.filter(d=>d!==mid));
      const fl=Array(cnt).fill(0).map(()=>cong?mid:(L===3||L===4)&&Math.random()<.3?pick(dirs.filter(d=>d!==mid)):o),c=cnt>>1;fl[c]=mid;const want=outer?o:mid;
      const room=Math.max(0,Math.min(40,(S.w-cnt*(aw+6))/2-8)),off=L>=4?`transform:translate(${R(-room,room)}px,${R(-24,24)}px)`:"";
      api.body.innerHTML=`<p class="mg-hint" style="font-weight:700;color:${outer?"#ff9fb8":"inherit"}">${outer?"Pink arrows: tap where the OUTER arrows point":"Where does the middle arrow point?"}</p>
        <div class="mg-arrows" style="${off}">${fl.map((d,i)=>`<span style="width:${aw}px;height:${aw}px">${svg("arrow",outer?"#ff9fb8":i===c&&L===1?"#ffd23f":"#eef1f6",d+90)}</span>`).join("")}</div>
        <div class="mg-dpad${dirs.length>2?" four":""}">${dirs.map(d=>`<button class="mg-b" data-d="${d}" style="grid-area:${DN[d]}" aria-label="${DN[d]}"><span class="mg-ai">${svg("arrow","#15181e",d+90)}</span></button>`).join("")}</div>`;
      api.body.querySelectorAll(".mg-b").forEach(b=>b.onclick=()=>{if(+b.dataset.d===want){api.score(1);make()}else{api.penalty(3);api.shake()}})};
    make()}},

truefalse:{name:"True or False",skill:"Math speed",how:"Is the sum right? Tap True or False as fast as you can. A wrong tap costs 3 seconds.",
  time:45,target:L=>[12,12,13,13,14][L-1],
  setup(api){const L=api.level;
    const make=()=>{let q,a,w=[1,-1,2,-2,10,-10];
      if(L===1){const x=R(1,12),y=R(1,9);if(Math.random()<.5){q=`${x} + ${y}`;a=x+y}else{q=`${x+y} − ${y}`;a=x}w=[1,-1,2,-2]}
      else if(L===2){const x=R(12,69),y=R(6,39);if(Math.random()<.5){q=`${x} + ${y}`;a=x+y}else{q=`${x+y} − ${y}`;a=x}}
      else if(L===3){const x=R(3,10),y=R(3,10);q=`${x} × ${y}`;a=x*y;w=[x,-x,y,-y,1,-1]}
      else if(L===4){const x=R(3,9),y=R(3,9),z=R(2,20);if(Math.random()<.5){q=`${x} × ${y} + ${z}`;a=x*y+z}else{q=`${x} × ${y} − ${z}`;a=x*y-z}}
      else{const t=R(0,2);if(t===0){const x=R(4,9),y=R(4,9),u=R(2,4),v=R(2,4);q=`${x} × ${y} − ${u} × ${v}`;a=x*y-u*v}else if(t===1){const d=R(3,9),k=R(3,12),z=R(2,30);q=`${d*k} ÷ ${d} + ${z}`;a=k+z}else{const x=R(12,19),y=R(3,8);q=`${x} × ${y}`;a=x*y}}
      const truth=Math.random()<.5;let shown=a;if(!truth){const d=pick(w);shown=a+d<0?a+Math.abs(d):a+d}
      api.body.innerHTML=`<div class="mg-q" style="font-size:clamp(26px,5.5vw,48px)"><small>True or false?</small>${q} = ${shown}</div><div class="mg-opts"><button class="mg-b" data-v="1" style="background:#9df0c8">True</button><button class="mg-b" data-v="0" style="background:#ffb3c1">False</button></div>`;
      api.body.querySelectorAll(".mg-b").forEach(b=>b.onclick=()=>{if((b.dataset.v==="1")===truth){api.score(1);make()}else{b.classList.add("no");api.penalty(3);api.shake()}})};
    make()}},

nextnumber:{name:"Number Pattern",skill:"Logic",how:"Find the rule behind the numbers and pick the missing one. A wrong tap costs 5 seconds.",
  time:60,target:L=>[6,6,7,7,7][L-1],
  setup(api){const L=api.level,len=L<3?5:6;let last="";
    const gen=()=>{const t=pick({1:["add"],2:["add","sub","dbl"],3:["alt","mul","sq","add2"],4:["grow","sq","dblp","alt","mul"],5:["fib","tri","inter","dblm","sqp","grow2"]}[L]);let s=[];const F=f=>{for(let i=0;i<len;i++)s.push(f(i))};
      if(t==="add"){const a=R(1,10),d=R(2,5);F(i=>a+i*d)}
      else if(t==="add2"){const a=R(5,40),d=R(6,15);F(i=>a+i*d)}
      else if(t==="sub"){const a=R(50,95),d=R(3,9);F(i=>a-i*d)}
      else if(t==="dbl"){const a=R(1,5);F(i=>a*2**i)}
      else if(t==="mul"){const a=R(1,2),m=L>3?pick([3,4]):3;s=[a];while(s.length<len-(m>3?1:0))s.push(s[s.length-1]*m)}
      else if(t==="alt"){const a=R(1,10),p=R(2,7);let q=R(-4,9);while(q===p||q===0)q=R(-4,9);s=[a];while(s.length<len)s.push(s[s.length-1]+(s.length%2?p:q))}
      else if(t==="sq"){const a=R(1,5);F(i=>(a+i)**2)}
      else if(t==="grow"){const a=R(1,10),d=R(1,3);s=[a];while(s.length<len)s.push(s[s.length-1]+d*s.length)}
      else if(t==="dblp"){s=[R(1,4)];while(s.length<len)s.push(s[s.length-1]*2+1)}
      else if(t==="fib"){s=[R(1,4),R(2,6)];while(s.length<len)s.push(s[s.length-1]+s[s.length-2])}
      else if(t==="tri"){const b=R(1,4);F(i=>(b+i)*(b+i+1)/2)}
      else if(t==="inter"){const a=R(1,9),d=R(2,5),b=R(30,50),e=R(2,5);F(i=>i%2?b-((i-1)/2)*e:a+(i/2)*d)}
      else if(t==="dblm"){s=[R(2,5)];while(s.length<len)s.push(s[s.length-1]*2-1)}
      else if(t==="sqp"){const a=R(1,4),k=pick([1,-1,2]);F(i=>(a+i)**2+k)}
      else{s=[R(2,8)];while(s.length<len)s.push(s[s.length-1]+2**(s.length-1))}
      return {s,miss:L<4?s.length-1:R(1,s.length-1)}};
    const make=()=>{let g;do g=gen();while(g.s.join()===last);last=g.s.join();const {s,miss}=g,ans=s[miss],d=Math.abs(s[1]-s[0])||3;
      const opts=new Set([ans]);for(const v of shuffle([ans+1,ans-1,ans+2,ans-2,ans+d,ans-d,ans+2*d]))if(opts.size<4&&v>=0&&v!==ans)opts.add(v);
      api.body.innerHTML=`<div class="mg-q mg-seq"><small>What's missing?</small><div>${s.map((v,i)=>i===miss?`<b class="mg-miss">?</b>`:`<span>${v}</span>`).join("")}</div></div><div class="mg-opts">${[...opts].sort((a,b)=>a-b).map(o=>`<button class="mg-b" data-v="${o}">${o}</button>`).join("")}</div>`;
      const bs=api.body.querySelectorAll(".mg-b");bs.forEach(b=>b.onclick=()=>{if(+b.dataset.v===ans){bs.forEach(x=>x.onclick=null);b.classList.add("ok");api.score(1);setTimeout(()=>api.alive()&&make(),250)}else{b.classList.add("no");api.penalty(5);api.shake()}})};
    make()}},

wordsort:{name:"Word Sort",skill:"Words",how:"A word appears. Tap the group it belongs to as fast as you can. A wrong tap costs 3 seconds.",
  time:45,target:L=>[10,10,11,12,12][L-1],
  setup(api){const L=api.level,cats=WS[L-1];let order=cats.map((_,i)=>i),last="";
    const make=()=>{const ci=R(0,cats.length-1);let w;do w=pick(cats[ci][1]);while(w===last);last=w;if(L===5)order=shuffle(order);
      api.body.innerHTML=`<p class="mg-hint">Where does this word belong?</p><div class="mg-q"><small>Word</small>${w}</div><div class="mg-opts ${cats.length%3===0?"c3":""}">${order.map(i=>`<button class="mg-b sm" data-i="${i}">${cats[i][0]}</button>`).join("")}</div>`;
      api.body.querySelectorAll(".mg-b").forEach(b=>b.onclick=()=>{if(+b.dataset.i===ci){api.score(1);make()}else{b.classList.add("no");api.penalty(3);api.shake()}})};
    make()}}
};

function play(el,o){css();const G=GAMES[o.game],L=Math.max(1,Math.min(5,o.level||1)),target=G.target(L),color=o.color||"#7ee8f0";
  let score=0,left=typeof G.time==="function"?G.time(L):G.time,lives=G.lives||0,timer=null,over=false;
  if(o.elapsed>0)left=Math.max(5,Math.round(left-o.elapsed)); // came back to a race already running: only the time that's left
  const lv=`<span class="mg-lv">${[1,2,3,4,5].map(i=>`<i class="${i<=L?"on":""}"></i>`).join("")}</span>`;
  el.innerHTML=`<div class="mg" style="--x:${color}"><div class="mg-top"><div class="ttl">${G.name}${lv}<small>${esc(o.team?o.team+" · ":"")}Level ${L} of 5 · ${G.skill}</small></div>
    <span class="mg-chip" id="mgS">0 / ${target}</span>${lives?`<span class="mg-chip mg-lives" id="mgL">${"♥".repeat(lives)}</span>`:""}<span class="mg-clock" id="mgT">${left}</span></div><div class="mg-body" id="mgB"></div></div>`;
  const root=el.querySelector(".mg"),body=el.querySelector("#mgB"),$=s=>el.querySelector(s);
  const api={level:L,body,alive:()=>!over,
    size:()=>({w:body.clientWidth||360,h:body.clientHeight||500}),
    fit(cols,rows,reserve=0,max=110,gap=8){const z=api.size();return Math.max(24,Math.floor(Math.min(max,(z.w-(cols-1)*gap)/cols,(z.h-reserve-(rows-1)*gap)/rows)))},
    score(n){score+=n;$("#mgS").textContent=`${score} / ${target}`;if(score>=target)finish()},
    penalty(s){left=Math.max(0,left-s);$("#mgT").textContent=left;if(left<=0)finish()},
    shake(){body.classList.remove("mg-shake");void body.offsetWidth;body.classList.add("mg-shake")},
    flash(ok,ans){if(!ok){const p=document.createElement("p");p.className="mg-hint";p.style.color="#ff8f8f";p.textContent=ans?`It was ${ans}`:"Not quite";body.appendChild(p);api.shake()}},
    loseLife(){lives--;$("#mgL").textContent="♥".repeat(Math.max(0,lives))||"–";if(lives<=0){finish();return false}return true}};
  function finish(){if(over)return;over=true;clearInterval(timer);const win=score>=target;
    if(o.onFinish)o.onFinish({win,score,left:win?left:0,target});
    const d=document.createElement("div");d.className="mg-end";d.innerHTML=`<div class="box"><div class="big" style="color:${win?"#7be08a":"#ff8f8f"}">${win?"You did it!":"Not this time"}</div><p class="mg-hint" style="margin:0 auto">${o.team?esc(o.team)+" scored":"You scored"} ${score} of ${target} on ${G.name}, level ${L}.</p><button class="mg-start" style="--x:${win?"#7be08a":"#ff8f8f"}">${o.doneLabel||"Continue"}</button></div>`;
    root.appendChild(d);d.querySelector("button").onclick=()=>o.onDone&&o.onDone(win,score)}
  const go=()=>{G.setup(api);timer=setInterval(()=>{left--;$("#mgT").textContent=left;if(left<=0)finish()},1000)};
  if(o.autostart){go();return {stop(){over=true;clearInterval(timer)}}} // playing together: the shared countdown already ran
  // intro, countdown, play
  body.innerHTML=`<div class="mg-q" style="font-size:clamp(24px,4vw,34px)"><small>How to play</small>${G.name}</div><p class="mg-hint">${G.how} ${G.goal?G.goal(L,target):`Reach <b>${target}</b>${lives?"":" before the timer runs out"} to win.`}</p><button class="mg-start" id="mgGo">Start level ${L}</button>`;
  $("#mgGo").onclick=()=>{let c=3;const cd=()=>{if(over)return;if(c===0){go();return}body.innerHTML=`<div class="mg-cd">${c--}</div>`;setTimeout(cd,650)};cd()};
  return {stop(){over=true;clearInterval(timer)}}}

window.MindGames={list:Object.keys(GAMES).map(id=>({id,name:GAMES[id].name,skill:GAMES[id].skill,how:GAMES[id].how})),play,
  liquidPuzzle,liquidSolve:lqSolve}; // the last two are for testing that every Liquid Lab puzzle can be solved
})();
