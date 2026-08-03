// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';

// https://astro.build/config
export default defineConfig({
	site: 'https://docs.docuccino.app',
	integrations: [
		starlight({
			title: 'Docuccino',
			description:
				'UIR-based API documentation generator for Laravel: tier-3 inference, deterministic output, semantic diffing and a bundled Scalar viewer.',
			logo: {
				// The task-specified mapping: light theme → logo-light.svg, dark theme → logo.svg.
				light: './src/assets/logo-light.svg',
				dark: './src/assets/logo.svg',
				replacesTitle: true,
				alt: 'Docuccino',
			},
			favicon: '/icon.svg',
			social: [
				{ icon: 'github', label: 'GitHub', href: 'https://github.com/docuccino/docuccino' },
			],
			editLink: {
				baseUrl: 'https://github.com/docuccino/docuccino/edit/main/website/',
			},
			sidebar: [
				{
					label: 'Getting Started',
					items: [{ autogenerate: { directory: 'getting-started' } }],
				},
				{
					label: 'Reference',
					items: [
						{ label: 'Configuration reference', slug: 'reference/configuration' },
						{ label: 'Commands', slug: 'reference/commands' },
						{ label: 'Attributes reference', slug: 'reference/attributes' },
					],
				},
				{
					label: 'Integrations',
					items: [{ autogenerate: { directory: 'integrations' } }],
				},
				{
					label: 'Guides',
					items: [
						{ label: 'Writing an integration', slug: 'guides/extension-authoring' },
						{ label: 'Docuccino vs Scramble', slug: 'guides/vs-scramble' },
					],
				},
				{
					label: 'UIR spec',
					items: [{ autogenerate: { directory: 'uir' } }],
				},
			],
		}),
	],
});
