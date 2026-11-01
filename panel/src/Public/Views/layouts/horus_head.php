<?php
/** First-paint boundary for the shared Horus shell; no user data is processed here. */
if (count(get_included_files()) === 1) { exit(); }
?>
<link rel="preload" href="assets/xcvm/cairo-panel-400.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="assets/xcvm/cairo-panel-700.woff2" as="font" type="font/woff2" crossorigin>
<style>
html.horus-booting{background:#10121b}
html.horus-booting[data-bs-theme=light]{background:#eef0f5}
html.horus-booting body{visibility:hidden!important}
#horus-page-loader{position:fixed;inset:0;z-index:2147483000;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:0;background:radial-gradient(ellipse at 50% 43%,#1b2b4099,transparent 48%),#10151f;color:#f4dfac;font-family:'Cairo Panel',Tahoma,Arial,sans-serif;visibility:visible!important;isolation:isolate}
#horus-page-loader:before{content:'';position:absolute;width:min(72vw,440px);aspect-ratio:1;border-radius:50%;background:radial-gradient(circle,#d7af590a,transparent 68%);pointer-events:none}
.horus-loader-emblem{width:156px;height:156px;overflow:visible;filter:drop-shadow(0 8px 24px #0005)}
.horus-loader-orbit{transform-origin:80px 80px;animation:horus-orbit 2.8s linear infinite}
.horus-loader-orbit-back{transform-origin:80px 80px;animation:horus-orbit 5s linear infinite reverse}
.horus-loader-falcon{animation:horus-lift 2.4s ease-in-out infinite}
.horus-loader-name{font-size:27px;font-weight:700;line-height:1.6;margin:12px 0 3px;color:#f4dfac}
.horus-loader-caption{font-size:12px;line-height:1.8;color:#aebdd1;margin:0;letter-spacing:.01em}
.horus-loader-track{width:112px;height:2px;border-radius:2px;overflow:hidden;background:#d7af5926;margin-top:22px}
.horus-loader-track:after{content:'';display:block;width:42%;height:100%;border-radius:inherit;background:linear-gradient(90deg,transparent,#eac66e,#fff1be);animation:horus-scan 1.8s ease-in-out infinite}
@keyframes horus-orbit{to{transform:rotate(360deg)}}
@keyframes horus-lift{0%,100%{transform:translateY(2px);opacity:.88}50%{transform:translateY(-3px);opacity:1}}
@keyframes horus-scan{0%{transform:translateX(-110%)}100%{transform:translateX(350%)}}
@media(max-width:575px){.horus-loader-emblem{width:136px;height:136px}.horus-loader-name{font-size:24px}}
@media(prefers-reduced-motion:reduce){#horus-page-loader *,#horus-page-loader :after{animation:none!important}.horus-loader-track:after{transform:translateX(70%)}}
</style>
<script>
(function(){
 var root=document.documentElement;
 root.classList.add('horus-booting');
 var loader=document.createElement('div');
 loader.id='horus-page-loader';loader.setAttribute('role','status');loader.setAttribute('aria-live','polite');loader.setAttribute('aria-label','جار تحميل الصفحة');
 loader.innerHTML='<svg class="horus-loader-emblem" viewBox="0 0 160 160" aria-hidden="true"><defs><linearGradient id="horus-loading-gold" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#fff0bd"/><stop offset=".55" stop-color="#e6bd62"/><stop offset="1" stop-color="#ab7a2b"/></linearGradient></defs><circle cx="80" cy="80" r="68" fill="none" stroke="#d7af5918" stroke-width="1"/><circle cx="80" cy="80" r="55" fill="#172233" stroke="#d7af5926"/><g class="horus-loader-orbit"><circle cx="80" cy="80" r="68" fill="none" stroke="url(#horus-loading-gold)" stroke-width="1.5" stroke-linecap="round" stroke-dasharray="78 350"/><circle cx="80" cy="12" r="2.5" fill="#f4dc9c"/></g><g class="horus-loader-orbit-back"><circle cx="80" cy="80" r="61" fill="none" stroke="#91bac7" stroke-opacity=".5" stroke-width="1" stroke-dasharray="18 174"/></g><g class="horus-loader-falcon"><g transform="translate(48 45)"><path d="M7 16l22 9-9 8 12 18 12-18-9-8 22-9-9 20-16 21-16-21z" fill="url(#horus-loading-gold)"/><path d="M25 16h14l7 8-12 3-2 10-7-11z" fill="#fff0c5"/><path d="M35 20h5l-3 4z" fill="#111c30"/><path d="M40 24l8 3-11 3z" fill="#e7b857"/></g></g></svg><div class="horus-loader-name" dir="rtl">حورس</div><p class="horus-loader-caption" dir="rtl">جارٍ تجهيز الصفحة</p><div class="horus-loader-track" aria-hidden="true"></div>';
 root.appendChild(loader);
 var fallback=setTimeout(reveal,8000);
 function reveal(){root.classList.remove('horus-booting');if(loader.parentNode)loader.parentNode.removeChild(loader);clearTimeout(fallback);}
 // Existing defer scripts finish registering before DOMContentLoaded. Two frames
 // let their synchronous layout work and initial table sizing finish before paint.
 document.addEventListener('DOMContentLoaded',function(){requestAnimationFrame(function(){requestAnimationFrame(reveal);});},{once:true});
 window.addEventListener('pageshow',function(event){if(event.persisted)reveal();});
})();
</script>
