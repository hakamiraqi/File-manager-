import { onBeforeUnmount, onMounted, ref } from 'vue';

// Wide screen (from Tailwind's lg): the page's title and buttons sit in the top bar; on a phone
// or a tablet they get their own row under it, so nothing is squeezed or covered.
export function useWide(query = '(min-width: 1024px)') {
    const mq = typeof window !== 'undefined' ? window.matchMedia(query) : null;
    const wide = ref(mq ? mq.matches : true);
    const on = (e) => (wide.value = e.matches);
    onMounted(() => mq?.addEventListener?.('change', on));
    onBeforeUnmount(() => mq?.removeEventListener?.('change', on));
    return wide;
}
