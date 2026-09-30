<script setup>
import { onMounted, ref } from 'vue';
import HouseInvitations from './HouseInvitations.vue';
const showInvitations = ref(false);

const props = defineProps({ house: { type: Object, required: true } });
const emit = defineEmits(['updated']);
const members = ref([]);
const loading = ref(true);
const saving = ref(false);
const error = ref('');
const message = ref('');
const memberToRevoke = ref(null);
const roles = [
    { value: 'resident', label: 'Житель', description: 'Сообщает о проблемах и подтверждает состояние услуг.' },
    { value: 'dispatcher', label: 'Диспетчер', description: 'Видит тексты обращений, назначает исполнителя и меняет статус работ.' },
    { value: 'moderator', label: 'Модератор', description: 'Видит сводку дома. Разбор спорных сообщений пока недоступен.' },
];

function roleLabel(role) {
    return roles.find((option) => option.value === role)?.label ?? (role === 'house_admin' ? 'Администратор дома' : role);
}

async function request(path, method = 'GET', body) {
    let response;
    try {
        response = await fetch(path, {
            method,
            headers: {
                Accept: 'application/json',
                ...(method !== 'GET' ? { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' } : {}),
                ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
            },
            ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
        });
    } catch {
        throw new Error('Не удалось связаться с сервером. Проверьте подключение и повторите попытку.');
    }

    if (response.ok && response.status === 204) return null;
    const result = await response.json().catch(() => ({}));
    if (!response.ok) {
        if (response.status === 401 || response.status === 419) throw new Error('Сессия истекла. Откройте приложение заново через MAX.');
        if (response.status === 403) throw new Error('У вас больше нет прав на управление этим домом.');
        if (response.status === 404) throw new Error('Участник уже недоступен. Откройте список заново.');
        throw new Error(Object.values(result.errors ?? {}).flat()[0] ?? result.message ?? 'Не удалось сохранить изменения. Повторите попытку.');
    }
    return result;
}

async function loadMembers() {
    loading.value = true;
    error.value = '';
    try {
        const result = await request(`/admin/houses/${props.house.id}/members`);
        members.value = result.members.map((member) => ({ ...member, selectedRole: member.role }));
    } catch (failure) {
        error.value = failure.message;
    } finally {
        loading.value = false;
    }
}

async function saveRole(member) {
    if (saving.value || member.isCurrentUser || member.role === member.selectedRole) return;
    saving.value = true;
    error.value = '';
    message.value = '';
    memberToRevoke.value = null;
    try {
        const result = await request(`/admin/houses/${props.house.id}/members/${member.id}`, 'PUT', { role: member.selectedRole });
        member.role = result.member.role;
        member.selectedRole = result.member.role;
        message.value = `Роль сохранена: ${member.name} — ${roleLabel(member.role)}.`;
        emit('updated');
    } catch (failure) {
        error.value = failure.message;
    } finally {
        saving.value = false;
    }
}

async function revokeAccess(member) {
    if (saving.value || member.isCurrentUser || memberToRevoke.value !== member.id) return;
    saving.value = true;
    error.value = '';
    message.value = '';
    try {
        await request(`/admin/houses/${props.house.id}/members/${member.id}`, 'DELETE');
        members.value = members.value.filter((item) => item.id !== member.id);
        memberToRevoke.value = null;
        message.value = `Доступ отозван: ${member.name}.`;
        emit('updated');
    } catch (failure) {
        error.value = failure.message;
    } finally {
        saving.value = false;
    }
}

onMounted(loadMembers);
</script>

<template>
    <section class="house-settings house-member-access" aria-label="Управление участниками дома">
        <h2>Участники и доступ</h2>
        <button class="text-button" type="button" @click="showInvitations = !showInvitations">{{ showInvitations ? 'Скрыть приглашения' : 'Пригласить жителя' }}</button>
        <HouseInvitations v-if="showInvitations" :house="house" />
        <p class="form-note">Роли действуют только в доме «{{ house.name }}». Назначение администраторов здесь недоступно.</p>
        <p v-if="error" class="meter-feedback meter-feedback-error" role="alert">{{ error }}</p>
        <p v-if="message" class="meter-feedback" role="status">{{ message }}</p>
        <p v-if="loading" role="status" aria-busy="true">Загружаем участников…</p>
        <button v-else-if="error && !members.length" class="text-button" type="button" data-test="retry-members" @click="loadMembers">Повторить загрузку</button>
        <p v-else-if="!members.length" class="form-note">Участников пока нет.</p>

        <article v-for="member in members" :key="member.id" class="member-access-card" :data-test="`member-access-${member.id}`">
            <h3>{{ member.name }} <span v-if="member.isCurrentUser" class="member-self-label">Это вы</span></h3>
            <p class="form-note">Текущая роль: {{ roleLabel(member.role) }}</p>
            <p v-if="member.isCurrentUser" class="form-note">Собственную роль и доступ изменить нельзя.</p>
            <form v-else @submit.prevent="saveRole(member)">
                <fieldset :disabled="saving" :aria-busy="saving">
                    <legend class="sr-only">Доступ участника {{ member.name }}</legend>
                    <div class="member-role-row">
                        <label class="field">Роль в доме
                            <select v-model="member.selectedRole" :name="`member-role-${member.id}`" :aria-describedby="`role-description-${member.id}`">
                                <option v-if="member.role === 'house_admin'" value="house_admin" disabled>Администратор дома</option>
                                <option v-for="role in roles" :key="role.value" :value="role.value">{{ role.label }}</option>
                            </select>
                        </label>
                        <button class="button button-secondary" type="submit" :disabled="member.selectedRole === member.role">Сохранить роль</button>
                    </div>
                    <p :id="`role-description-${member.id}`" class="form-note">{{ roles.find((role) => role.value === member.selectedRole)?.description }}</p>
                    <div v-if="memberToRevoke === member.id" class="member-revoke-confirmation" data-test="revoke-confirmation">
                        <p>Отозвать доступ: {{ member.name }}?</p>
                        <p class="form-note">Участник потеряет доступ к дому «{{ house.name }}». Его сообщения сохранятся, доступ к другим домам не изменится.</p>
                        <div class="member-access-actions">
                            <button class="button button-danger" type="button" data-test="confirm-revoke" @click="revokeAccess(member)">Подтвердить отзыв</button>
                            <button class="button button-secondary" type="button" data-test="cancel-revoke" @click="memberToRevoke = null">Отмена</button>
                        </div>
                    </div>
                    <button v-else class="text-button member-revoke-button" type="button" data-test="revoke-access" @click="memberToRevoke = member.id; error = ''; message = ''">Отозвать доступ</button>
                </fieldset>
            </form>
        </article>
    </section>
</template>
