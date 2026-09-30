<script setup>
import { ref } from 'vue';
import { houseRequest } from './houseRequest';
const props = defineProps({ house: { type: Object, required: true } });
const emit = defineEmits(['updated']);
const enabled = ref(!!props.house.notificationsEnabled);
const busy = ref(false);
const error = ref('');
const message = ref('');
async function save(event) {
    if (busy.value) return;
    busy.value = true; error.value = ''; message.value = '';
    try {
        enabled.value = (await houseRequest(`/houses/${props.house.id}/notifications`, 'PUT', { enabled: event.target.checked })).enabled;
        message.value = enabled.value ? 'Уведомления включены. Начните диалог с ботом в MAX, чтобы получать сообщения.' : 'Уведомления отключены.';
        emit('updated', enabled.value);
    } catch (failure) { error.value = failure.message; }
    finally { event.target.checked = enabled.value; busy.value = false; }
}
</script>
<template>
    <section class="house-settings" aria-label="Уведомления MAX">
        <label><input type="checkbox" :checked="enabled" :disabled="busy" @change="save"> Получать уведомления и короткие опросы по этому дому в MAX</label>
        <p class="form-note">Только по вашему согласию. Можно отключить в любой момент. Ответы соседям показываются общей сводкой.</p>
        <p v-if="message" role="status">{{ message }}</p>
        <p v-if="error" role="alert" class="meter-feedback meter-feedback-error">{{ error }}</p>
    </section>
</template>
