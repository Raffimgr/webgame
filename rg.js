/* Ridge Games shared helpers: talking to api.php, the login screen, photo shrinking, avatars, periods.
   Used by scores.html and admin.html. */
(function(){
const CSS=`
.rg-gate{position:fixed;inset:0;z-index:50;display:grid;place-items:center;padding:16px;background:rgba(15,17,21,.86);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px)}
.rg-card{width:min(460px,100%);max-height:92vh;overflow:auto;background:#2a2e35;border-radius:26px;padding:22px;box-shadow:0 24px 60px rgba(0,0,0,.6);display:flex;flex-direction:column;gap:14px;color:#eef1f6;font-family:"Inter",system-ui,sans-serif}
.rg-card h2{margin:0;font:600 24px "Fredoka","Inter",system-ui}
.rg-card p{margin:0;color:#9aa3b3;font-size:14px}
.rg-who{display:grid;grid-template-columns:repeat(auto-fill,minmax(74px,1fr));gap:10px}
.rg-who button{display:flex;flex-direction:column;align-items:center;gap:6px;padding:10px 4px;border-radius:16px;border:0;background:#202328;color:#eef1f6;font:600 12.5px "Inter",system-ui;cursor:pointer;box-shadow:inset 3px 3px 8px rgba(0,0,0,.5)}
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
`;
const st=document.createElement("style");st.textContent=CSS;document.head.appendChild(st);

const esc=s=>String(s==null?"":s).replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));

async function api(action,body,opts={}){
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
async function login({admin=false}={}){
  const s=await api("state");
  if(s.me&&(!admin||s.me.is_admin))return {me:s.me,state:s};
  return new Promise(resolve=>{
    const g=document.createElement("div");g.className="rg-gate";
    const list=admin?s.players.filter(p=>p.is_admin):s.players;
    let pick=list.length===1?list[0]:null;
    const draw=()=>{
      const needSetup=pick&&pick.is_admin&&!s.admin_ready;
      g.innerHTML=`<form class="rg-card" autocomplete="on">
        <h2>${admin?"Admin":"Who's playing?"}</h2>
        <p>${admin?"Only the admin can record games and change settings.":"Pick your profile and type your password."}</p>
        <div class="rg-who">${list.map(p=>`<button type="button" data-id="${esc(p.id)}" class="${pick&&pick.id===p.id?"on":""}" style="--x:${esc(p.color)}">${av(p,44)}${esc(p.name)}</button>`).join("")}</div>
        ${pick?`<input type="text" name="username" value="${esc(pick.id)}" autocomplete="username" hidden>
        <input class="rg-in" type="password" name="password" placeholder="${needSetup?"Choose the admin password (6+ letters)":"Password"}" autocomplete="${needSetup?"new-password":"current-password"}" required>
        ${needSetup?`<input class="rg-in" type="password" name="password2" placeholder="Type it again" autocomplete="new-password" required>`:""}
        <div class="rg-err"></div>
        <button class="rg-btn" type="submit">${needSetup?"Set password and enter":"Enter"}</button>`:""}
        <a href="./" style="color:#9aa3b3;font-size:13px;text-align:center;padding:8px">Back home</a>
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
          g.remove();resolve({me:r.me,state:await api("state")});
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

window.RG={api,av,esc,toast,login,shrinkPhoto,shrinkAvatar,PERIODS,inPeriod,parseLocal,nowLocal,fmtDate,ordinal,GAMES};
})();
