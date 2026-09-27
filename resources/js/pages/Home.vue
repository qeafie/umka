<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    appName: { type: String, required: true },
    emergencyGuides: { type: Array, required: true },
});

const activeView = ref('home');
const selectedGuideId = ref(null);
const draft = ref('');
const deliveryStatus = ref('');
const copyMessage = ref('');
const errors = ref({});
const isSubmitting = ref(false);
const form = reactive({
    issueType: 'water',
    address: '',
    apartment: '',
    residentName: '',
    details: '',
});

const selectedGuide = computed(() => props.emergencyGuides.find(
    (guide) => guide.id === selectedGuideId.value,
));

function showGuide(guideId) {
    selectedGuideId.value = guideId;
    activeView.value = 'guide';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function showRequestForm() {
    if (selectedGuide.value) {
        form.issueType = selectedGuide.value.id;
    }

    errors.value = {};
    draft.value = '';
    deliveryStatus.value = '';
    copyMessage.value = '';
    activeView.value = 'request';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function goHome() {
    activeView.value = 'home';
    selectedGuideId.value = null;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function generateDraft() {
    errors.value = {};
    draft.value = '';
    copyMessage.value = '';
    isSubmitting.value = true;

    try {
        const response = await fetch('/appeals/preview', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify(form),
        });
        const result = await response.json();

        if (!response.ok) {
            errors.value = result.errors ?? {};
            return;
        }

        draft.value = result.draft;
        deliveryStatus.value = result.deliveryStatus;
    } catch {
        copyMessage.value = 'Не удалось подготовить текст. Проверьте интернет и попробуйте ещё раз.';
    } finally {
        isSubmitting.value = false;
    }
}

async function copyDraft() {
    try {
        await navigator.clipboard.writeText(draft.value);
        copyMessage.value = 'Текст скопирован. Теперь его можно отправить в УК или ТСЖ.';
    } catch {
        copyMessage.value = 'Скопируйте текст обращения вручную.';
    }
}

function handleMaxBack() {
    if (activeView.value !== 'home') {
        goHome();
    }
}

let maxBackButton;

watch(activeView, (view) => {
    if (!maxBackButton) {
        return;
    }

    if (view === 'home') {
        maxBackButton.hide();
    } else {
        maxBackButton.show();
    }
}, { immediate: true });

onMounted(() => {
    maxBackButton = window.WebApp?.BackButton;

    if (maxBackButton) {
        maxBackButton.onClick(handleMaxBack);

        if (activeView.value !== 'home') {
            maxBackButton.show();
        }
    }
});

onBeforeUnmount(() => {
    maxBackButton?.offClick(handleMaxBack);
    maxBackButton?.hide();
});
</script>

<template>
    <Head :title="appName" />

    <main class="app-shell">
        <header class="topbar">
            <button class="brand" type="button" aria-label="На главную страницу" @click="goHome">
                <span class="brand-mark" aria-hidden="true">П</span>
                <span>{{ appName }}</span>
            </button>
            <span class="topbar-label">Помощь по дому</span>
        </header>

        <template v-if="activeView === 'home'">
            <section class="page-intro" aria-labelledby="home-title">
                <p class="eyebrow">Умный город · MAX</p>
                <h1 id="home-title">Что случилось дома?</h1>
                <p class="intro-copy">
                    Выберите ситуацию — подскажем, что сделать и куда обратиться.
                </p>
            </section>

            <div class="urgent-banner" role="note">
                <span class="urgent-mark" aria-hidden="true">!</span>
                <p>Если кому-то угрожает опасность, выйдите в безопасное место и позвоните 112.</p>
            </div>

            <section aria-labelledby="guides-title">
                <div class="section-heading">
                    <h2 id="guides-title">Быстрые инструкции</h2>
                    <p>Коротко и по шагам</p>
                </div>

                <div class="guide-grid">
                    <button
                        v-for="(guide, index) in emergencyGuides"
                        :key="guide.id"
                        class="guide-card"
                        :data-test="`guide-${guide.id}`"
                        type="button"
                        @click="showGuide(guide.id)"
                    >
                        <span class="guide-kicker">
                            <span class="guide-dot" aria-hidden="true"></span>
                            Ситуация {{ index + 1 }}
                        </span>
                        <h3>{{ guide.title }}</h3>
                        <p>{{ guide.summary }}</p>
                    </button>
                </div>
                <div v-if="emergencyGuides.length === 0" class="empty-state" data-test="guides-empty">
                    <span class="empty-state-mark" aria-hidden="true">i</span>
                    <div>
                        <h3>Инструкции временно недоступны</h3>
                        <p>Попробуйте зайти чуть позже. Если ситуация опасная, позвоните 112.</p>
                    </div>
                </div>
            </section>

            <section class="request-card" aria-labelledby="request-title">
                <div>
                    <h2 id="request-title">Нужно сообщить в УК или ТСЖ?</h2>
                    <p>Ответьте на несколько вопросов — подготовим обращение, которое можно скопировать.</p>
                </div>
                <button
                    class="button"
                    data-test="open-request-form"
                    type="button"
                    @click="showRequestForm"
                >
                    Составить обращение
                </button>
            </section>

            <p class="page-footnote">
                Инструкции помогают сориентироваться и не заменяют вызов аварийной службы.
                Номер аварийной службы дома обычно указан в квитанции или на стенде у подъезда.
            </p>
        </template>

        <template v-else-if="activeView === 'guide' && selectedGuide">
            <button class="back-button" type="button" @click="goHome">← Все ситуации</button>

            <article class="detail-panel">
                <p class="eyebrow">План действий</p>
                <h1>{{ selectedGuide.title }}</h1>
                <p class="detail-summary">{{ selectedGuide.summary }}</p>

                <div class="detail-alert" role="note">{{ selectedGuide.alert }}</div>

                <ol class="steps-list">
                    <li v-for="(step, index) in selectedGuide.steps" :key="step.title" class="step-item">
                        <span class="step-number" aria-hidden="true">{{ index + 1 }}</span>
                        <div>
                            <h2>{{ step.title }}</h2>
                            <p>{{ step.description }}</p>
                        </div>
                    </li>
                </ol>

                <div class="detail-actions">
                    <a
                        v-if="selectedGuide.urgentPhone"
                        class="button button-danger"
                        :href="`tel:${selectedGuide.urgentPhone}`"
                    >
                        Позвонить {{ selectedGuide.urgentPhone }}
                    </a>
                    <button class="button button-secondary" type="button" @click="showRequestForm">
                        Составить обращение в УК/ТСЖ
                    </button>
                </div>

                <a
                    v-if="selectedGuide.source"
                    class="source-link"
                    :href="selectedGuide.source.url"
                    target="_blank"
                    rel="noreferrer"
                >
                    {{ selectedGuide.source.label }}
                </a>
            </article>
        </template>

        <template v-else>
            <button class="back-button" type="button" @click="goHome">← На главную</button>

            <section class="form-panel" aria-labelledby="form-title">
                <p class="eyebrow">Сообщение в УК или ТСЖ</p>
                <h1 id="form-title">Составить обращение</h1>
                <p class="form-intro">
                    Укажите, что произошло. Мы подготовим понятный текст, который вы сможете проверить и скопировать.
                </p>

                <form class="form-grid" @submit.prevent="generateDraft">
                    <div class="field field-full">
                        <label for="issue-type">О чём обращение</label>
                        <select id="issue-type" v-model="form.issueType" name="issueType">
                            <option v-for="guide in emergencyGuides" :key="guide.id" :value="guide.id">
                                {{ guide.title }}
                            </option>
                        </select>
                        <span v-if="errors.issueType" class="field-error">{{ errors.issueType[0] }}</span>
                    </div>

                    <div class="field field-full">
                        <label for="address">Адрес дома</label>
                        <input
                            id="address"
                            v-model.trim="form.address"
                            autocomplete="street-address"
                            name="address"
                            placeholder="Город, улица, дом"
                            required
                        >
                        <span v-if="errors.address" class="field-error">{{ errors.address[0] }}</span>
                    </div>

                    <div class="field">
                        <label for="apartment">Квартира <span class="form-note">(необязательно)</span></label>
                        <input
                            id="apartment"
                            v-model.trim="form.apartment"
                            autocomplete="address-line2"
                            name="apartment"
                            placeholder="Например, 24"
                        >
                        <span v-if="errors.apartment" class="field-error">{{ errors.apartment[0] }}</span>
                    </div>

                    <div class="field">
                        <label for="resident-name">Ваше имя <span class="form-note">(необязательно)</span></label>
                        <input
                            id="resident-name"
                            v-model.trim="form.residentName"
                            autocomplete="name"
                            name="residentName"
                            placeholder="Как к вам обращаться"
                        >
                        <span v-if="errors.residentName" class="field-error">{{ errors.residentName[0] }}</span>
                    </div>

                    <div class="field field-full">
                        <label for="details">Что произошло</label>
                        <textarea
                            id="details"
                            v-model.trim="form.details"
                            name="details"
                            placeholder="Опишите, где и когда вы заметили проблему"
                            required
                        ></textarea>
                        <span v-if="errors.details" class="field-error">{{ errors.details[0] }}</span>
                    </div>

                    <div class="field-full form-footer">
                        <button class="button" data-test="submit-request" type="submit" :disabled="isSubmitting" :aria-busy="isSubmitting">
                            <span v-if="isSubmitting" class="spinner" aria-hidden="true"></span>
                            {{ isSubmitting ? 'Готовим текст…' : 'Подготовить текст' }}
                        </button>
                        <p v-if="isSubmitting" class="loading-message" role="status" data-test="submit-status">Готовим обращение. Обычно это занимает несколько секунд.</p>
                        <p class="form-note">Обращение не отправляется автоматически.</p>
                    </div>
                </form>
            </section>

            <section v-if="isSubmitting" class="draft-panel draft-loading" aria-busy="true" data-test="draft-skeleton">
                <span class="skeleton skeleton-heading"></span>
                <span class="skeleton skeleton-line"></span>
                <span class="skeleton skeleton-line skeleton-short"></span>
                <span class="skeleton skeleton-block"></span>
                <span class="sr-only">Готовим текст обращения</span>
            </section>

            <section v-else-if="draft" class="draft-panel" aria-live="polite" data-test="draft-result">
                <h2>Проверьте черновик</h2>
                <p class="draft-status">
                    Текст подготовлен. Он сохранён только на этом экране и не отправлен в УК или ТСЖ.
                </p>
                <pre class="draft-text">{{ draft }}</pre>
                <div class="form-footer">
                    <button class="button" type="button" @click="copyDraft">Скопировать обращение</button>
                    <p v-if="deliveryStatus === 'draft_only'" class="form-note" data-test="delivery-status">
                        Можно вставить текст в привычный канал связи с УК или ТСЖ.
                    </p>
                </div>
                <p v-if="copyMessage" class="form-note" role="status">{{ copyMessage }}</p>
            </section>

            <p v-else-if="copyMessage" class="form-note" role="status">{{ copyMessage }}</p>
        </template>
    </main>
</template>
