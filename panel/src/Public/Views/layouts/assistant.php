<?php if (\XcVm\Domain\Assistant\AssistantClient::enabled()): ?>
<link rel="stylesheet" href="assets/xcvm/assistant.css?v=<?= (int) filemtime(MAIN_HOME . 'Public/assets/admin/xcvm/assistant.css') ?>">
<div id="xc-assistant" data-endpoint="api?action=assistant">
    <button type="button" id="xc-ai-launch" aria-controls="xc-ai-panel" aria-expanded="false" aria-label="XC AI Assistant">✦ <span>XC AI</span></button>
    <section id="xc-ai-panel" aria-labelledby="xc-ai-title" hidden>
        <header><div><strong id="xc-ai-title">XC Assistant</strong><small id="xc-ai-subtitle"></small></div><button type="button" id="xc-ai-close" aria-label="Close">×</button></header>
        <div id="xc-ai-feed" role="log" aria-live="polite" aria-relevant="additions"></div>
        <div id="xc-ai-pending" hidden></div>
        <div id="xc-ai-status" role="status"></div>
        <form id="xc-ai-form"><label class="xc-ai-sr" for="xc-ai-input">Message / الرسالة</label><textarea id="xc-ai-input" rows="2" maxlength="1000" required dir="auto"></textarea><button type="submit" id="xc-ai-send"></button></form>
        <button type="button" id="xc-ai-reset"></button>
    </section>
</div>
<script src="assets/xcvm/assistant.js?v=<?= (int) filemtime(MAIN_HOME . 'Public/assets/admin/xcvm/assistant.js') ?>" defer></script>
<?php endif; ?>
