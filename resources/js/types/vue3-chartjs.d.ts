/**
 * `@j-t-mcc/vue3-chartjs` ships a correct `dist/index.d.ts` (its `package.json`
 * "types" field names it), but its `exports` map declares only `import`/
 * `require` conditions and no `types` condition — under this project's
 * `moduleResolution: "bundler"`, TypeScript resolves package types through
 * `exports` and never falls back to the legacy `types` field once `exports`
 * exists, so the real declaration file is unreachable by package-specifier
 * resolution (confirmed: TS7016 "implicitly has an 'any' type" pointing at
 * the resolved `.es.js`, not a missing-package error). This is an upstream
 * packaging omission, not a wrong or absent declaration — this shim exists so
 * the component's usage stays type-checked; the shape below mirrors
 * `node_modules/@j-t-mcc/vue3-chartjs/dist/index.d.ts` for exactly what
 * `TrendChart.vue` (T27) uses: the four props and the exposed `update`/
 * `destroy` instance methods it calls via a template ref.
 */
declare module '@j-t-mcc/vue3-chartjs' {
    import type {
        Chart,
        ChartData,
        ChartOptions,
        ChartType,
        Plugin,
    } from 'chart.js';
    import type { DefineComponent } from 'vue';

    type UpdateMode =
        'resize' | 'reset' | 'default' | 'none' | 'hide' | 'show' | 'active';

    type Vue3ChartJsProps = {
        type: ChartType;
        height?: number;
        width?: number;
        data: ChartData;
        options?: ChartOptions;
        plugins?: Plugin[];
    };

    type Vue3ChartJsExposed = {
        chartJSState: {
            chart: Chart | null;
        };
        render: () => void;
        destroy: () => void;
        update: (mode?: UpdateMode) => void;
        resize: () => void;
    };

    const Vue3ChartJs: DefineComponent<Vue3ChartJsProps, Vue3ChartJsExposed>;
    export default Vue3ChartJs;
}
