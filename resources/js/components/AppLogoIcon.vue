<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 512 512"
        :class="className"
        v-bind="$attrs"
    >
        <!--
            The terminal's interior, drawn first so everything else sits on
            top of it. Its geometry is taken from the frame path's own inner
            subpath rather than approximated: x 21.333 to 490.667, y 106.667
            (where the title bar ends) to 490.667, with the bottom two corners
            rounded to 32 and the top two square. Filling the shape itself is
            what avoids needing a background behind a non-square icon, where
            the corner radius would not line up.
        -->
        <path
            class="fill-white"
            d="M21.333 106.667H490.667V458.667A32 32 0 0 1 458.667 490.667H53.333A32 32 0 0 1 21.333 458.667Z"
        />
        <path
            d="m87.467 358.4c2.133 2.133 4.267 2.133 8.533 2.133s6.4 0 8.533-2.133l64-64c4.267-4.267 4.267-10.667 0-14.934l-64-64c-4.267-4.267-10.667-4.267-14.934 0s-4.267 10.667 0 14.934l55.467 55.467-55.466 55.467c-4.267 6.4-4.267 12.799-2.133 17.066z"
        />
        <!--
            The message line pulses to read as traffic moving through the
            proxy. `animate-pulse-full-quick` is defined in
            `resources/css/app.css` — it is not Tailwind's own
            `animate-pulse`, which is slower and only dips to half opacity.
        -->
        <path
            class="animate-pulse-full-quick"
            d="m202.667 341.333h106.667c6.4 0 10.667-4.267 10.667-10.667s-4.267-10.667-10.667-10.667h-106.667c-6.4 0-10.667 4.267-10.667 10.667s4.267 10.667 10.667 10.667z"
        />
        <path
            d="m458.667 0h-405.334c-29.866 0-53.333 23.467-53.333 53.333v405.333c0 29.867 23.467 53.334 53.333 53.334h405.333c29.867 0 53.334-23.467 53.334-53.333v-405.334c0-29.866-23.467-53.333-53.333-53.333zm-320 42.667c6.4 0 10.667 4.267 10.667 10.667s-4.268 10.666-10.667 10.666c-6.4 0-10.667-4.267-10.667-10.667s4.267-10.666 10.667-10.666zm-42.667 0c6.4 0 10.667 4.267 10.667 10.667s-4.267 10.666-10.667 10.666-10.667-4.267-10.667-10.667 4.267-10.666 10.667-10.666zm-42.667 0c6.4 0 10.667 4.267 10.667 10.667s-4.267 10.666-10.667 10.666-10.667-4.267-10.667-10.667 4.268-10.666 10.667-10.666zm437.334 416c0 17.066-14.934 32-32 32h-405.334c-17.066 0-32-14.934-32-32v-352h469.333v352z"
        />
    </svg>
</template>
