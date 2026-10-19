/* Ridge Games: the shared lobby for every play-together game (poker, mind races, Imposter).
   RGLobby.waiting(room, me, people, busy) draws "who's playing" with Ready checks and, for the host or the admin, a Close lobby button;
   RGLobby.wire(send) hooks up its buttons;
   RGLobby.clock(room) keeps the phone in step with the server; RGLobby.countdown(room, onGo) shows 3, 2, 1, Go at the same moment on every phone. */
(function(){
const CSS=`
.lb-row{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:16px;background:#202328;box-shadow:inset 4px 4px 10px rgba(0,0,0,.55),inset -3px -3px 8px rgba(255,255,255,.035);font-weight:650}
.lb-row .n{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lb-row.away{opacity:.55}
.lb-st{flex:none;display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:700;color:#9aa3b3}
.lb-ok{width:30px;height:30px;border-radius:50%;display:grid;place-items:center;background:#7be08a;color:#15181e;box-shadow:0 0 14px rgba(123,224,138,.55)}
.lb-ok svg{width:18px;height:18px}
.lb-wait{width:30px;height:30px;border-radius:50%;border:2px dashed #4a505b}
.lb-tag{font:800 10px "Inter",system-ui;letter-spacing:.05em;padding:3px 7px;border-radius:99px;background:#33383f;color:#9aa3b3;flex:none}
.lb-tag.you{background:#7ee8f0;color:#15181e}
.lb-ready{width:100%;border-radius:20px;padding:18px;font:700 20px "Inter",system-ui;color:#15181e;background:#7be08a;box-shadow:0 0 22px rgba(123,224,138,.45);text-align:center}
.lb-close{width:100%;border:2px solid rgba(255,123,107,.45);border-radius:18px;padding:13px;background:#3a2a2d;color:#ffb3b3;font:700 16px "Inter",system-ui;cursor:pointer}
.lb-close:disabled{opacity:.5}
.lb-ready.undo{background:#33383f;color:#eef1f6;box-shadow:none;font-size:16px;padding:14px}
.lb-cd{position:fixed;inset:0;z-index:90;display:grid;place-items:center;background:rgba(15,17,21,.92);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);text-align:center;color:#eef1f6;font-family:"Inter",system-ui}
.lb-cd b{display:block;font:700 min(42vw,190px)/1 "Fredoka","Inter",system-ui;color:#ffd23f;text-shadow:0 0 40px rgba(255,210,63,.45)}
.lb-cd small{display:block;font:600 16px "Inter",system-ui;color:#9aa3b3;margin-top:10px}
@media (prefers-reduced-motion:no-preference){.lb-cd b{animation:lbpop .9s ease-out infinite}@keyframes lbpop{0%{transform:scale(1.25);opacity:.4}25%{transform:scale(1);opacity:1}}}
`;
const st=document.createElement("style");st.textContent=CSS;document.head.appendChild(st);
const esc=s=>String(s==null?"":s).replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
const CHECK='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';

let off=0,best=[]; // server time minus this phone's time, in seconds
/* t0/t1 (ms) = when the request left and the answer came back: the reply with the shortest round trip gives the best reading. */
function clock(room,t0,t1){
  if(!room||!room.now)return;
  if(!t0){if(!best.length)off=room.now-Date.now()/1000;return}
  best.push({rtt:t1-t0,off:room.now-(t0+t1)/2000});if(best.length>12)best.shift();
  off=best.reduce((a,b)=>b.rtt<a.rtt?b:a).off;
}
const serverNow=()=>Date.now()/1000+off;

function members(room){return room.game==="poker"?room.seats.map(s=>s.id):room.players.map(p=>p.id)}
function amIn(room){return members(room).includes(room.me.id)}
function names(ids,people){const n=ids.map(i=>people[i]?people[i].name:i);return n.length<2?n.join(""):n.slice(0,-1).join(", ")+" and "+n[n.length-1]}

/* The waiting room. */
function waiting(room,me,people,busy){
  const ids=members(room),ready=new Set(room.ready||[]),inv=room.invited||[];
  const order=inv.concat(ids.filter(i=>!inv.includes(i)));
  const away=inv.filter(i=>!ids.includes(i)),notReady=ids.filter(i=>!ready.has(i));
  const av=id=>{const p=people[id]||{name:id,short:String(id).slice(0,2),color:"#9aa3b3"};return `<span class="av" style="--x:${esc(p.color)};--s:36px">${p.avatar?`<img src="${esc(p.avatar)}" alt="">`:esc(p.short)}</span>`};
  const rows=order.map(id=>{const isIn=ids.includes(id),ok=ready.has(id);
    return `<div class="lb-row${isIn?"":" away"}">${av(id)}<span class="n">${esc(people[id]?people[id].name:id)}</span>${id===room.host?'<span class="lb-tag">HOST</span>':""}${id===me.id?'<span class="lb-tag you">YOU</span>':""}
      <span class="lb-st">${ok?`Ready <span class="lb-ok" aria-label="Ready">${CHECK}</span>`:isIn?`Joined <span class="lb-wait"></span>`:`Not here yet <span class="lb-wait"></span>`}</span></div>`}).join("");
  const mine=amIn(room),iReady=ready.has(me.id),invited=inv.includes(me.id);
  let hint;
  if(away.length)hint=`Waiting for ${esc(names(away,people))} to open the game.`;
  else if(notReady.length)hint=`Waiting for ${esc(names(notReady,people))} to tap Ready.`;
  else hint="Everyone is here.";
  hint+=" When everyone taps Ready, the countdown starts on every phone at once.";
  const canForce=room.me.host&&ready.size>=2&&(away.length||notReady.length);
  return `<div class="panel"><div class="row" style="justify-content:space-between"><span class="lab">Who's playing</span><span class="chip">${ready.size} of ${order.length} ready</span></div>
    <div style="display:flex;flex-direction:column;gap:8px">${rows}</div><p class="muted" style="margin:0;font-size:14px">${hint}</p></div>
    ${mine?`<button class="lb-ready${iReady?" undo":""}" id="lbReady" ${busy?"disabled":""}>${iReady?"Not ready yet":"I'm ready"}</button>`
      :`<button class="lb-ready" id="lbJoin" ${busy?"disabled":""}>${invited?"You're invited: join the game":"Join the game"}</button><p class="muted" style="margin:0;text-align:center;font-size:13.5px">Or just watch from here.</p>`}
    <div class="row" style="justify-content:center">${mine&&!room.me.host?`<button class="go ghost sm" id="lbLeave" ${busy?"disabled":""}>Leave</button>`:""}
      ${canForce?`<button class="go sm" id="lbForce" style="--x:#ffd23f" ${busy?"disabled":""}>Start now with the ${ready.size} ready</button>`:""}
</div>
    ${room.me.host||+me.is_admin===1?`<button class="lb-close" id="lbCancel" data-host="${esc(room.me.host?"":people[room.host]?people[room.host].name:"the host")}" ${busy?"disabled":""}>\u2715 Close lobby</button>`:""}`;
}
function wire(send){
  const on=(id,f)=>{const b=document.getElementById(id);if(b)b.onclick=f};
  on("lbReady",()=>send("room_ready",{ready:!document.getElementById("lbReady").classList.contains("undo")}));
  on("lbJoin",()=>send("room_join"));on("lbLeave",()=>send("room_leave"));on("lbForce",()=>send("room_start"));
  on("lbCancel",()=>{const h=document.getElementById("lbCancel").dataset.host;if(confirm(h?`Close ${h}'s lobby for everyone?`:"Close this lobby for everyone?"))send("room_close")});
}

/* 3, 2, 1, Go: shown until the server's start moment, then onGo() runs (once per room). */
let cdFor=null,cdTimer=null;
function countdown(room,onGo){
  const left=()=>room.start_at?room.start_at-serverNow():-1;
  if(left()<=0)return false; // over (or never had one): the game shows
  if(cdFor===room.id&&document.querySelector(".lb-cd"))return true; // already counting
  cdFor=room.id;
  let el=document.querySelector(".lb-cd");if(!el){el=document.createElement("div");el.className="lb-cd";document.body.appendChild(el)}
  const t=()=>{const s=left();
    if(s<=0){el.innerHTML="<div><b>Go!</b></div>";clearInterval(cdTimer);try{navigator.vibrate&&navigator.vibrate(120)}catch(e){}setTimeout(()=>el.remove(),450);onGo&&onGo();return}
    el.innerHTML=`<div><b>${Math.ceil(s)}</b><small>${esc(room.label||"")} starts on every phone together</small></div>`};
  clearInterval(cdTimer);t();cdTimer=setInterval(t,100);return true;
}

window.RGLobby={clock,serverNow,members,amIn,waiting,wire,countdown,names};
})();
