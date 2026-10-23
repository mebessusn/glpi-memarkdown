# Minimal Effort Markdown (GLPI plugin)

> Fork of [hellrazorx64/glpi-memarkdown](https://github.com/hellrazorx64/glpi-memarkdown), heavily rewritten. Thanks to HellrazorX64 for the original.

Write in Markdown anywhere GLPI has a rich-text editor. Tested on **GLPI 11.0.6**. GLPI 10.0.x is supported too.

## Features

- **Markdown toggle on every rich-text editor**, ON by default: KB articles and translations, ticket/change/problem descriptions, followups, tasks, solutions, validations and reminders. It works for new items and when editing existing ones.
- **GitHub-flavored Markdown**: tables, fenced code blocks with a language (```` ```bash ````), blockquotes, `---` rules, nested lists, task lists. Rendering uses [marked](https://github.com/markedjs/marked).
- **Your markdown is kept.** The exact text you wrote is stored, so reopening an item shows your original markdown, not a re-conversion. If someone later edits the item in the rich-text editor, the plugin converts the current HTML back to markdown with [turndown](https://github.com/mixmark-io/turndown).
- **Callouts**: Obsidian `> [!summary] Title` (all Obsidian types, foldable with `[!type]-` / `[!type]+`) and GitHub `> [!NOTE]` alerts.
- **One-click Copy button** on every code block in KB articles and the ticket timeline. It also works on plain-HTTP intranet servers.
- **Fully air-gapped**: no build step, no composer/npm, no CDN, no web fonts. Libraries are bundled in `public/js/vendor`; icons use the Tabler icon font GLPI already ships. Tested with every non-GLPI request blocked: zero attempted.
- Switch the toggle off at any time to go back to the normal TinyMCE editor for that field.

## Install / upgrade

1. Put the folder at `glpi/plugins/memarkdown` (the folder name must be `memarkdown`).
2. Go to **Setup > Plugins** and click **Install** (new) or **Update** (upgrading from 1.x), then **Enable**.
   Or use the CLI: `php bin/console plugin:install -u glpi --force memarkdown && php bin/console plugin:activate memarkdown`
3. Clear your browser cache (or hard-refresh) once.

Installing creates one table, `glpi_plugin_memarkdown_sources`, which holds the markdown text. Uninstalling drops it. The rendered HTML always stays in GLPI's own fields, so articles display normally even with the plugin disabled.

## Limitations

- Pasting an *image* into the markdown box does not upload it. Switch the toggle off, paste the image in the rich-text editor, then switch back on. The image is kept as `![](/front/document.send.php?docid=…)`.
- The GLPI 11 Forms builder is excluded on purpose.
- Obsidian-only syntax such as `[[wikilinks]]` and `![[embeds]]` is shown as plain text.
- Task-list checkboxes (`- [ ]`) render as plain bullets, because GLPI strips form inputs from saved content.

## License

MIT. Bundled libraries: marked (MIT), turndown (MIT), turndown-plugin-gfm (MIT). Their licenses are in `public/js/vendor/`.
