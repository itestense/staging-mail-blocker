<?php
/**
 * Plugin Name: Staging Mail Blocker
 * Description: Blocca le email generate da WordPress e offre un registro configurabile.
 * Version: 1.1.0
 * Author: Paolo Niccolò Giubelli <paoloniccolo.giubelli@itestense.it>
 * Author URI: https://www.itestense.it
 * License: MIT
 * License URI: https://opensource.org/license/mit
 * Requires at least: 5.7
 */

namespace StagingMailBlocker;

defined('ABSPATH') || exit;

const SETTINGS = 'staging_mail_blocker_settings';
const SCHEMA_OPTION = 'staging_mail_blocker_schema';
const SCHEMA_VERSION = 1;
const PAGE = 'staging-mail-blocker';
const CLEAR_ACTION = 'staging_mail_blocker_clear';
const PAGE_SIZE = 20;
const DEFAULT_LIMIT = 100;
const MAX_LIMIT = 500;

add_filter('pre_wp_mail', __NAMESPACE__ . '\\block_mail', PHP_INT_MIN, 2);
add_action('admin_menu', __NAMESPACE__ . '\\register_page');
add_action('admin_init', __NAMESPACE__ . '\\register_settings');
add_action('admin_post_' . CLEAR_ACTION, __NAMESPACE__ . '\\clear_log');

function log_modes() {
    return [
        'database' => 'Registro in WordPress',
        'php'      => 'Log PHP',
        'both'     => 'Registro in WordPress e log PHP',
        'off'      => 'Nessuna registrazione',
    ];
}

function sanitize_settings($settings) {
    $settings = is_array($settings) ? $settings : [];
    $mode = $settings['mode'] ?? 'database';
    $limit = $settings['limit'] ?? DEFAULT_LIMIT;

    return [
        'mode'    => is_string($mode) && isset(log_modes()[$mode]) ? $mode : 'database',
        'details' => in_array($settings['details'] ?? 0, [1, '1'], true) ? 1 : 0,
        'limit'   => is_numeric($limit) ? max(1, min(MAX_LIMIT, (int) $limit)) : DEFAULT_LIMIT,
    ];
}

function block_mail($return, $atts) {
    try {
        $settings = sanitize_settings(get_option(SETTINGS, []));
        if ($settings['mode'] === 'off') {
            return true;
        }

        $entry = [
            'timestamp' => gmdate('c'),
            'status'    => 'blocked',
            'to'        => limit_value($atts['to'] ?? '', 4096),
            'subject'   => limit_value($atts['subject'] ?? '', 1024),
        ];

        if ($settings['details']) {
            $entry['message'] = limit_value($atts['message'] ?? '', 20 * 1024);
            foreach (['headers', 'attachments', 'embeds'] as $field) {
                if (isset($atts[$field])) {
                    $entry[$field] = limit_value($atts[$field], 4096);
                }
            }
        }

        $json = wp_json_encode($entry);
        if ($json === false) {
            error_log('[STAGING MAIL] Impossibile codificare una voce del registro.');
            return true;
        }

        if (in_array($settings['mode'], ['php', 'both'], true)) {
            error_log('[STAGING MAIL] ' . $json);
        }

        if (
            in_array($settings['mode'], ['database', 'both'], true)
            && !store_entry($json, $settings['limit'])
        ) {
            error_log('[STAGING MAIL] Impossibile aggiornare il registro nel database.');
        }
    } catch (\Throwable $exception) {
        error_log('[STAGING MAIL] Errore durante la registrazione di una email bloccata.');
    }

    return true;
}

function limit_value($value, $max_bytes) {
    if (is_array($value)) {
        $value = implode("\n", array_filter($value, 'is_string'));
    }
    if (!is_string($value)) {
        return '';
    }
    if (strlen($value) <= $max_bytes) {
        return $value;
    }

    $excerpt = substr($value, 0, $max_bytes);
    if (preg_match('//u', $value)) {
        // Remove any incomplete UTF-8 character at the byte limit.
        while (!preg_match('//u', $excerpt)) {
            $excerpt = substr($excerpt, 0, -1);
        }
    }

    return $excerpt . ' […]';
}

function table_name() {
    global $wpdb;
    return $wpdb->prefix . 'staging_mail_blocker_log';
}

function table_ready() {
    return (int) get_option(SCHEMA_OPTION, 0) === SCHEMA_VERSION;
}

function install_table($table) {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $collation = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE `$table` (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        payload longtext NOT NULL,
        PRIMARY KEY  (id)
    ) $collation;");

    $installed_table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
    if ($installed_table !== $table) {
        return false;
    }

    update_option(SCHEMA_OPTION, SCHEMA_VERSION, false);
    return true;
}

function store_entry($json, $limit) {
    global $wpdb;

    $table = table_name();
    $previous_suppression = $wpdb->suppress_errors(true);

    try {
        if (!table_ready() && !install_table($table)) {
            return false;
        }
        if ($wpdb->insert($table, ['payload' => $json], ['%s']) === false) {
            return false;
        }

        $cutoff = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM `$table` ORDER BY id DESC LIMIT 1 OFFSET %d",
            $limit
        ));

        if ($wpdb->last_error !== '') {
            return false;
        }
        if ($cutoff === null) {
            return true;
        }

        return $wpdb->query($wpdb->prepare("DELETE FROM `$table` WHERE id <= %d", $cutoff)) !== false;
    } finally {
        $wpdb->suppress_errors($previous_suppression);
    }
}

function register_settings() {
    register_setting(SETTINGS, SETTINGS, [
        'type'              => 'array',
        'sanitize_callback' => __NAMESPACE__ . '\\sanitize_settings',
    ]);
}

function register_page() {
    add_management_page(
        'Email bloccate',
        'Email bloccate',
        'manage_options',
        PAGE,
        __NAMESPACE__ . '\\render_page'
    );
}

function clear_log() {
    if (!current_user_can('manage_options')) {
        wp_die('Accesso negato.', '', ['response' => 403]);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        wp_die('Usa il modulo del registro.', '', ['response' => 405]);
    }

    check_admin_referer(CLEAR_ACTION);
    global $wpdb;

    $table = table_name();
    $cleared = !table_ready() || $wpdb->query("DELETE FROM `$table`") !== false;
    wp_safe_redirect(add_query_arg(
        ['page' => PAGE, 'log-cleared' => $cleared ? '1' : '0'],
        admin_url('tools.php')
    ));
    exit;
}

function render_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Accesso negato.', '', ['response' => 403]);
    }

    $settings = sanitize_settings(get_option(SETTINGS, []));
    ?>
    <div class="wrap">
        <h1>Email bloccate</h1>
        <p>Il blocco delle email inviate tramite <code>wp_mail()</code> resta attivo con tutte le impostazioni.</p>
        <?php settings_errors(); ?>
        <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
            <?php settings_fields(SETTINGS); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="smb-mode">Destinazione del registro</label></th>
                    <td>
                        <select id="smb-mode" name="<?php echo esc_attr(SETTINGS); ?>[mode]">
                            <?php foreach (log_modes() as $mode => $label) : ?>
                                <option value="<?php echo esc_attr($mode); ?>" <?php selected($settings['mode'], $mode); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Contenuto del registro</th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr(SETTINGS); ?>[details]" value="1"
                                <?php checked($settings['details'], 1); ?>>
                            Includi corpo, intestazioni e percorsi degli allegati e dei file incorporati
                        </label>
                        <p class="description">Data, destinatari e oggetto sono sempre inclusi quando la registrazione è attiva.
                            I dettagli possono contenere link riservati e dati personali.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="smb-limit">Numero massimo di voci nel database</label></th>
                    <td>
                        <input type="number" id="smb-limit" name="<?php echo esc_attr(SETTINGS); ?>[limit]"
                            value="<?php echo esc_attr($settings['limit']); ?>" min="1"
                            max="<?php echo esc_attr(MAX_LIMIT); ?>" required>
                        <p class="description">Il limite viene applicato alla prossima registrazione nel database.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <hr>
        <h2>Registro in WordPress</h2>
        <?php render_log(); ?>
    </div>
    <?php
}

function render_log() {
    global $wpdb;

    if (($_GET['log-cleared'] ?? '') === '1') {
        echo '<div class="notice notice-success inline"><p>Registro di WordPress svuotato.</p></div>';
    } elseif (($_GET['log-cleared'] ?? '') === '0') {
        echo '<div class="notice notice-error inline"><p>Impossibile svuotare il registro di WordPress.</p></div>';
    }

    $table = table_name();
    $count = table_ready() ? $wpdb->get_var("SELECT COUNT(*) FROM `$table`") : 0;
    if ($count === null) {
        echo '<p>Impossibile leggere il registro di WordPress.</p>';
        return;
    }
    if ((int) $count === 0) {
        echo '<p>Nessuna email bloccata nel registro di WordPress.</p>';
        return;
    }

    $last_page = (int) ceil($count / PAGE_SIZE);
    $requested_page = $_GET['log-page'] ?? 1;
    $page = is_numeric($requested_page) ? min($last_page, max(1, (int) $requested_page)) : 1;
    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT payload FROM `$table` ORDER BY id DESC LIMIT %d OFFSET %d",
        PAGE_SIZE,
        ($page - 1) * PAGE_SIZE
    ));

    if ($wpdb->last_error !== '') {
        echo '<p>Impossibile leggere il registro di WordPress.</p>';
        return;
    }
    ?>
    <table class="widefat striped">
        <thead>
            <tr><th scope="col">Data e ora</th><th scope="col">Destinatari</th><th scope="col">Oggetto</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $payload) :
            $entry = json_decode($payload, true);
            if (!is_array($entry) || !isset($entry['timestamp'], $entry['to'], $entry['subject'])) {
                continue;
            }
            $timestamp = strtotime($entry['timestamp']);
            ?>
            <tr>
                <td><?php echo esc_html(wp_date('d/m/Y H:i:s', $timestamp)); ?></td>
                <td style="white-space: pre-line; overflow-wrap: anywhere"><?php echo esc_html($entry['to']); ?></td>
                <td style="overflow-wrap: anywhere">
                    <?php echo esc_html($entry['subject']); ?>
                    <?php if (isset($entry['message'])) : ?>
                        <details>
                            <summary>Dettagli email</summary>
                            <?php foreach ([
                                'message'     => 'Corpo',
                                'headers'     => 'Intestazioni',
                                'attachments' => 'Percorsi degli allegati',
                                'embeds'      => 'Percorsi dei file incorporati',
                            ] as $key => $label) :
                                if (!isset($entry[$key]) || $entry[$key] === '') {
                                    continue;
                                }
                                ?>
                                <p><strong><?php echo esc_html($label); ?></strong></p>
                                <pre style="white-space: pre-wrap; overflow-wrap: anywhere"><?php echo esc_html($entry[$key]); ?></pre>
                            <?php endforeach; ?>
                        </details>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($last_page > 1) : ?>
        <div class="tablenav">
            <div class="tablenav-pages">
                <?php echo wp_kses_post(paginate_links([
                    'base'    => add_query_arg('log-page', '%#%', menu_page_url(PAGE, false)),
                    'format'  => '',
                    'total'   => $last_page,
                    'current' => $page,
                ])); ?>
            </div>
        </div>
    <?php endif; ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="<?php echo esc_attr(CLEAR_ACTION); ?>">
        <?php wp_nonce_field(CLEAR_ACTION); ?>
        <?php submit_button('Svuota il registro di WordPress', 'secondary'); ?>
    </form>
    <?php
}
