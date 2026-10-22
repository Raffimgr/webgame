/* Ridge Games service worker: only for phone notifications (push.php sends them, rg.js turns them on).
   It doesn't touch page loads at all, so the site works exactly as before. */
self.addEventListener("install",()=>self.skipWaiting());
self.addEventListener("activate",e=>e.waitUntil(self.clients.claim()));

self.addEventListener("push",e=>{
  let d={};try{d=e.data?e.data.json():{}}catch(x){d={title:"Shenandoah Cabin",body:e.data?e.data.text():""}}
  e.waitUntil(self.registration.showNotification(d.title||"Shenandoah Cabin",{
    body:d.body||"",tag:d.tag||undefined,renotify:!!d.tag,icon:"icons/icon-192.png",badge:"icons/icon-192.png",
    data:{url:new URL(d.url||"./",self.registration.scope).href}}));
});

/* Tapping a notification opens that page: in the app if it's already open, otherwise a new window. */
self.addEventListener("notificationclick",e=>{
  e.notification.close();const url=(e.notification.data&&e.notification.data.url)||self.registration.scope;
  e.waitUntil((async()=>{
    const all=await self.clients.matchAll({type:"window",includeUncontrolled:true});
    for(const c of all){if(c.url.startsWith(self.registration.scope)&&"focus" in c&&"navigate" in c){
      try{await c.focus();await c.navigate(url);return}catch(x){}}}
    return self.clients.openWindow(url);
  })());
});
