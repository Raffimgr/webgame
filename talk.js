/* Likes and comments (talk.php), shared by the guide (index.html) and the trip feed (social.html).
   After login: Talk.setMe(me, playersById) and await Talk.load(). Talk.bar(key) draws an item's Like and Comment buttons;
   clicks, posting and the 30-second refresh are handled here. Styles for .talk live in each page. */
(function(){
const esc=s=>String(s==null?"":s).replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
const T={data:null,open:new Set(),me:null,people:{},onChange:null};
T.setMe=(me,people)=>{T.me=me;T.people=people||{}};
T.name=id=>(T.people[id]||{}).name||"Someone";
T.av=(id,size)=>T.people[id]&&window.RG?RG.av(T.people[id],size):"";
T.ago=t=>{const s=Date.now()/1000-t;if(s<60)return "just now";if(s<3600)return Math.floor(s/60)+"m ago";if(s<86400)return Math.floor(s/3600)+"h ago";
  return new Date(t*1000).toLocaleDateString([],{month:"short",day:"numeric"})};
T.load=async()=>{try{const r=await fetch("talk.php",{credentials:"same-origin"});if(r.ok)T.data=(await r.json()).items||{}}catch(e){}};
T.get=key=>(T.data&&T.data[key])||{likes:[],comments:[]};
T.bar=key=>{
  if(!T.data||!T.me)return "";
  const me=T.me,t=T.get(key),mine=t.likes.includes(me.id),n=t.comments.length,open=T.open.has(key),names=t.likes.map(T.name);
  return `<div class="talk" data-talk="${esc(key)}"><div class="tb"><button class="tk ${mine?"on":""}" data-like aria-pressed="${mine}">${mine?"❤️":"\u{1F90D}"} ${t.likes.length||"Like"}</button>
    <button class="tk" data-cm aria-expanded="${open}">\u{1F4AC} ${n?n+(n===1?" comment":" comments"):"Comment"}</button></div>
    ${names.length?`<div class="tw">Liked by ${esc(names.length>4?names.slice(0,4).join(", ")+" and "+(names.length-4)+" more":names.join(", "))}</div>`:""}
    ${open?`<div class="tc">${t.comments.map(c=>`<div class="cmt">${T.av(c.by,28)}<div>${me.id===c.by||me.is_admin?`<button class="tdel" data-tdel="${c.id}" aria-label="Delete comment">✕</button>`:""}<b>${esc(T.name(c.by))}</b> <span class="tm">${T.ago(c.at)}</span><div class="tx">${esc(c.body)}</div></div></div>`).join("")}
      <form class="tf" data-say><input name="b" maxlength="500" placeholder="Write a comment…" autocomplete="off" aria-label="Write a comment"><button class="btn">Post</button></form></div>`:""}</div>`};
T.count=key=>{const t=T.data&&T.data[key];if(!t)return "";const a=t.likes.length,b=t.comments.length;return a||b?` <span class="tcnt">${a?"❤️ "+a:""}${a&&b?" ":""}${b?"\u{1F4AC} "+b:""}</span>`:""};
/* redraw one item's bars (or all of them); keepDraft leaves alone a bar someone is typing in */
T.redraw=(key,keepDraft)=>{document.querySelectorAll(".talk").forEach(el=>{if(key&&el.dataset.talk!==key)return;const inp=el.querySelector("input");
  if(keepDraft&&inp&&(inp.value||document.activeElement===inp))return;el.insertAdjacentHTML("afterend",T.bar(el.dataset.talk));el.remove()});
  if(T.onChange)T.onChange(key)};
T.post=async body=>{const r=await fetch("talk.php",{method:"POST",credentials:"same-origin",headers:{"X-RG":"1","Content-Type":"application/json"},body:JSON.stringify(body)});
  const j=await r.json().catch(()=>({}));if(!r.ok)throw new Error(j.error||"Could not save. Try again.");T.data[j.item]=j.data;return j.item};
const toast=m=>window.RG?RG.toast(m):alert(m);
document.addEventListener("click",async e=>{
  const el=e.target.closest(".talk");if(!el)return;const key=el.dataset.talk;
  if(e.target.closest("[data-like]")){try{await T.post({act:"like",item:key});T.redraw(key)}catch(err){toast(err.message)}}
  else if(e.target.closest("[data-cm]")){T.open.has(key)?T.open.delete(key):T.open.add(key);T.redraw(key)}
  else{const d=e.target.closest("[data-tdel]");if(d){if(!confirm("Delete this comment?"))return;try{await T.post({act:"del",id:+d.dataset.tdel});T.redraw(key)}catch(err){toast(err.message)}}}});
document.addEventListener("submit",async e=>{const f=e.target.closest("[data-say]");if(!f)return;e.preventDefault();
  const key=f.closest(".talk").dataset.talk,inp=f.querySelector("input"),btn=f.querySelector("button"),body=inp.value.trim();if(!body)return;
  btn.disabled=true;try{await T.post({act:"say",item:key,body});inp.value="";T.redraw(key)}catch(err){toast(err.message);btn.disabled=false}});
/* pick up everyone else's likes and comments while the page is open */
setInterval(()=>{if(T.data&&!document.hidden)T.load().then(()=>T.redraw(null,true))},30000);
window.Talk=T;
})();
