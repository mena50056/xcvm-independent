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
html.horus-booting:after{content:'حورس';position:fixed;inset:0;z-index:2147483000;display:grid;place-items:center;background:inherit;color:#d7af59;font:700 28px Tahoma,Arial,sans-serif;pointer-events:none}
</style>
<script>
(function(){
 var root=document.documentElement;
 root.classList.add('horus-booting');
 var fallback=setTimeout(reveal,8000);
 function reveal(){root.classList.remove('horus-booting');clearTimeout(fallback);}
 // Existing defer scripts finish registering before DOMContentLoaded. Two frames
 // let their synchronous layout work and initial table sizing finish before paint.
 document.addEventListener('DOMContentLoaded',function(){requestAnimationFrame(function(){requestAnimationFrame(reveal);});},{once:true});
 window.addEventListener('pageshow',function(event){if(event.persisted)reveal();});
})();
</script>
