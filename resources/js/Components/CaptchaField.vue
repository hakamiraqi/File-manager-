<script setup>
import { onMounted } from 'vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    captcha: { type: Object, default: () => ({ enabled: false }) },
    modelValue: { type: String, default: '' },
    error: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

onMounted(() => {
    if (props.captcha?.enabled && props.captcha?.siteKey && !document.querySelector('#recaptcha-script')) {
        const script = document.createElement('script');
        script.id = 'recaptcha-script';
        script.src = 'https://www.google.com/recaptcha/api.js';
        script.async = true;
        script.defer = true;
        document.head.appendChild(script);
    }
});
</script>

<template>
    <div v-if="captcha?.enabled" class="mt-4">
        <template v-if="captcha.siteKey">
            <div
                class="g-recaptcha"
                :data-sitekey="captcha.siteKey"
                :data-size="captcha.invisible ? 'invisible' : 'normal'"
            />
        </template>
        <template v-else>
            <InputLabel value="رمز التحقق: كم يساوي" />
            <div class="mt-1 flex items-center gap-2">
                <span class="rounded-md bg-gray-100 px-3 py-2 font-mono text-sm" dir="ltr">{{ captcha.question }} =</span>
                <TextInput
                    :model-value="modelValue"
                    @update:model-value="(v) => emit('update:modelValue', v)"
                    type="number"
                    class="w-24"
                    required
                />
            </div>
            <InputError :message="error" class="mt-1" />
        </template>
    </div>
</template>
