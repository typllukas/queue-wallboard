import js from '@eslint/js';
import { defineConfig } from 'eslint/config';
import nextVitals from 'eslint-config-next/core-web-vitals';
import react from 'eslint-plugin-react';
import typescriptEslint from 'typescript-eslint';

export default defineConfig(
  { ignores: ['.next/**', 'next-env.d.ts'] },
  ...nextVitals,
  js.configs.recommended,
  typescriptEslint.configs.recommendedTypeChecked,
  {
    plugins: { react },
    settings: { react: { version: 'detect' } },
    languageOptions: {
      parserOptions: { projectService: { allowDefaultProject: ['eslint.config.mjs'] }, tsconfigRootDir: import.meta.dirname },
    },
    rules: {
      '@typescript-eslint/consistent-type-assertions': ['error', { assertionStyle: 'never' }],
      '@typescript-eslint/consistent-type-definitions': ['error', 'type'],
      '@typescript-eslint/no-deprecated': 'error',
      '@typescript-eslint/no-non-null-assertion': 'error',
      '@typescript-eslint/no-unnecessary-type-parameters': 'error',
      '@typescript-eslint/switch-exhaustiveness-check': 'error',
      '@typescript-eslint/no-explicit-any': 'error',
      'id-length': ['error', { min: 2, exceptions: ['id'] }],
      'no-restricted-syntax': [
        'error',
        {
          selector:
            'NewExpression[callee.name=/Error$/] :matches(Literal[raw=/[^\\x00-\\x7F]/], TemplateElement[value.raw=/[^\\x00-\\x7F]/])',
          message: 'Error messages are for developers: English only.',
        },
        {
          // a literal reaching a typed API through a variable skips the excess-property check
          selector:
            ':matches(Program, ExportNamedDeclaration) > VariableDeclaration[kind="const"] > VariableDeclarator[id.typeAnnotation=undefined][init.type=/^(ObjectExpression|ArrayExpression)$/]',
          message: 'Give a module-level object or array literal a type: `satisfies T`, an annotation, or `as const`.',
        },
      ],
      'react/jsx-key': ['error', { checkFragmentShorthand: true }],
      'react/no-array-index-key': 'error',
      'react/jsx-no-target-blank': 'error',
      'no-restricted-imports': [
        'error',
        { patterns: [{ regex: '^\\.\\./', message: 'Import from outside the folder through @/, which does not break when a file moves.' }] },
      ],
    },
  },
);
