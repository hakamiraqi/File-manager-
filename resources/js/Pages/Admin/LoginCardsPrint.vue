<script setup>
// Login cards to print on A4 and cut (like the clinics system's): 10 a page, 2 by 5, dashed
// lines to cut along. Each card: the site, the label, the name, the user name and password, the
// addresses, the Wi-Fi, and a QR code that signs the student straight in. See LoginCardController.
import { computed, onMounted, reactive } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import { useTrans } from '@/Composables/useTrans';

const { t } = useTrans();
const props = defineProps({ name: String, cards: Array, settings: Object });
const pages = computed(() => {
    const out = [];
    for (let i = 0; i < props.cards.length; i += 10) out.push(props.cards.slice(i, i + 10));
    return out;
});
// The QR code signs in on the address written on the card (its /card/… page).
const base = (u) => { try { const x = new URL(u); return x.origin; } catch { return ''; } };
const qrBase = base(props.settings.alt_url) || base(props.settings.login_url) || window.location.origin;
const qr = reactive({});
onMounted(() => props.cards.forEach((c, i) => c.card_code && QRCode.toDataURL(`${qrBase}/card/${c.card_code}`, { margin: 1, width: 220, errorCorrectionLevel: 'M' }).then((u) => (qr[i] = u))));
const printNow = () => window.print();
const show = (u) => String(u || '').replace(/\/+$/, '');
</script>

<template>
    <Head :title="t('admin.cards.title', 'بطاقات الدخول') + ' — ' + name" />
    <div class="cards-root" dir="rtl">
        <div class="bar">
            <Link :href="route('admin.cards.index')" class="back">{{ t('admin.cards.back', 'رجوع') }}</Link>
            <span>{{ t('admin.cards.bar', ':n بطاقة · :p صفحة A4 · اطبع ثم قص على الخطوط المنقطة', { n: cards.length, p: pages.length }) }}</span>
            <button type="button" @click="printNow">{{ t('admin.cards.print_pdf', 'طباعة / حفظ PDF') }}</button>
        </div>
        <section v-for="(pg, p) in pages" :key="p" class="page">
            <div v-for="(c, k) in pg" :key="k" class="card">
                <div class="head"><span class="title">{{ settings.title }}</span><span class="label">{{ c.label }}</span></div>
                <div class="name" dir="auto">{{ c.name }}</div>
                <div class="body">
                    <table>
                        <tr><td class="k">{{ t('admin.cards.username', 'اسم المستخدم') }}</td><td class="v">{{ c.username }}</td></tr>
                        <tr v-if="c.password"><td class="k">{{ t('admin.cards.password', 'كلمة المرور') }}</td><td class="v pw">{{ c.password }}</td></tr>
                        <tr><td class="k">{{ t('admin.cards.system', 'رابط النظام') }}</td><td class="v">{{ show(settings.login_url) }}</td></tr>
                        <tr v-if="settings.alt_url"><td class="k">{{ t('admin.cards.by_ip', 'أو بالآيبي') }}</td><td class="v">{{ show(settings.alt_url) }}</td></tr>
                        <tr v-if="settings.wifi"><td class="k">{{ t('admin.cards.wifi_short', 'شبكة الواي فاي') }}</td><td class="v wifi" dir="auto">{{ settings.wifi }}</td></tr>
                    </table>
                    <div v-if="c.card_code" class="qr"><img v-if="qr[p * 10 + k]" :src="qr[p * 10 + k]" alt="QR" /><span>{{ t('admin.cards.scan', 'امسح للدخول') }}</span></div>
                </div>
                <div v-if="settings.note" class="note">{{ settings.note }}</div>
            </div>
        </section>
    </div>
</template>

<style>
@page { size: A4; margin: 8mm; }
.cards-root { min-height: 100vh; margin: 0; font-family: Tahoma, "Segoe UI", Arial, sans-serif; color: #111; background: #f3f4f6; }
.cards-root * { box-sizing: border-box; }
.cards-root .bar { position: sticky; top: 0; z-index: 2; display: flex; gap: 12px; align-items: center; justify-content: center; padding: 10px; background: #0e7490; color: #fff; font-size: 14px; flex-wrap: wrap; }
.cards-root .bar button, .cards-root .bar .back { font: inherit; padding: 6px 18px; border: 0; border-radius: 18px; background: #fff; color: #0e7490; cursor: pointer; text-decoration: none; }
.cards-root .page { display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: repeat(5, minmax(0, 1fr)); width: 194mm; height: 280mm; margin: 10px auto; background: #fff; page-break-after: always; break-after: page; }
.cards-root .page:last-child { page-break-after: auto; break-after: auto; }
.cards-root .card { border: 1px dashed #888; padding: 3mm 4.5mm; display: flex; flex-direction: column; gap: 1.2mm; overflow: hidden; }
.cards-root .head { display: flex; justify-content: space-between; align-items: center; gap: 2mm; border-bottom: 1.5px solid #0e7490; padding-bottom: 1mm; }
.cards-root .title { font-weight: bold; color: #0e7490; font-size: 10.5pt; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cards-root .label { font-size: 8.5pt; background: #e0f2fe; color: #075985; padding: .3mm 2.5mm; border-radius: 3mm; white-space: nowrap; }
.cards-root .name { font-weight: bold; font-size: 11.5pt; text-align: center; }
.cards-root table { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
.cards-root td { padding: .35mm 0; }
.cards-root .k { color: #555; width: 21mm; white-space: nowrap; }
.cards-root .v { direction: ltr; text-align: left; font-family: Consolas, "DejaVu Sans Mono", monospace; font-size: 10pt; word-break: break-all; }
.cards-root .pw { font-weight: bold; font-size: 12pt; letter-spacing: .6mm; }
.cards-root .wifi { font-weight: bold; }
.cards-root .body { display: flex; gap: 2.5mm; align-items: center; }
.cards-root .body table { flex: 1; min-width: 0; }
.cards-root .qr { flex: none; display: flex; flex-direction: column; align-items: center; gap: .5mm; font-size: 6.5pt; color: #555; }
.cards-root .qr img { width: 21mm; height: 21mm; image-rendering: pixelated; }
.cards-root .note { font-size: 7.5pt; color: #666; margin-top: auto; line-height: 1.35; }
@media print { .cards-root { background: #fff; } .cards-root .bar { display: none; } .cards-root .page { margin: 0; } }
</style>
