<?php
/**
 * ---------------------------------------------------------------------
 *
 * Minimal Effort Markdown - GLPI Plugin
 *
 * Adds a Markdown mode (on by default) to every rich-text editor:
 * KB articles, ticket/change/problem descriptions, followups, tasks,
 * solutions, validations, reminders.
 *
 * @copyright 2024 HellrazorX64 (original glpi-memarkdown)
 * @copyright 2026 Megan Bess
 * @license   MIT
 * @homepage  https://github.com/mebessusn/glpi-memarkdown
 * Based on https://github.com/hellrazorx64/glpi-memarkdown
 *
 * ---------------------------------------------------------------------
 */

define('PLUGIN_MEMARKDOWN_VERSION', '2.2.0');
define('PLUGIN_MEMARKDOWN_MIN_GLPI', '10.0.0');
define('PLUGIN_MEMARKDOWN_MAX_GLPI', '13.0.0');

/**
 * Item types whose rich-text markdown source is remembered between edits.
 * Editors on other item types still get the Markdown toggle; their markdown
 * is just re-derived from the saved HTML when reopened.
 */
function plugin_memarkdown_itemtypes(): array
{
    return [
        'KnowbaseItem',
        'KnowbaseItemTranslation',
        'Ticket', 'Change', 'Problem',
        'ITILFollowup',
        'TicketTask', 'ChangeTask', 'ProblemTask',
        'ITILSolution',
        'TicketValidation', 'ChangeValidation',
        'Reminder',
    ];
}

function plugin_init_memarkdown()
{
    global $PLUGIN_HOOKS;

    if (version_compare(GLPI_VERSION, '11.0.0', '<')) {
        // Always enforced (and set automatically) in GLPI 11+
        $PLUGIN_HOOKS['csrf_compliant']['memarkdown'] = true;
    }

    // GLPI 11 serves plugin assets from public/ and URLs must NOT contain "/public"
    // (still works but logs a deprecation per request). GLPI 10 serves the plugin root.
    $prefix = version_compare(GLPI_VERSION, '11.0.0', '>=') ? '' : 'public/';

    if (Session::getLoginUserID()) {
        $PLUGIN_HOOKS['add_css']['memarkdown'] = $prefix . 'css/markdown.css';
        $PLUGIN_HOOKS['add_javascript']['memarkdown'] = [
            $prefix . 'js/vendor/marked.umd.js',
            $prefix . 'js/vendor/turndown.js',
            $prefix . 'js/vendor/turndown-plugin-gfm.js',
            $prefix . 'js/markdown-toggle.js',
            $prefix . 'js/code-copy.js',
        ];
    }

    $hooked = [];
    foreach (plugin_memarkdown_itemtypes() as $itemtype) {
        $hooked[$itemtype] = 'plugin_memarkdown_item_saved';
    }
    $PLUGIN_HOOKS['item_add']['memarkdown']    = $hooked;
    $PLUGIN_HOOKS['item_update']['memarkdown'] = $hooked;

    $purge = [];
    foreach (plugin_memarkdown_itemtypes() as $itemtype) {
        $purge[$itemtype] = 'plugin_memarkdown_item_purged';
    }
    $PLUGIN_HOOKS['item_purge']['memarkdown'] = $purge;

    // Emits stored markdown sources inside each item form
    $PLUGIN_HOOKS['post_item_form']['memarkdown'] = 'plugin_memarkdown_post_item_form';
}

function plugin_version_memarkdown()
{
    return [
        'name'         => 'Minimal Effort Markdown',
        'version'      => PLUGIN_MEMARKDOWN_VERSION,
        'author'       => 'Megan Bess (based on work by HellrazorX64)',
        'license'      => 'MIT',
        'homepage'     => 'https://github.com/mebessusn/glpi-memarkdown',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_MEMARKDOWN_MIN_GLPI,
                'max' => PLUGIN_MEMARKDOWN_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_memarkdown_check_prerequisites()
{
    return true; // version range enforced by GLPI via 'requirements'
}

function plugin_memarkdown_check_config($verbose = false)
{
    return true;
}
