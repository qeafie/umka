<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { houseRequest } from './houseRequest';
const props = defineProps({ house: { type: Object, required: true }, incident: { type: Object, required: true } });
const open = ref(false);
const busy = ref(false);
const error = ref('');
const message = ref('');
const counts = ref({});
const form = reactive({ entrance: '', floorFrom: 1, floorTo: 1 });
const floors = computed(() => props.house.layout?.find((entry) => entry.entrance === Number(form.entrance))?.floors ?? 0);
watch(() => form.entrance, () => { form.floorFrom = 1; form.floorTo = floors.value || 1; });
watch(() => props.incident.status, () => { if (open.value) refresh(); });
const path = computed(() => `/houses/${props.house.id}/incidents/${props.incident.id}/surveys`);
async function refresh() {
    error.value = '';
    try { counts.value = (await houseRequest(path.value)).counts ?? {}; }
    catch (failure) { error.value = failure.message; }
}
async function toggle() { open.value = !open.value; if (open.value) await refresh(); }
async function send() {
    if (busy.value) return;
    busy.value = true; error.value = ''; message.value = '';
    try {
        const result = await houseRequest(path.value, 'POST', { entrance: Number(form.entrance), floorFrom: Number(form.floorFrom), floorTo: Number(form.floorTo) });
        message.value = `В очередь добавлено: ${result.queued}. Доставка ещё не подтверждена.`;
        await refresh();
    } catch (failure) { error.value = failure.message; }
    finally { busy.value = false; }
}
</script>
<template>
    <section class="house-settings" aria-label="Адресный опрос MAX">
        <button class="text-button" data-test="toggle-survey" type="button" :aria-expanded="open" @click="toggle">{{ open ? 'Скрыть опросы MAX' : 'Опросы и доставка MAX' }}</button>
        <div v-if="open">
            <p v-if="error" role="alert" class="meter-feedback meter-feedback-error">{{ error }}</p>
            <p v-if="message" role="status">{{ message }}</p>
            <p class="form-note">В очереди: {{ counts.pending ?? 0 }} · Отправлено: {{ counts.sent ?? 0 }} · Ошибок: {{ counts.failed ?? 0 }} · Результат неизвестен: {{ (counts.uncertain ?? 0) + (counts.sending ?? 0) }} · Отменено: {{ counts.cancelled ?? 0 }}</p>
            <button class="text-button" type="button" @click="refresh">Обновить доставку</button>
            <p v-if="incident.status === 'work_completed'" class="form-note">Запросы проверки восстановления добавляются автоматически для сообщивших о проблеме жителей с включёнными уведомлениями.</p>
            <p v-else-if="!house.layout?.length" class="form-note">Сначала попросите администратора настроить схему дома.</p>
            <form v-else @submit.prevent="send">
                <p class="form-note">Спросим только жителей выбранного участка с согласием на уведомления и без свежего ответа. Повторный запуск не дублирует действующий опрос.</p>
                <fieldset :disabled="busy" :aria-busy="busy">
                    <label class="field">Подъезд<select v-model="form.entrance" name="entrance" required><option value="" disabled>Выберите подъезд</option><option v-for="entry in house.layout" :key="entry.entrance" :value="entry.entrance">{{ entry.entrance }}</option></select></label>
                    <label class="field">С этажа<input v-model="form.floorFrom" type="number" name="floorFrom" min="1" :max="floors || 60" required></label>
                    <label class="field">По этаж<input v-model="form.floorTo" type="number" name="floorTo" :min="form.floorFrom" :max="floors || 60" required></label>
                    <button class="button button-secondary" type="submit">Запросить состояние</button>
                </fieldset>
            </form>
        </div>
    </section>
</template>
