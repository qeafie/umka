<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({
    appName: { type: String, required: true },
});
</script>

<template>
    <main class="flex min-h-screen items-center justify-center bg-slate-50 px-6 py-16 text-slate-900">
        <Head :title="appName" />
        <section class="w-full max-w-xl rounded-3xl border border-slate-200 bg-white p-8 shadow-sm sm:p-12">
            <p class="mb-5 text-sm font-semibold tracking-widest text-teal-700 uppercase">Умный город</p>
            <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">{{ appName }}</h1>
            <p class="mt-6 text-lg leading-relaxed text-slate-600">
                Помогаем жителям и диспетчерам понять, где в доме возникла проблема
                и у кого она устранена.
            </p>
            <p class="mt-8 inline-flex rounded-full bg-teal-50 px-4 py-2 text-sm font-medium text-teal-800">
                Приложение в разработке
            </p>
        </section>
    </main>
</template>
