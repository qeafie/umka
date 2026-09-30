<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { houseRequest } from './houseRequest';
const props = defineProps({ house: { type: Object, required: true } });
const invitations = ref([]);
const loading = ref(false);
const busy = ref(false);
const error = ref('');
const created = ref(null);
const copied = ref(false);
const form = reactive({ apartment: '', entrance: null, floor: null });
const floors = computed(() => props.house.layout?.find((item) => item.entrance === Number(form.entrance))?.floors ?? 0);
watch(() => form.entrance, () => { form.floor = null; });
const statusLabel = { pending: 'Ожидает принятия', accepted: 'Принято', revoked: 'Отозвано', expired: 'Истекло' };
async function load() {
    loading.value = true;
    error.value = '';
    try { invitations.value = (await houseRequest(`/admin/houses/${props.house.id}/invitations`)).invitations; }
    catch (failure) { error.value = failure.message; }
    finally { loading.value = false; }
}
async function create() {
    if (busy.value) return;
    busy.value = true; error.value = ''; copied.value = false; created.value = null;
    try {
        const result = await houseRequest(`/admin/houses/${props.house.id}/invitations`, 'POST', {
            apartment: form.apartment, entrance: form.entrance ? Number(form.entrance) : null, floor: form.floor ? Number(form.floor) : null,
        });
        created.value = result;
        invitations.value.unshift(result.invitation);
    } catch (failure) { error.value = failure.message; }
    finally { busy.value = false; }
}
async function revoke(invitation) {
    if (busy.value) return;
    busy.value = true; error.value = '';
    try {
        await houseRequest(`/admin/houses/${props.house.id}/invitations/${invitation.id}`, 'DELETE');
        invitation.status = 'revoked';
        if (created.value?.invitation.id === invitation.id) created.value = null;
    } catch (failure) { error.value = failure.message; }
    finally { busy.value = false; }
}
async function copy() {
    try { await navigator.clipboard.writeText(created.value.url ?? created.value.token); copied.value = true; }
    catch { error.value = 'Не удалось скопировать. Выделите и скопируйте код вручную.'; }
}
onMounted(load);
</script>
<template>
    <section class="house-settings" aria-label="Приглашения жителей">
        <h2>Пригласить жителя</h2>
        <p class="form-note">Одно приглашение — один житель. Действует 72 часа и даёт доступ к сводке дома. Передавайте лично после проверки адреса; приглашение не подтверждает право собственности.</p>
        <p v-if="error" role="alert" class="meter-feedback meter-feedback-error">{{ error }}</p>
        <form @submit.prevent="create">
            <fieldset :disabled="busy" :aria-busy="busy">
                <label class="field">Квартира<input v-model="form.apartment" name="apartment" maxlength="20" required></label>
                <label class="field">Подъезд<select v-model="form.entrance" name="entrance"><option :value="null">Без привязки</option><option v-for="entry in house.layout ?? []" :key="entry.entrance" :value="entry.entrance">{{ entry.entrance }}</option></select></label>
                <label v-if="form.entrance" class="field">Этаж<select v-model="form.floor" name="floor" required><option :value="null" disabled>Выберите этаж</option><option v-for="floor in floors" :key="floor" :value="floor">{{ floor }}</option></select></label>
                <button class="button button-primary" type="submit">Создать приглашение</button>
            </fieldset>
        </form>
        <div v-if="created" class="meter-feedback">
            <p>Сохраните {{ created.url ? 'ссылку' : 'код' }} сейчас — повторно он не показывается.</p>
            <label class="field">{{ created.url ? 'Ссылка MAX' : 'Код приглашения' }}<input data-test="invitation-secret" readonly :value="created.url ?? created.token" @focus="$event.target.select()"></label>
            <button type="button" class="text-button" @click="copy">{{ copied ? 'Скопировано' : 'Скопировать' }}</button>
            <p v-if="!created.url" class="form-note">Жителю: откройте Умку в MAX → «Проблемы в доме» → «Вступить по приглашению» и вставьте код.</p>
        </div>
        <p v-if="loading" role="status">Загружаем приглашения…</p>
        <button v-else-if="error" type="button" class="text-button" @click="load">Обновить список</button>
        <p v-else-if="!invitations.length" class="form-note">Приглашений пока нет.</p>
        <article v-for="invitation in invitations" :key="invitation.id" class="member-access-card">
            <p>Квартира {{ invitation.apartment }} · {{ statusLabel[invitation.status] }}</p>
            <p class="form-note">Действует до {{ new Date(invitation.expiresAt).toLocaleString('ru-RU') }}</p>
            <button v-if="invitation.status === 'pending'" data-test="revoke-invitation" :disabled="busy" type="button" class="text-button" @click="revoke(invitation)">Отозвать приглашение</button>
        </article>
    </section>
</template>
