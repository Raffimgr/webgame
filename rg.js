/* Ridge Games shared helpers: talking to api.php, the login screen, photo shrinking, avatars, periods,
   and the bottom bar (Trip / Games / Feed / Chat / To-do / Me) on the main pages. Used by every page that loads it. */
(function(){
const CSS=`
.rg-gate{position:fixed;inset:0;z-index:50;display:grid;place-items:center;padding:16px;background:rgba(15,17,21,.86);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px)}
.rg-card{width:min(460px,100%);max-height:92vh;overflow:auto;background:#2a2e35;border-radius:26px;padding:22px;box-shadow:0 24px 60px rgba(0,0,0,.6);display:flex;flex-direction:column;gap:14px;color:#eef1f6;font-family:"Inter",system-ui,sans-serif}
.rg-card h2{margin:0;font:600 24px "Fredoka","Inter",system-ui}
.rg-card p{margin:0;color:#9aa3b3;font-size:14px}
.rg-who{display:grid;grid-template-columns:repeat(auto-fill,minmax(74px,1fr));gap:10px}
.rg-who button{display:flex;flex-direction:column;align-items:center;gap:6px;padding:10px 4px;border-radius:16px;border:0;background:#202328;color:#eef1f6;font:600 12.5px "Inter",system-ui;cursor:pointer;box-shadow:inset 3px 3px 8px rgba(0,0,0,.5)}
.rg-who .scr{grid-column:1/-1;flex-direction:row;justify-content:center;gap:10px;padding:10px;font-size:13.5px}
.rg-who button.on{background:#33383f;box-shadow:0 0 0 2px var(--x),0 0 14px color-mix(in srgb,var(--x) 40%,transparent)}
.rg-in{width:100%;padding:13px 14px;border-radius:14px;border:0;background:#202328;color:#eef1f6;font:500 16px "Inter",system-ui;box-shadow:inset 3px 3px 8px rgba(0,0,0,.5)}
.rg-in:focus{outline:2px solid #2ec4b6}
.rg-btn{border:0;cursor:pointer;padding:13px 18px;border-radius:15px;font:700 15px "Inter",system-ui;background:#2ec4b6;color:#15181e;box-shadow:0 0 14px rgba(46,196,182,.35)}
.rg-btn.ghost{background:#33383f;color:#eef1f6;box-shadow:none}
.rg-btn:disabled{opacity:.5;cursor:default}
.rg-err{color:#ff9c9c;font-weight:600;font-size:13.5px;min-height:18px}
.rg-av{display:inline-grid;place-items:center;border-radius:50%;font-weight:800;color:#15181e;overflow:hidden;flex:none;background:var(--x)}
.rg-av img{width:100%;height:100%;object-fit:cover}
.rg-toast{position:fixed;left:50%;bottom:calc(20px + env(safe-area-inset-bottom,0px));transform:translateX(-50%);background:#eef1f6;color:#15181e;font:700 14px "Inter",system-ui;padding:11px 18px;border-radius:99px;z-index:80;box-shadow:0 10px 30px rgba(0,0,0,.5);max-width:calc(100vw - 32px)}
.rg-nav{position:fixed;left:0;right:0;bottom:0;z-index:55;display:flex;justify-content:center;gap:2px;padding:6px max(6px,env(safe-area-inset-right,0px)) calc(6px + env(safe-area-inset-bottom,0px)) max(6px,env(safe-area-inset-left,0px));background:rgba(27,29,34,.94);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border-top:1px solid rgba(255,255,255,.07)}
.rg-nav a{position:relative;flex:1;min-width:0;max-width:110px;display:flex;flex-direction:column;align-items:center;gap:2px;padding:6px 2px 5px;border-radius:14px;color:#9aa3b3;font:600 11px "Inter",system-ui;text-decoration:none;white-space:nowrap}
.rg-nav a>span{height:24px;display:grid;place-items:center;font-size:21px;line-height:1}
.rg-nav a.on{color:#2ec4b6;background:rgba(46,196,182,.13)}
.rg-nav a.on .rg-av{box-shadow:0 0 0 2px #2ec4b6}
.rg-nav b{position:absolute;top:2px;left:calc(50% + 6px);min-width:18px;height:18px;padding:0 5px;border-radius:99px;background:#ff5a5f;color:#fff;font:800 11px/18px "Inter",system-ui;text-align:center;box-shadow:0 0 0 2px #1b1d22}
.rg-soon,.rg-lock>.rg-soon{display:inline-block;padding:4px 10px;border-radius:99px;background:#ffd23f;color:#15181e;font:800 11px/1.2 "Inter",system-ui;letter-spacing:.05em;text-transform:uppercase;white-space:nowrap}
.rg-lock{position:relative}
.rg-lock>:not(.rg-soon){opacity:.45;filter:grayscale(.5)}
.rg-lock>.rg-soon{position:absolute;top:12px;right:12px;z-index:1}
html.rg-playing .rg-nav{display:none}
.rg-bell{position:relative;flex:none;display:inline-grid;place-items:center;width:40px;height:40px;border:0;border-radius:50%;cursor:pointer;background:#2a2e35;color:#eef1f6;font-size:19px;line-height:1;box-shadow:4px 4px 10px rgba(0,0,0,.45),-2px -2px 6px rgba(255,255,255,.04)}
.rg-me{display:inline-flex;align-items:center;gap:8px;margin-left:auto;min-width:0}
.top.rg-wrap{flex-wrap:wrap;justify-content:flex-end}.top.rg-wrap>h1{flex-basis:100%}
.rg-bell:focus-visible{outline:2px solid #2ec4b6;outline-offset:2px}
.rg-bell.float{position:absolute;top:calc(12px + env(safe-area-inset-top,0px));right:max(12px,env(safe-area-inset-right,0px));z-index:40}
.rg-bell b{position:absolute;top:-3px;right:-4px;min-width:19px;height:19px;padding:0 5px;border-radius:99px;background:#ff5a5f;color:#fff;font:800 11px/19px "Inter",system-ui;text-align:center;box-shadow:0 0 0 2px #1b1d22}
.rg-bell.ring{animation:rg-ring .9s ease 1}
@keyframes rg-ring{0%,100%{transform:rotate(0)}20%{transform:rotate(-14deg)}40%{transform:rotate(12deg)}60%{transform:rotate(-8deg)}80%{transform:rotate(4deg)}}
@media (prefers-reduced-motion:reduce){.rg-bell.ring{animation:none}}
html.rg-playing .rg-bell,html.rg-playing .rg-np{display:none}
.rg-np{position:fixed;z-index:70;right:max(12px,env(safe-area-inset-right,0px));width:min(380px,calc(100vw - 24px));display:flex;flex-direction:column;background:#2a2e35;color:#eef1f6;border-radius:22px;box-shadow:0 24px 60px rgba(0,0,0,.6);font-family:"Inter",system-ui,sans-serif;overflow:hidden}
.rg-np header{display:flex;align-items:center;gap:10px;padding:14px 14px 10px 18px}
.rg-np header h3{margin:0;flex:1;font:600 19px "Fredoka","Inter",system-ui}
.rg-np header button{border:0;background:#202328;color:#9aa3b3;width:32px;height:32px;border-radius:50%;font-size:15px;cursor:pointer}
.rg-np .rg-nl{overflow:auto;padding:0 8px 10px;display:flex;flex-direction:column;gap:4px}
.rg-np a{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:10px;align-items:center;padding:9px 10px;border-radius:15px;color:inherit;text-decoration:none}
.rg-np a:hover,.rg-np a:focus-visible{background:#33383f}
.rg-np a.new{background:rgba(46,196,182,.1)}
.rg-np a p{margin:0;font-size:14px;line-height:1.35}
.rg-np a p small{display:block;color:#9aa3b3;font-size:12.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rg-np time{color:#9aa3b3;font-size:12px;font-weight:600;white-space:nowrap}
.rg-np a.new time{color:#2ec4b6}
.rg-np .rg-ne{margin:4px 10px 12px;color:#9aa3b3;font-size:14px;line-height:1.45}
.rg-pp{display:flex;flex-wrap:wrap;align-items:center;gap:8px 10px;margin:0 8px 8px;padding:10px 12px;border-radius:16px;background:#202328}
.rg-pp>span{font-size:22px}
.rg-pp p{flex:1;min-width:150px;margin:0;font-size:14px;line-height:1.35}
.rg-pp p small{display:block;color:#9aa3b3;font-size:12.5px}
.rg-pp div{display:flex;gap:6px;margin-left:auto}
.rg-pp button{border:0;border-radius:99px;padding:8px 14px;font:700 13px "Inter",system-ui;cursor:pointer;background:#33383f;color:#eef1f6}
.rg-pp button.go{background:#2ec4b6;color:#10241f}
.rg-pp button:disabled{opacity:.55}
html.rg-nav-on:not(.rg-playing) .rg-gate{padding-bottom:calc(80px + env(safe-area-inset-bottom,0px))}
html.rg-nav-on:not(.rg-playing) .rg-card{max-height:calc(100vh - 110px)}
html.rg-nav-on:not(.rg-playing) body{padding-bottom:calc(68px + env(safe-area-inset-bottom,0px))}
html.rg-nav-on:not(.rg-playing) .toast,html.rg-nav-on:not(.rg-playing) .rg-toast{bottom:calc(84px + env(safe-area-inset-bottom,0px))}
`;
const st=document.createElement("style");st.textContent=CSS;document.head.appendChild(st);

/* Bottom bar (like Instagram's) to switch between the Shenandoah Guide (the front page), the games home, the trip feed, the group chat,
   the to-do list and your profile. Also on the game pages (Games lit up), where RG.playing(true) hides it while a game is being played.
   The Chat button shows how many messages you haven't read yet; the Me button shows your picture once you are logged in.
   Next to your name (top right) is the bell: what's new for you from the chat, the Feed and game invites (notify.php).
   The top of the bell's panel turns phone notifications on for this phone (push.php, sw.js), so the same news arrives when the app is closed.
   It stays above the login screen, so someone who isn't logged in can still switch pages. */
(function(){
  const here={"":"trip","index.html":"trip","games.html":"games","social.html":"feed","chat.html":"chat","plan.html":"plan","profile.html":"me",
    "play.html":"games","online.html":"games","mind.html":"games","cards.html":"games","map.html":"games","scores.html":"games","admin.html":"me"}[location.pathname.split("/").pop()];
  if(!here||window.top!==window)return; // pages shown inside the Score screen have no bar
  const items=[["trip","./","\u{1F3D4}️","Trip"],["games","games.html","\u{1F3B2}","Games"],["feed","social.html","\u{1F4F8}","Feed"],
    ["chat","chat.html","\u{1F4AC}","Chat"],["plan","plan.html","\u{1F4CB}","To-do"],["me","profile.html","\u{1F464}","Me"]];
  let nav=null,shownMe=false,bell=null,people=null;
  const add=()=>{nav=document.createElement("nav");nav.className="rg-nav";nav.setAttribute("aria-label","Main");
    nav.innerHTML=items.map(([k,href,icon,label])=>`<a href="${href}" data-k="${k}"${k===here?' class="on" aria-current="page"':""}><span>${icon}</span>${label}</a>`).join("");
    document.body.appendChild(nav);document.documentElement.classList.add("rg-nav-on");check()};
  // the bell (notify.php via api.php): next to your name where the page shows it, else at the start of the page's top row, else in the top right corner
  function placeBell(){bell=document.createElement("button");bell.type="button";bell.className="rg-bell";bell.setAttribute("aria-label","Notifications");bell.innerHTML="\u{1F514}";
    const name=document.querySelector(".top #who,.top #user"),h1=document.querySelector(".top>h1"),head=document.querySelector(".head");
    if(name){const w=document.createElement("span");w.className="rg-me";name.replaceWith(w);w.append(bell,name)}
    else if(h1){const top=h1.parentNode;top.appendChild(bell);if(h1.offsetWidth<200)top.classList.add("rg-wrap")}
    else if(head)head.appendChild(bell);
    else{bell.classList.add("float");document.body.appendChild(bell);document.documentElement.classList.add("rg-bell-float")}
    bell.onclick=e=>{e.stopPropagation();document.querySelector(".rg-np")?closePanel():openPanel()}}
  function setCount(n){if(!bell)return;let b=bell.querySelector("b");const was=b?+b.dataset.n:0;
    if(!n){if(b)b.remove();bell.setAttribute("aria-label","Notifications");return}
    if(!b){b=document.createElement("b");bell.appendChild(b)}b.dataset.n=n;b.textContent=n>9?"9+":n;bell.setAttribute("aria-label",`Notifications, ${n} new`);
    if(n>was){bell.classList.remove("ring");void bell.offsetWidth;bell.classList.add("ring")}}
  async function check(){
    if(document.hidden)return;
    try{const r=await fetch("api.php?a=notify&count=1",{credentials:"same-origin"});
      if(!r.ok){if(bell){bell.remove();bell=null}return}const j=await r.json();
      if(j.me&&!shownMe){shownMe=true;nav.querySelector('[data-k="me"]>span').innerHTML=av(j.me,24)}
      if(j.me&&!+j.me.screen&&!bell)placeBell();
      if(j.me&&!+j.me.screen){pushMe=j.me.id;pushKeep()}
      setCount(j.n);
      const c=nav.querySelector('[data-k="chat"]');let b=c.querySelector("b");
      if(here==="chat"||!j.chat){if(b)b.remove();return}
      if(!b){b=document.createElement("b");c.appendChild(b)}b.textContent=j.chat>9?"9+":j.chat;
    }catch(e){}
  }
  const ago=t=>{const s=Date.now()/1000-t;if(s<60)return "now";if(s<3600)return Math.floor(s/60)+"m";if(s<86400)return Math.floor(s/3600)+"h";
    const d=new Date(t*1000),y=new Date();y.setDate(y.getDate()-1);return d.toDateString()===y.toDateString()?"Yesterday":d.toLocaleDateString(undefined,{weekday:"short"})};
  const names=ids=>{const n=ids.map(id=>esc((people[id]||{name:"Someone"}).name));return n.length<3?n.join(" and "):`${n[0]}, ${n[1]} and ${n.length-2} more`};
  const line=it=>{const who=`<b>${names(it.by)}</b>`,q=it.text?`<small>\u201C${esc(it.text)}\u201D</small>`:"";
    return {chat:`${who} sent ${it.count===1?"a new message":it.count+" new messages"} in the group chat`,post:`${who} shared ${esc(it.what)} on the Feed${q}`,
      tag:`${who} tagged you in a post${q}`,like:`${who} liked your post${q}`,comment:`${who} commented on your post${q}`,
      invite:`${who} invited you to play ${esc(it.text)}<small>Tap to join</small>`}[it.kind]||""};
  function closePanel(){const p=document.querySelector(".rg-np");if(p)p.remove();document.removeEventListener("click",outside,true);removeEventListener("keydown",onKey)}
  const outside=e=>{const p=document.querySelector(".rg-np");if(p&&!p.contains(e.target)&&!bell.contains(e.target))closePanel()};
  const onKey=e=>{if(e.key==="Escape")closePanel()};
  async function openPanel(){const p=document.createElement("div");p.className="rg-np";p.setAttribute("role","dialog");p.setAttribute("aria-label","Notifications");
    const r=bell.getBoundingClientRect(),t=Math.max(8,r.bottom+8);p.style.top=t+"px";p.style.maxHeight=`calc(100vh - ${t+90}px)`;
    p.innerHTML=`<header><h3>Notifications</h3><button type="button" aria-label="Close">\u2715</button></header><div class="rg-pp" hidden></div><div class="rg-nl"><p class="rg-ne">Loading\u2026</p></div>`;
    document.body.appendChild(p);p.querySelector("header button").onclick=closePanel;drawPush(p.querySelector(".rg-pp"));
    setTimeout(()=>document.addEventListener("click",outside,true));addEventListener("keydown",onKey);
    try{const j=await api("notify");people={};(j.people||[]).forEach(x=>people[x.id]=x);const l=p.querySelector(".rg-nl");
      l.innerHTML=j.items.length?j.items.map(it=>`<a href="${esc(it.href)}" class="${it.new?"new":""}">${av(people[it.by[0]]||{short:"?",color:"#9aa3b3"},40)}<p>${line(it)}</p><time>${ago(it.at)}</time></a>`).join("")
        :`<p class="rg-ne">Nothing new yet. Chat messages, new Feed posts, likes and comments on your posts, and game invites show up here.</p>`;
      if(j.n){await api("notify_seen",{});setCount(0)}
    }catch(e){p.querySelector(".rg-nl").innerHTML=`<p class="rg-ne">${esc(e.message)}</p>`}}
  window.addEventListener("rg-login",()=>{shownMe=false;check()});
  setInterval(check,30000);document.addEventListener("visibilitychange",check);
  document.body?add():document.addEventListener("DOMContentLoaded",add);
})();

/* Phone notifications (push.php sends them, sw.js shows them). Each phone turns them on from the bell's panel; they then arrive even
   when the app is closed. On an iPhone they only work in the app opened from its home-screen icon (iOS 16.4 or newer).
   Logging out turns them off on that phone. Once a day (or when someone else logs in) the phone tells the server again, in case it changed. */
const PUSH_OK="serviceWorker" in navigator&&"PushManager" in window&&"Notification" in window&&window.isSecureContext;
const IS_IOS=/iPhone|iPad|iPod/.test(navigator.userAgent)||(navigator.platform==="MacIntel"&&navigator.maxTouchPoints>1);
const IS_HOME_APP=matchMedia("(display-mode: standalone)").matches||navigator.standalone===true;
const b64u=buf=>btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g,"-").replace(/\//g,"_").replace(/=+$/,"");
const unb64u=s=>Uint8Array.from(atob(s.replace(/-/g,"+").replace(/_/g,"/")+"===".slice((s.length+3)%4)),c=>c.charCodeAt(0));
const pushMark=v=>{try{v?localStorage.setItem("rg-push",v):localStorage.removeItem("rg-push")}catch(e){}};
async function pushSub(){
  if(!PUSH_OK)return null;
  try{const reg=await navigator.serviceWorker.getRegistration();return reg?await reg.pushManager.getSubscription():null}catch(e){return null}}
async function pushSend(sub,me){ // (re)subscribe if the server's key changed, then tell the server which member this phone is for
  const {key}=await api("push_key"),reg=await navigator.serviceWorker.register("sw.js").then(()=>navigator.serviceWorker.ready);
  if(sub&&sub.options.applicationServerKey&&b64u(sub.options.applicationServerKey)!==key){await sub.unsubscribe();sub=null}
  if(!sub)sub=await reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:unb64u(key)});
  await api("push_sub",{sub:sub.toJSON()});pushMark(me+"|"+new Date().toDateString());}
let pushKept=false,pushMe=null; // pushMe: who is logged in (set by the bell's check)
async function pushKeep(){
  if(pushKept||!PUSH_OK||Notification.permission!=="granted")return;pushKept=true;
  let v="";try{v=localStorage.getItem("rg-push")||""}catch(e){}
  if(v===pushMe+"|"+new Date().toDateString())return;
  try{const sub=await pushSub();if(sub)await pushSend(sub,pushMe)}catch(e){}}
async function pushOff(){
  const sub=await pushSub();pushMark("");if(!sub)return;
  try{await api("push_unsub",{endpoint:sub.endpoint})}catch(e){}
  try{await sub.unsubscribe()}catch(e){}}
async function drawPush(box){
  const set=(html)=>{box.innerHTML=html;box.hidden=!html};
  if(!PUSH_OK){
    if(IS_IOS&&!IS_HOME_APP)set(`<span>\u{1F4F2}</span><p><b>Get these on your iPhone</b><small>Notifications only work in the app on your home screen. In Safari tap Share, then Add to Home Screen, then open the app from its icon.</small></p>`);
    else if(IS_IOS)set(`<span>\u{1F4F2}</span><p><b>Notifications need a newer iPhone update</b><small>Update the iPhone to iOS 16.4 or newer in Settings, General, Software Update.</small></p>`);
    return}
  let conf;try{conf=await api("push_key")}catch(e){return}
  if(!conf.any){set("");return} // the admin turned every kind off
  const sub=await pushSub();
  if(Notification.permission==="denied")set(`<span>\u{1F515}</span><p><b>Notifications are blocked on this phone</b><small>${IS_IOS?"Open the iPhone's Settings, then Notifications, then Shenandoah Cabin, and allow them.":"Allow notifications for this site in the browser's settings, then come back."}</small></p>`);
  else if(sub)set(`<span>\u{1F4F2}</span><p><b>Phone notifications are on</b><small>They arrive even when the app is closed.</small></p><div><button type="button" data-p="test">Send a test</button><button type="button" data-p="off">Turn off</button></div>`);
  else set(`<span>\u{1F4F2}</span><p><b>Get these on your phone</b><small>Even when the app is closed.</small></p><div><button type="button" class="go" data-p="on">Turn on</button></div>`);
  box.querySelectorAll("button").forEach(b=>b.onclick=async e=>{e.stopPropagation();
    const k=b.dataset.p;
    // asked first, straight from the tap: iPhones only show the question when it comes right from a tap
    const ask=k==="on"?Notification.requestPermission():null;
    box.querySelectorAll("button").forEach(x=>x.disabled=true);
    try{
      if(k==="on"){if(await ask!=="granted"){drawPush(box);return}await pushSend(await pushSub(),pushMe);toast("Notifications are on for this phone")}
      if(k==="off"){await pushOff();toast("Notifications are off for this phone")}
      if(k==="test"){const r=await api("push_test",{});toast(r.sent?"Sent. It should show up in a moment.":"This phone didn't answer. Turn notifications off and on again.")}
    }catch(x){toast(x.message||"That didn't work. Try again.")}
    drawPush(box)})}

const esc=s=>String(s==null?"":s).replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));

async function api(action,body,opts={}){
  if(action==="logout")await pushOff(); // this phone stops getting that member's notifications
  const init={method:body===undefined?"GET":"POST",headers:{"X-RG":"1"},credentials:"same-origin"};
  if(body!==undefined){
    if(body instanceof Blob){init.body=body;init.headers["Content-Type"]=body.type||"application/octet-stream"}
    else{init.body=JSON.stringify(body);init.headers["Content-Type"]="application/json"}
  }
  if(location.protocol==="file:")throw Object.assign(new Error("This page is open as a file, so there is no game server behind it."),{offline:1});
  let r;
  try{r=await fetch("api.php?a="+encodeURIComponent(action)+(opts.q||""),init)}catch(e){throw Object.assign(new Error("No connection to the server. Check the Wi-Fi and try again."),{offline:1})}
  let j=null;try{j=await r.json()}catch(e){}
  if(!j)throw Object.assign(new Error("The game server isn't answering here."),{status:r.status,offline:1});
  if(!r.ok)throw Object.assign(new Error(j.error||"Something went wrong. Try again."),{status:r.status});
  return j;
}

function av(p,size=36){
  if(!p)return "";
  const inner=p.avatar?`<img src="${esc(p.avatar)}" alt="">`:esc(p.short);
  return `<span class="rg-av" style="--x:${esc(p.color)};width:${size}px;height:${size}px;font-size:${Math.round(size*.34)}px">${inner}</span>`;
}

function toast(msg){const t=document.createElement("div");t.className="rg-toast";t.textContent=msg;document.body.appendChild(t);setTimeout(()=>t.remove(),2600)}

/* Login screen. Resolves with the logged-in player. admin:true = only the admin may pass. */
/* The Score screen account only watches: on its own (not inside the screen page) every page sends it to screen.html. */
const toScreen=me=>{if(me&&me.screen&&window.top===window&&!/screen\.html$/.test(location.pathname)){location.replace("screen.html");return true}};
async function login({admin=false}={}){
  const s=await api("state");setAccess(s);
  if(s.me&&!admin&&toScreen(s.me))return new Promise(()=>{});
  if(s.me&&(!admin||s.me.is_admin))return {me:s.me,state:s};
  return new Promise(resolve=>{
    const g=document.createElement("div");g.className="rg-gate";
    const list=admin?s.players.filter(p=>p.is_admin):s.players.concat(s.screens||[]);
    let pick=list.length===1?list[0]:null;
    const draw=()=>{
      const needSetup=pick&&pick.is_admin&&!s.admin_ready;
      g.innerHTML=`<form class="rg-card" autocomplete="on">
        <h2>${admin?"Admin":"Who's playing?"}</h2>
        <p>${admin?"Only the admin can record games and change settings.":"Pick your profile and type your password."}</p>
        <div class="rg-who">${list.map(p=>`<button type="button" data-id="${esc(p.id)}" class="${pick&&pick.id===p.id?"on":""}${p.screen?" scr":""}" style="--x:${esc(p.color)}">${p.screen?"<span>\u{1F4FA}</span>":av(p,44)}<span>${esc(p.name)}</span>${p.screen?'<small style="color:#9aa3b3;font-weight:500">(watch only)</small>':""}</button>`).join("")}</div>
        ${pick?`<input type="text" name="username" value="${esc(pick.id)}" autocomplete="username" hidden>
        <input class="rg-in" type="password" name="password" placeholder="${needSetup?"Choose the admin password (6+ letters)":"Password"}" autocomplete="${needSetup?"new-password":"current-password"}" required>
        ${needSetup?`<input class="rg-in" type="password" name="password2" placeholder="Type it again" autocomplete="new-password" required>`:""}
        <div class="rg-err"></div>
        <button class="rg-btn" type="submit">${needSetup?"Set password and enter":"Enter"}</button>`:""}
        ${/\/(index\.html|games\.html)?$/.test(location.pathname)?"":`<a href="games.html" style="color:#9aa3b3;font-size:13px;text-align:center;padding:8px">Back home</a>`}
      </form>`;
      const f=g.querySelector("form");
      g.querySelectorAll(".rg-who button").forEach(b=>b.onclick=()=>{pick=list.find(p=>p.id===b.dataset.id);draw();const i=g.querySelector("input[name=password]");if(i)i.focus()});
      f.onsubmit=async e=>{
        e.preventDefault();if(!pick)return;
        const err=g.querySelector(".rg-err"),btn=g.querySelector(".rg-btn");err.textContent="";btn.disabled=true;
        try{
          const pw=f.password.value;
          let r;
          if(needSetup){if(pw!==f.password2.value)throw new Error("The two passwords don't match.");r=await api("setup",{password:pw})}
          else r=await api("login",{id:pick.id,password:pw});
          if(admin&&!r.me.is_admin)throw new Error("That profile is not the admin.");
          if(!admin&&toScreen(r.me))return;
          const s2=await api("state");setAccess(s2);
          g.remove();window.dispatchEvent(new Event("rg-login"));resolve({me:r.me,state:s2});
        }catch(x){err.textContent=x.message;btn.disabled=false}
      };
    };
    draw();document.body.appendChild(g);
  });
}

/* Shrink a photo until it is under maxBytes (default 1 MB). Returns a JPEG Blob. */
async function shrinkPhoto(file,maxBytes=1000000){
  const url=URL.createObjectURL(file);
  try{
    const img=await new Promise((res,rej)=>{const i=new Image();i.onload=()=>res(i);i.onerror=()=>rej(new Error("That file is not a photo."));i.src=url});
    let side=2000,q=.85,blob=null;
    for(let n=0;n<12;n++){
      const k=Math.min(1,side/Math.max(img.naturalWidth,img.naturalHeight));
      const c=document.createElement("canvas");c.width=Math.round(img.naturalWidth*k);c.height=Math.round(img.naturalHeight*k);
      c.getContext("2d").drawImage(img,0,0,c.width,c.height);
      blob=await new Promise(r=>c.toBlob(r,"image/jpeg",q));
      if(blob&&blob.size<=maxBytes)return blob;
      if(q>.6)q-=.1;else side=Math.round(side*.8);
    }
    throw new Error("Could not make the photo small enough.");
  }finally{URL.revokeObjectURL(url)}
}

/* Small square avatar from a picture (for profiles). */
async function shrinkAvatar(file,size=160){
  const url=URL.createObjectURL(file);
  try{
    const img=await new Promise((res,rej)=>{const i=new Image();i.onload=()=>res(i);i.onerror=()=>rej(new Error("That file is not a picture."));i.src=url});
    const c=document.createElement("canvas");c.width=c.height=size;const s=Math.min(img.naturalWidth,img.naturalHeight);
    c.getContext("2d").drawImage(img,(img.naturalWidth-s)/2,(img.naturalHeight-s)/2,s,s,0,0,size,size);
    return c.toDataURL("image/jpeg",.82);
  }finally{URL.revokeObjectURL(url)}
}

/* Periods for averages. played_at is local "YYYY-MM-DDTHH:MM" (no time zone): read it as the device's own clock time,
   by hand, because some Safari versions read that string as UTC. */
const PERIODS=[["day","Today"],["week","This week"],["month","This month"],["year","This year"],["all","All time"]];
function parseLocal(iso){const m=/^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2}))?/.exec(iso||"");return m?new Date(+m[1],m[2]-1,+m[3],+(m[4]||0),+(m[5]||0)):new Date(NaN)}
function inPeriod(iso,period,now=new Date()){
  if(period==="all")return true;
  const d=parseLocal(iso);
  if(period==="day")return d.toDateString()===now.toDateString();
  if(period==="month")return d.getFullYear()===now.getFullYear()&&d.getMonth()===now.getMonth();
  if(period==="year")return d.getFullYear()===now.getFullYear();
  if(period==="week"){const m=new Date(now);m.setHours(0,0,0,0);m.setDate(m.getDate()-((m.getDay()+6)%7));const e=new Date(m);e.setDate(m.getDate()+7);return d>=m&&d<e}
  return true;
}
function nowLocal(){const d=new Date();d.setMinutes(d.getMinutes()-d.getTimezoneOffset());return d.toISOString().slice(0,16)}
function fmtDate(iso){const d=parseLocal(iso);if(isNaN(d))return "No date";return d.toLocaleDateString(undefined,{weekday:"short",month:"short",day:"numeric"})+" · "+d.toLocaleTimeString(undefined,{hour:"numeric",minute:"2-digit"})}
const ordinal=n=>n+(["th","st","nd","rd"][(n%100>10&&n%100<14)?0:(n%10<4?n%10:0)]||"th");

/* Game names for the record form (from the catalog, the map, mind games and cards). */
const GAMES={
  "Party":["Charades","Pictionary","Heads Up","Two Truths and a Lie","Who Am I?","Word Chain","Telephone Pictionary","Mafia / Werewolf","Spyfall","Codenames"],
  "Arcade":["Air Hockey","Pong Duel","Reaction Tap","Snake Duel","Tap Race"],
  "Mind games":[],
  "Cards":["Poker","High Card Showdown","Higher or Lower","Blackjack vs Dealer"],
  "Grand game":["The Road to the Crown"],
  "Outdoor":["Scavenger Hunt","Cornhole","Frisbee Golf","Tug of War","Water Balloon Toss"]
};
if(window.MindGames)GAMES["Mind games"]=MindGames.list.map(g=>g.name);

/* Who may play what (Admin > Settings > Access). A player whose games are off plays only the games ticked as open;
   the rest show "Coming soon". A mind race counts as the mind game it races on. Admins always have everything.
   Before login (or with no server) nothing is locked. */
const ACCESS_GAMES=[
  ["Play together",[["play:poker","Poker on phones"],["play:imposter","Imposter"],["play:trivia","Trivia race"],["play:reaction","Last One Standing"],["play:likely","Most Likely To"],["play:werewolf","Werewolf"],["play:spyfall","Spyfall"],["play:codenames","Codenames"]]],
  ["Mind Games (alone or in a Mind race)",window.MindGames?MindGames.list.map(g=>["mind:"+g.id,g.name]):[]],
  ["Card Games on one iPad",[["cards:poker","Texas Hold'em"],["cards:highcard","High Card Showdown"],["cards:hilo","Higher or Lower"],["cards:blackjack","Blackjack vs Dealer"]]],
  ["The grand game",[["crown","The Road to the Crown"]]]];
/* The Road to the Crown: the games that can be shuffled onto the map (names as in map.html POOL). Admin > Settings picks which ones. */
const CROWN_GAMES=[
  ["Game stops",["Charades","Heads Up","Taboo","Pictionary","Memory Match","Trivia Sprint"]],
  ["Challenges (team vs team)",["Air Hockey","Tap Race","Quick Draw","Trivia Battle","Imposter"]],
  ["Showdowns (everyone plays)",["Last One Standing","Mind Race"]],
  ["Mind game stops (near the castle)",["Quick Math","Color Clash","Number Memory","Word Scramble","Bigger or Smaller","Grid Recall","Liquid Lab","Echo Tiles","Odd One Out","Flash Count","Number Hunt","Turn Twin","Pair Up","Slide Puzzle","Arrow Storm","True or False","Number Pattern","Word Sort"]]];
let acc=null,accMe=null;const accPeople={};
function setAccess(s){acc=s.access||null;accMe=s.me||null;(s.players||[]).forEach(p=>accPeople[p.id]=p)}
function locked(pid,list){const p=pid?accPeople[pid]:accMe;return !!(acc&&p&&!+p.is_admin&&(acc[list]||[]).includes(p.id))}
const canPlay=(gid,pid)=>!locked(pid,"nogames")||acc.open.includes(gid);
const canFeed=pid=>!locked(pid,"nofeed");
const canChat=pid=>!locked(pid,"nochat");
const canAny=prefix=>!locked(null,"nogames")||acc.open.some(g=>g.startsWith(prefix)); // e.g. "mind:": at least one mind game
const SOON="Coming soon";
const playing=on=>document.documentElement.classList.toggle("rg-playing",!!on); // hides the bottom bar during a game

window.RG={api,av,esc,toast,login,playing,canPlay,canFeed,canChat,canAny,ACCESS_GAMES,CROWN_GAMES,SOON,shrinkPhoto,shrinkAvatar,PERIODS,inPeriod,parseLocal,nowLocal,fmtDate,ordinal,GAMES};
})();
