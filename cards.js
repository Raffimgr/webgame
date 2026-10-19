/* Ridge Games: card engine. Decks, the poker hand evaluator, and a one-hand Texas Hold'em
   table (blinds, betting rounds, side pots, showdown). No page code here, so node can test it.
   In the browser it is window.Cards; in node, require("./cards.js"). */
(function(root){
"use strict";
const SUITS=["s","h","d","c"];
const RANK_CH={10:"10",11:"J",12:"Q",13:"K",14:"A"};
function rankCh(r){return RANK_CH[r]||String(r)}
function one(r){return {11:"Jack",12:"Queen",13:"King",14:"Ace"}[r]||String(r)}
function many(r){return {11:"Jacks",12:"Queens",13:"Kings",14:"Aces"}[r]||r+"s"}

/* "As", "Td", "10h", "7c" -> {r,s} */
function parse(t){t=String(t).trim();const s=t.slice(-1).toLowerCase(),x=t.slice(0,-1).toUpperCase();
  const r={A:14,K:13,Q:12,J:11,T:10}[x]||parseInt(x,10);if(!(r>=2&&r<=14)||!SUITS.includes(s))throw new Error("bad card "+t);return {r,s}}
function cards(str){return str.trim().split(/\s+/).map(parse)}
function rand(n){if(root.crypto&&root.crypto.getRandomValues){const a=new Uint32Array(1);root.crypto.getRandomValues(a);return a[0]%n}return Math.floor(Math.random()*n)}
function shuffle(a){for(let i=a.length-1;i>0;i--){const j=rand(i+1);[a[i],a[j]]=[a[j],a[i]]}return a}
function newDeck(decks){const d=[];for(let k=0;k<(decks||1);k++)for(const s of SUITS)for(let r=2;r<=14;r++)d.push({r,s});return d}

/* ---------- Hand evaluator ----------
   score5 gives an array [category, tie-breakers...]; bigger array (compared left to right) = better hand.
   Categories: 8 straight flush, 7 four of a kind, 6 full house, 5 flush, 4 straight, 3 three of a kind, 2 two pair, 1 pair, 0 high card. */
function score5(cs){
  const rs=cs.map(c=>c.r).sort((a,b)=>b-a);
  const flush=cs.every(c=>c.s===cs[0].s);
  const cnt={};for(const r of rs)cnt[r]=(cnt[r]||0)+1;
  const g=Object.keys(cnt).map(Number).sort((a,b)=>cnt[b]-cnt[a]||b-a);
  let st=0;
  if(g.length===5){if(rs[0]-rs[4]===4)st=rs[0];else if(rs[0]===14&&rs[1]===5)st=5}
  if(st&&flush)return [8,st];
  if(cnt[g[0]]===4)return [7,g[0],g[1]];
  if(cnt[g[0]]===3&&cnt[g[1]]===2)return [6,g[0],g[1]];
  if(flush)return [5,...rs];
  if(st)return [4,st];
  if(cnt[g[0]]===3)return [3,...g];
  if(cnt[g[0]]===2&&cnt[g[1]]===2)return [2,...g];
  if(cnt[g[0]]===2)return [1,...g];
  return [0,...rs];
}
function cmp(a,b){for(let i=0;i<Math.max(a.length,b.length);i++){const d=(a[i]||0)-(b[i]||0);if(d)return d}return 0}
/* best 5 of 5, 6 or 7 cards -> {score, best (5 cards), name} */
function best(cs){
  if(cs.length<5)return {score:[-1],best:cs.slice(),name:pre(cs)};
  let top=null,topC=null;const n=cs.length;
  for(let a=0;a<n;a++)for(let b=a+1;b<n;b++)for(let c=b+1;c<n;c++)for(let d=c+1;d<n;d++)for(let e=d+1;e<n;e++){
    const h=[cs[a],cs[b],cs[c],cs[d],cs[e]],s=score5(h);if(!top||cmp(s,top)>0){top=s;topC=h}}
  return {score:top,best:topC,name:handName(top)};
}
function pre(cs){if(cs.length===2){const [x,y]=cs;if(x.r===y.r)return "Pocket "+many(x.r);const hi=Math.max(x.r,y.r),lo=Math.min(x.r,y.r);return rankCh(hi)+"-"+rankCh(lo)+(x.s===y.s?" suited":"")}return ""}
function handName(s){
  switch(s[0]){
    case 8:return s[1]===14?"Royal flush":"Straight flush, "+one(s[1])+" high";
    case 7:return "Four of a kind, "+many(s[1]);
    case 6:return "Full house, "+many(s[1])+" over "+many(s[2]);
    case 5:return "Flush, "+one(s[1])+" high";
    case 4:return "Straight, "+one(s[1])+" high";
    case 3:return "Three of a kind, "+many(s[1]);
    case 2:return "Two pair, "+many(s[1])+" and "+many(s[2]);
    case 1:return "Pair of "+many(s[1]);
    case 0:return "High card, "+one(s[1]);
  }
  return "";
}
/* Names for hands shown side by side: when two hands share the same main ranks, add the kickers that decide it
   ("Two pair, Kings and 3s, Queen kicker"). */
const MAIN={8:1,7:1,6:2,5:1,4:1,3:1,2:2,1:1,0:1};
function kickerNames(scores){
  return scores.map(s=>{
    const m=MAIN[s[0]]+1;let name=handName(s);
    if(s.length<=m)return name;
    const sim=scores.filter(t=>t[0]===s[0]&&t.slice(1,m).join()===s.slice(1,m).join());
    if(sim.length<2)return name;
    let j=m;while(j<s.length&&sim.every(t=>t[j]===s[j]))j++;
    if(j>=s.length)return name;
    const ks=s.slice(m,j+1);
    return name+(ks.length===1?", "+one(ks[0])+" kicker":", kickers "+ks.map(rankCh).join("-"));
  });
}
const STREETS=["Pre-flop","Flop","Turn","River","Showdown"];

/* ---------- Side pots ----------
   Each pot level is set by the smallest stake among players still in. Folded chips stay in the pots
   but folded players can't win. Returns [{amount, eligible:[seat indexes]}], main pot first. */
function buildPots(ps){
  const c=ps.map(p=>p.total),pots=[];let from=0;
  for(;;){
    const live=[];for(let i=0;i<ps.length;i++)if(!ps[i].folded&&c[i]>0)live.push(i);
    if(!live.length)break;
    const m=Math.min(...live.map(i=>c[i]));let amt=0;from=0;
    for(let i=0;i<c.length;i++){const t=Math.min(c[i],m);amt+=t;c[i]-=t;if(t)from++}
    pots.push({amount:amt,eligible:live,from});
  }
  const rest=c.reduce((a,b)=>a+b,0);if(rest&&pots.length){pots[pots.length-1].amount+=rest;pots[pots.length-1].from++}
  return pots;
}

/* ---------- One hand of Texas Hold'em ----------
   pokerNew({players, stack, sb, bb, dealer, deck, stacks}) -> table g. The deck (optional, for tests) is dealt from the front;
   stacks (optional) gives each seat its own starting chips instead of the same stack for everyone.
   pokerOptions(g) -> what the player to act may do. pokerAct(g, "fold"|"check"|"call"|"raise"|"allin", raiseTo). */
function pokerNew(o){
  const n=o.players.length;if(n<2)throw new Error("need 2+ players");
  const g={sb:o.sb,bb:o.bb,stack:o.stack,dealer:((o.dealer||0)%n+n)%n,deck:o.deck?o.deck.slice():shuffle(newDeck()),
    board:[],street:0,currentBet:0,minRaise:o.bb,log:[],done:false,result:null,foldSeq:0,cur:-1,runout:false,
    players:o.players.map((p,i)=>{const s=o.stacks?o.stacks[i]:o.stack;return Object.assign({},p,{stack:s,start:s,bet:0,total:0,folded:false,allIn:false,acted:false,mayRaise:true,hole:[],foldAt:null,foldStreet:null,won:0})})};
  for(let k=0;k<2;k++)for(let j=1;j<=n;j++)g.players[(g.dealer+j)%n].hole.push(g.deck.shift());
  g.sbI=n===2?g.dealer:(g.dealer+1)%n;g.bbI=(g.sbI+1)%n;
  put(g,g.sbI,g.sb);put(g,g.bbI,g.bb);
  g.currentBet=g.bb;g.minRaise=g.bb;
  g.log.push(g.players[g.sbI].name+" posts "+g.sb+", "+g.players[g.bbI].name+" posts "+g.bb);
  g.cur=nextToAct(g,g.bbI);
  if(g.cur<0)endStreet(g); // the blinds put everyone (or all but one matched player) all-in: deal the board out
  return g;
}
function put(g,i,amt){const p=g.players[i],a=Math.max(0,Math.min(amt,p.stack));p.stack-=a;p.bet+=a;p.total+=a;if(p.stack===0)p.allIn=true;return a}
function needs(g,i){const p=g.players[i];return !p.folded&&!p.allIn&&(!p.acted||p.bet<g.currentBet)}
function nextToAct(g,from){const n=g.players.length;for(let k=1;k<=n;k++){const i=(from+k)%n;if(needs(g,i))return i}return -1}
function potTotal(g){return g.players.reduce((a,p)=>a+p.total,0)}
function pokerOptions(g){
  if(g.done||g.cur<0)return null;
  const p=g.players[g.cur],toCall=Math.max(0,g.currentBet-p.bet),maxTo=p.bet+p.stack,minTo=g.currentBet+g.minRaise;
  const others=g.players.filter((q,j)=>j!==g.cur&&!q.folded&&!q.allIn).length;
  const canRaise=p.mayRaise&&others>0&&p.stack>toCall;
  return {toCall,callAmt:Math.min(toCall,p.stack),callAllIn:toCall>0&&p.stack<=toCall,canCheck:toCall===0,canFold:toCall>0,
    canRaise,raiseMin:canRaise?Math.min(minTo,maxTo):0,raiseMax:canRaise?maxTo:0,onlyAllIn:canRaise&&maxTo<=minTo,
    isBet:g.currentBet===0,pot:potTotal(g),stack:p.stack,bet:p.bet,currentBet:g.currentBet,bb:g.bb};
}
function pokerAct(g,type,amt){
  const o=pokerOptions(g);if(!o)throw new Error("no action expected");
  const i=g.cur,p=g.players[i];let msg="";
  const call=()=>{const a=put(g,i,o.callAmt);msg=p.allIn?"calls "+a+" (all-in)":"calls "+a};
  const raise=to=>{
    if(to<=g.currentBet){call();return}
    const size=to-g.currentBet;put(g,i,to-p.bet);
    // a full raise re-opens the betting for everyone; a short all-in does not (callers may only call or fold),
    // except an opening bet: players who only checked may still raise it
    if(size>=g.minRaise){g.minRaise=size;g.players.forEach((q,j)=>{if(j!==i&&!q.folded&&!q.allIn){q.acted=false;q.mayRaise=true}})}
    else if(g.currentBet===0)g.players.forEach((q,j)=>{if(j!==i&&!q.folded&&!q.allIn)q.mayRaise=true});
    msg=(g.currentBet===0?"bets ":"raises to ")+to+(p.allIn?" (all-in)":"");g.currentBet=to;
  };
  if(type==="fold"){p.folded=true;p.foldAt=++g.foldSeq;p.foldStreet=g.street;msg="folds"}
  else if(type==="check"){if(!o.canCheck)throw new Error("can't check");msg="checks"}
  else if(type==="call"){if(o.toCall<=0)throw new Error("nothing to call");call()}
  else if(type==="raise"||type==="allin"){
    if(!o.canRaise){if(type==="allin"&&o.callAllIn){call()}else throw new Error("can't raise")}
    else{let to=type==="allin"?o.raiseMax:Math.round(+amt);
      if(!Number.isFinite(to))throw new Error("no raise amount");
      if(to<o.raiseMin&&to<o.raiseMax)throw new Error("raise too small");
      raise(Math.min(to,o.raiseMax))}
  } else throw new Error("unknown action "+type);
  p.acted=true;p.mayRaise=false;
  g.log.push(p.name+" "+msg);
  const live=g.players.filter(q=>!q.folded);
  if(live.length===1){finishFold(g,live[0]);return g}
  const nx=nextToAct(g,i);
  if(nx>=0){g.cur=nx;return g}
  endStreet(g);return g;
}
function endStreet(g){
  for(;;){
    g.players.forEach(p=>{p.bet=0;p.acted=false;p.mayRaise=true});
    g.currentBet=0;g.minRaise=g.bb;
    if(g.street===3){showdown(g);return}
    g.street++;
    const k=g.street===1?3:1;for(let j=0;j<k;j++)g.board.push(g.deck.shift());
    g.log.push(STREETS[g.street]+" dealt");
    const able=g.players.filter(p=>!p.folded&&!p.allIn).length;
    if(able>=2){g.cur=nextToAct(g,g.dealer);return}
    g.runout=true;
  }
}
function finishFold(g,w){
  const amt=potTotal(g);w.stack+=amt;w.won=amt;g.done=true;g.cur=-1;
  g.result={type:"fold",pots:[{amount:amt,eligible:[g.players.indexOf(w)],winners:[g.players.indexOf(w)]}],main:[g.players.indexOf(w)]};
  g.log.push(w.name+" wins "+amt+" (everyone else folded)");
}
function showdown(g){
  g.street=4;g.done=true;g.cur=-1;const n=g.players.length;
  g.players.forEach(p=>{if(!p.folded){p.hand=best(p.hole.concat(g.board))}});
  const live=g.players.filter(p=>!p.folded),names=kickerNames(live.map(p=>p.hand.score));
  live.forEach((p,k)=>{p.hand.name=names[k]});
  const pots=buildPots(g.players);
  pots.forEach(pt=>{
    let top=null,w=[];
    for(const i of pt.eligible){const s=g.players[i].hand.score,c=top?cmp(s,top):1;if(c>0){top=s;w=[i]}else if(c===0)w.push(i)}
    // odd chips go to the winner(s) closest to the dealer's left
    w.sort((a,b)=>((a-g.dealer-1+n)%n)-((b-g.dealer-1+n)%n));
    const share=Math.floor(pt.amount/w.length);let odd=pt.amount-share*w.length;
    pt.winners=w;pt.shares=w.map(()=>share);
    w.forEach((i,k)=>{const a=share+(odd>0?1:0);if(odd>0)odd--;pt.shares[k]=a;g.players[i].stack+=a;g.players[i].won+=a});
    pt.returned=pt.eligible.length===1&&pt.from===1; // only that player's own uncalled chips: handed back, not won
  });
  g.result={type:"showdown",pots,main:pots[0].winners};
  g.log.push("Showdown");
}
/* Finishing order: winner(s) of the main pot first, then more chips left = better,
   then (ties) a later fold beats an earlier one, players who reached the showdown beat folders, better hand beats worse. */
function pokerRanking(g){
  if(!g.done)return null;
  const win=new Set(g.result.main);
  const idx=g.players.map((p,i)=>i);
  const key=(a,b)=>{const A=g.players[a],B=g.players[b];
    return (win.has(b)-win.has(a))||(B.stack-A.stack)||((B.foldAt==null?1e9:B.foldAt)-(A.foldAt==null?1e9:A.foldAt))||(A.hand&&B.hand?cmp(B.hand.score,A.hand.score):0)};
  idx.sort((a,b)=>key(a,b)||a-b);
  // everyone who won the main pot shares 1st place (a split can leave them with a chip more or less)
  let place=0;return idx.map((i,k)=>{if(win.has(i))place=1;else if(k===0||win.has(idx[k-1])||key(idx[k-1],i)!==0)place=k+1;return {i,place,player:g.players[i]}});
}

/* ---------- Blackjack helpers ---------- */
function bjValue(cs){let t=0,aces=0;for(const c of cs){if(c.r===14){aces++;t+=11}else t+=Math.min(c.r,10)}
  while(t>21&&aces){t-=10;aces--}return {total:t,soft:aces>0,bust:t>21,blackjack:cs.length===2&&t===21}}

const Cards={SUITS,STREETS,rankCh,one,many,parse,cards,shuffle,newDeck,score5,cmp,best,handName,kickerNames,buildPots,
  pokerNew,pokerOptions,pokerAct,pokerRanking,potTotal,bjValue};
if(typeof module!=="undefined"&&module.exports)module.exports=Cards;else root.Cards=Cards;
})(typeof window!=="undefined"?window:globalThis);
