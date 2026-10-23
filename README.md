# Minimal Effort Markdown (GLPI plugin)

> Fork of [hellrazorx64/glpi-memarkdown](https://github.com/hellrazorx64/glpi-memarkdown), heavily rewritten. Thanks to HellrazorX64 for the original.

Write in Markdown anywhere GLPI has a rich-text editor.

## Compatibility

One download works on all supported GLPI versions:

| GLPI | Status |
| --- | --- |
| 10.0.x | Supported (tested on 10.0.28) |
| 11.0.x | Supported (tested on 11.0.11) |
| 12.0.x | Supported (tested on 12.0.0), see the GLPI 12 notes below |

## Features

- **Markdown toggle on every rich-text editor**, ON by default: KB articles and translations, ticket/change/problem descriptions, followups, tasks, solutions, validations and reminders. It works for new items and when editing existing ones.
- **GitHub-flavored Markdown**: tables, fenced code blocks with a language (```` ```bash ````), blockquotes, `---` rules, nested lists, task lists. Rendering uses [marked](https://github.com/markedjs/marked).
- **Your markdown is kept.** The exact text you wrote is stored, so reopening an item shows your original markdown, not a re-conversion. If someone later edits the item in the rich-text editor, the plugin converts the current HTML back to markdown with [turndown](https://github.com/mixmark-io/turndown).
- **Callouts**: Obsidian `> [!summary] Title` (all Obsidian types, foldable with `[!type]-` / `[!type]+`) and GitHub `> [!NOTE]` alerts.
- **One-click Copy button** on every code block in KB articles and the ticket timeline. It also works on plain-HTTP intranet servers.
- **Fully air-gapped**: no build step, no composer/npm, no CDN, no web fonts. Libraries are bundled in `public/js/vendor`; icons use the Tabler icon font GLPI already ships. Tested with every non-GLPI request blocked: zero attempted.
- Switch the toggle off at any time to go back to the normal TinyMCE editor for that field.

## Install / upgrade

1. Download the latest release archive and unpack it into `glpi/plugins/`, so the folder is `glpi/plugins/memarkdown` (the folder name must be `memarkdown`). Or clone it:
   `git clone https://github.com/mebessusn/glpi-memarkdown.git memarkdown`
2. Go to **Setup > Plugins** and click **Install** (new) or **Update** (upgrading), then **Enable**.
   Or use the CLI: `php bin/console plugin:install -u glpi --force memarkdown && php bin/console plugin:activate memarkdown`
3. Clear your browser cache (or hard-refresh) once.

Installing creates one table, `glpi_plugin_memarkdown_sources`, which holds the markdown text. Uninstalling drops it. The rendered HTML always stays in GLPI's own fields, so articles display normally even with the plugin disabled.

## GLPI 12 notes

GLPI 12 replaced the knowledge base editor with a new block editor (Tiptap) that saves articles in the background. On GLPI 12 knowledge base articles:

- The Markdown toggle appears when you create an article or click **Edit**. GLPI's own **Add article** / **Save** buttons save it.
- Reopening an article shows markdown converted from the saved article, not your exact original text (headings, lists, tables, code blocks and links come back as written).
- Callouts (`> [!note]`) lose their colored box and become a bold title plus normal text, because the new editor has no callout block.
- The code-block **Copy** button is not added inside GLPI 12 knowledge base articles (the new editor manages that area itself).

Tickets, followups, tasks, solutions and the other editors work exactly as on GLPI 10/11.

## Limitations

- Pasting an *image* into the markdown box does not upload it. Switch the toggle off, paste the image in the rich-text editor, then switch back on. The image is kept as `![](/front/document.send.php?docid=…)`.
- The GLPI 11 Forms builder is excluded on purpose.
- Obsidian-only syntax such as `[[wikilinks]]` and `![[embeds]]` is shown as plain text.
- Task-list checkboxes (`- [ ]`) render as plain bullets, because GLPI strips form inputs from saved content.

## License

MIT. Bundled libraries: marked (MIT), turndown (MIT), turndown-plugin-gfm (MIT). Their licenses are in `public/js/vendor/`.
