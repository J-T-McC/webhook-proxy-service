import type { HighlighterCore } from 'shiki/core';

/**
 * Shiki, in its fine-grained bundle: only the grammars the documentation page
 * actually uses, and the JavaScript regex engine rather than the oniguruma
 * WASM build, so no `.wasm` asset ships. Every import here is dynamic — the
 * highlighter loads only once a page asks to highlight something, and never
 * on first paint.
 */
let highlighter: Promise<HighlighterCore> | null = null;

const LANGUAGE_LOADERS = {
    bash: () => import('shiki/langs/bash.mjs'),
    javascript: () => import('shiki/langs/javascript.mjs'),
    json: () => import('shiki/langs/json.mjs'),
    php: () => import('shiki/langs/php.mjs'),
};

export type CodeLanguage = keyof typeof LANGUAGE_LOADERS;

export function getHighlighter(): Promise<HighlighterCore> {
    highlighter ??= (async () => {
        const [{ createHighlighterCore }, { createJavaScriptRegexEngine }] =
            await Promise.all([
                import('shiki/core'),
                import('shiki/engine/javascript'),
            ]);

        return createHighlighterCore({
            themes: [
                import('shiki/themes/github-light-default.mjs'),
                import('shiki/themes/github-dark-default.mjs'),
            ],
            langs: [],
            engine: createJavaScriptRegexEngine(),
        });
    })();

    return highlighter;
}

/**
 * Both themes in one pass. `defaultColor: false` emits the light colour as a
 * CSS custom property alongside the dark one, so the rendered block follows
 * the theme toggle without re-highlighting.
 */
export async function highlight(
    code: string,
    lang: CodeLanguage,
): Promise<string> {
    const shiki = await getHighlighter();

    // Grammars load per language, not up front: the PHP grammar is the
    // heaviest of the four and is only fetched once a reader opens that tab.
    if (!shiki.getLoadedLanguages().includes(lang)) {
        await shiki.loadLanguage(await LANGUAGE_LOADERS[lang]());
    }

    return shiki.codeToHtml(code, {
        lang,
        themes: { light: 'github-light-default', dark: 'github-dark-default' },
        defaultColor: false,
    });
}
