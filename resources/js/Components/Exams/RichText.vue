<script setup>
import { ref, watch, onMounted, nextTick } from 'vue';
import renderMathInElement from 'katex/contrib/auto-render';
import 'katex/dist/katex.min.css';

// A question's text on screen: formatted HTML (already cleaned by the server) or plain text with
// its line breaks; equations between $…$, $$…$$, \(…\) or \[…\] are drawn by KaTeX, offline.
const props = defineProps({
    text: { type: String, default: '' },
    html: { type: Boolean, default: false },
    tag: { type: String, default: 'div' },
});
const el = ref(null);
const escape = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

function render() {
    if (!el.value) return;
    el.value.innerHTML = props.html ? props.text || '' : escape(props.text || '');
    if (/\$|\\\(|\\\[/.test(props.text || '')) {
        try {
            renderMathInElement(el.value, {
                delimiters: [
                    { left: '$$', right: '$$', display: true },
                    { left: '\\[', right: '\\]', display: true },
                    { left: '\\(', right: '\\)', display: false },
                    { left: '$', right: '$', display: false },
                ],
                throwOnError: false,
                ignoredTags: ['script', 'noscript', 'style', 'textarea', 'pre', 'code', 'option'],
            });
        } catch { /* leave the text as it is */ }
    }
}
onMounted(render);
watch(() => [props.text, props.html], () => nextTick(render));
</script>

<template>
    <component :is="tag" ref="el" dir="auto" class="rich-text" :class="html ? '' : 'whitespace-pre-line'" />
</template>

<style>
.rich-text p { margin: 0.35em 0; }
.rich-text ul { list-style: disc; padding-inline-start: 1.5em; }
.rich-text ol { list-style: decimal; padding-inline-start: 1.5em; }
.rich-text table { border-collapse: collapse; margin: 0.5em 0; }
.rich-text th, .rich-text td { border: 1px solid #9ca3af; padding: 0.25em 0.6em; }
.rich-text th { background: rgba(156, 163, 175, 0.15); }
.rich-text img, .rich-text video { max-width: 100%; max-height: 60vh; border-radius: 0.375rem; }
.rich-text audio { max-width: 100%; }
.rich-text blockquote { border-inline-start: 3px solid #9ca3af; padding-inline-start: 0.75em; color: #6b7280; }
.rich-text pre, .rich-text code { font-family: ui-monospace, monospace; direction: ltr; }
.rich-text .katex { font-size: 1.08em; }
.rich-text .katex-display { direction: ltr; overflow-x: auto; overflow-y: hidden; }
</style>
