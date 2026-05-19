module.exports = {
    root: true,
    env: {
        browser: true,
        es2022: true,
        node: true,
    },
    extends: [
        'eslint:recommended',
        'plugin:@typescript-eslint/recommended',
        'plugin:react-hooks/recommended',
    ],
    parser: '@typescript-eslint/parser',
    parserOptions: {
        ecmaVersion: 'latest',
        sourceType: 'module',
        ecmaFeatures: {
            jsx: true,
        },
    },
    plugins: ['@typescript-eslint', 'react-refresh'],
    ignorePatterns: ['public/build', 'node_modules', 'vendor'],
    rules: {
        'react-refresh/only-export-components': ['warn', { allowConstantExport: true }],
    },
    overrides: [
        {
            files: ['**/*.test.ts', '**/*.test.tsx'],
            env: {
                node: true,
            },
        },
    ],
};
