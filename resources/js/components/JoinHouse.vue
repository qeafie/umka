<script setup>
import { ref, watch } from 'vue';
import { houseRequest } from './houseRequest';
const props = defineProps({ authenticated: Boolean, initialToken: { type: String, default: '' } });
const emit = defineEmits(['joined']);
const token = ref(props.initialToken);
const invitation = ref(null);
const busy = ref(false);
const error = ref('');
const joined = ref(false);
watch(() => props.initialToken, (value) => { if (value) token.value = value; });
watch(token, () => { invitation.value = null; });
async function preview() {
    if (busy.value || !props.authenticated) return;
    busy.value = true; error.value = ''; invitation.value = null;
    try { invitation.value = (await houseRequest('/invitations/preview', 'POST', { token: token.value.trim() })).invitation; }
    catch (failure) { error.value = failure.message; }
    finally { busy.value = false; }
}
async function accept() {
    if (busy.value || !invitation.value) return;
    busy.value = true; error.value = '';
    try {
        const result = await houseRequest('/invitations/accept', 'POST', { token: token.value.trim() });
        joined.value = true; token.value = ''; invitation.value = null; emit('joined', result.house);
    } catch (failure) { error.value = failure.message; }
    finally { busy.value = false; }
}
</script>
<template>
    <section class="house-settings" aria-label="Вступить по приглашению">
        <h2>Вступить по приглашению</h2>
        <p v-if="!authenticated" class="form-note">Откройте Умку через MAX и дождитесь входа, затем введите код от администратора дома.</p>
        <p v-if="error" role="alert" class="meter-feedback meter-feedback-error">{{ error }}</p>
        <p v-if="joined" role="status">Вы присоединились к дому.</p>
        <form v-else @submit.prevent="preview">
            <fieldset :disabled="!authenticated || busy" :aria-busy="busy">
                <label class="field">Код приглашения<input v-model="token" name="invitation-code" autocomplete="off" maxlength="64" required></label>
                <button class="button button-secondary" type="submit">Проверить приглашение</button>
            </fieldset>
        </form>
        <div v-if="invitation">
            <h3>{{ invitation.house.name }}</h3><p>{{ invitation.house.address }} · квартира {{ invitation.apartment }}</p>
            <p class="form-note">Вы получите роль жителя. Убедитесь, что это ваш дом и квартира.</p>
            <button data-test="accept-invitation" class="button button-primary" type="button" :disabled="busy" @click="accept">Подтвердить и вступить</button>
        </div>
    </section>
</template>
