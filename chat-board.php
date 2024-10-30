<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/*
 * Plugin Name: Chat Board
 * Plugin URI: https://github.com/EduhubSolutions/chatboard/
 * Description: A Smart Chat Board for Support and Marketing
 * Version: 1.3.0
 * Author: Eduhub Solutions
 * Author URI: https://eduhub.solutions/
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl.html
 * © 2024 chatboardapp.com. All rights reserved.
 */

// Add admin menu
function chatboard_set_admin_menu() {
    add_submenu_page('options-general.php', 'Chat Board', 'Chat Board', 'manage_options', 'chat-board', 'chatboard_admin');
}
add_action('admin_menu', 'chatboard_set_admin_menu');

// Enqueue styles for admin panel
function chatboard_enqueue_admin() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (isset($_GET['page']) && sanitize_text_field($_GET['page']) === 'chat-board') {
        wp_enqueue_style('chatboard-admin-css', plugin_dir_url(__FILE__) . 'assets/style.css', [], '1.0', 'all');
    }
}
add_action('admin_enqueue_scripts', 'chatboard_enqueue_admin');

// Enqueue scripts for front-end
function chatboard_enqueue() {
    $settings = json_decode(get_option('chatboard-settings'), true);
    if (!$settings || empty($settings['chat-id'])) return;

    $inline_code = '';
    $page_id = get_the_ID();
    $exclusions = [
        !empty($settings['visibility-ids']) ? array_map('trim', explode(',', sanitize_text_field($settings['visibility-ids']))) : [],
        !empty($settings['visibility-post-types']) ? array_map('trim', explode(',', sanitize_text_field($settings['visibility-post-types']))) : [],
        sanitize_text_field($settings['visibility-type'] ?? '')
    ];

    // Selective chat loading
    if ($exclusions[2] && (
        ($exclusions[2] === 'show' && !in_array($page_id, $exclusions[0])) ||
        ($exclusions[2] === 'hide' && in_array($page_id, $exclusions[0]))
    )) {
        return;
    }

    if (!empty($exclusions[1])) {
        $post_type = get_post_type($page_id);
        if (($exclusions[2] === 'show' && !in_array($post_type, $exclusions[1])) ||
            ($exclusions[2] === 'hide' && in_array($post_type, $exclusions[1]))
        ) {
            return;
        }
    }

    // Multisite routing
    if (is_multisite() && !empty($settings['multisite-routing'])) {
        $inline_code .= 'var SB_DEFAULT_DEPARTMENT = ' . esc_js(get_current_blog_id()) . ';';
    }

    // WordPress user synchronization
    if (!empty($settings['synch-wp-users'])) {
        $current_user = wp_get_current_user();
        if ($current_user->ID) {
            $profile_image = get_avatar_url($current_user->ID, ['size' => 500]);
            $profile_image = (strpos($profile_image, '.jpg') || strpos($profile_image, '.png')) ? esc_url($profile_image) : '';
            $inline_code .= 'var SB_DEFAULT_USER = {
                first_name: "' . esc_js($current_user->user_firstname ?: $current_user->nickname) . '",
                last_name: "' . esc_js($current_user->user_lastname) . '",
                email: "' . esc_js($current_user->user_email) . '",
                profile_image: "' . $profile_image . '",
                extra: { "wp-id": "' . esc_js($current_user->ID) . '" }
            };';
        }
    }

    // Force language if set
    $language = !empty($settings['force-language']) ? '&lang=' . esc_attr($settings['force-language']) : '';

    // Enqueue chat script
    wp_enqueue_script('chat-init', 'https://dashboard.chatboardapp.com/account/js/init.js?id=' . esc_attr($settings['chat-id']) . $language, ['jquery'], '1.0', true);
    if ($inline_code) {
        wp_add_inline_script('jquery', $inline_code);
    }
}
add_action('wp_enqueue_scripts', 'chatboard_enqueue');

// Shortcode for chatboard tickets
function chatboard_tickets_shortcode() {
    wp_register_script('chatboard-tickets', '', [], '1.0.0', true);
    wp_enqueue_script('chatboard-tickets');
    wp_add_inline_script('chatboard-tickets', 'var SB_TICKETS = true;');
    return '<div id="sb-tickets"></div>';
}
add_shortcode('chatboard-tickets', 'chatboard_tickets_shortcode');

// Shortcode for chatboard articles
function chatboard_articles_shortcode() {
    return '<script>var SB_ARTICLES_PAGE = true;</script><div id="sb-articles" class="sb-loading"></div>';
}
add_shortcode('chatboard-articles', 'chatboard_articles_shortcode');

// Admin settings page
function chatboard_admin() { 
    // Retrieve saved settings from the database
    $settings = json_decode(get_option('chatboard-settings'), true);

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['chatboard_submit'])) {
        // Verify nonce before processing the form
        $sanitized_nonce = isset($_POST['sb_nonce']) ? sanitize_text_field($_POST['sb_nonce']) : '';
        if (!$sanitized_nonce || !wp_verify_nonce($sanitized_nonce, 'sb-nonce')) {
            wp_die(esc_html__('Security verification failed. Please refresh and try again.', 'chat-board'), 'Verification Error');
        }

        // Sanitize and validate inputs
        $chat_id = sanitize_text_field($_POST['chatboard-chat-id']);
        $settings = [
            'chat-id' => $chat_id,
            'multisite-routing' => filter_var(chatboard_isset($_POST, 'chatboard-multisite-routing', false), FILTER_VALIDATE_BOOLEAN),
            'visibility-type' => sanitize_text_field($_POST['chatboard-visibility-type']),
            'visibility-ids' => sanitize_text_field($_POST['chatboard-visibility-ids']),
            'visibility-post-types' => sanitize_text_field($_POST['chatboard-visibility-post-types']),
            'synch-wp-users' => filter_var(chatboard_isset($_POST, 'chatboard-synch-wp-users', false), FILTER_VALIDATE_BOOLEAN),
            'force-language' => sanitize_text_field($_POST['chatboard-force-language'])
        ];

        // Save the settings to the database
        update_option('chatboard-settings', wp_json_encode($settings));

        // Display a success message
        echo '<div class="updated"><p>' . esc_html__('Settings saved successfully!', 'chat-board') . '</p></div>';
    }

    // Display the admin settings form
    $settings = json_decode(get_option('chatboard-settings'), true);
    $force_language = chatboard_isset($settings, 'force-language');
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Chat Board Settings', 'chat-board'); ?></h1>
        <p><?php esc_html_e('For more information on installation and setup, visit', 'chat-board'); ?> <a href="https://docs.chatboardapp.com"><?php esc_html_e('docs.chatboardapp.com', 'chat-board'); ?></a></p>
        <ul id="main-menu">
            <li><?php esc_html_e('1. To embed the articles widget, use the shortcode:', 'chat-board'); ?> <b>[chatboard-articles]</b></li>
            <li><?php esc_html_e('2. To embed the tickets widget, use the shortcode:', 'chat-board'); ?> <b>[chatboard-tickets]</b></li>
        </ul>
        <form method="post" action="">
            <?php wp_nonce_field('sb-nonce', 'sb_nonce'); ?>
            <table class="form-table chatboard-table">
                <tr valign="top">
                    <th scope="row"><label for="chatboard-chat-id"><?php esc_html_e('Chat ID', 'chat-board'); ?></label></th>
                    <td>
                        <input type="text" id="chatboard-chat-id" name="chatboard-chat-id" 
                               value="<?php echo esc_attr(chatboard_isset($settings, 'chat-id')); ?>" />
                        <p class="description"><?php esc_html_e('Enter the embed code or the ID attribute from Chat Board.', 'chat-board'); ?></p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="chatboard-multisite-routing"><?php esc_html_e('Multisite Routing', 'chat-board'); ?></label></th>
                    <td>
                        <input type="checkbox" id="chatboard-multisite-routing" name="chatboard-multisite-routing"
                               <?php checked(chatboard_isset($settings, 'multisite-routing')); ?> />
                        <p class="description"><?php esc_html_e('Automatically route conversations to the department with the same ID as the WordPress site.', 'chat-board'); ?></p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="chatboard-visibility-type"><?php esc_html_e('Visibility', 'chat-board'); ?></label></th>
                    <td>
                        <select id="chatboard-visibility-type" name="chatboard-visibility-type">
                            <option value="" <?php selected(chatboard_isset($settings, 'visibility-type'), ''); ?>><?php esc_html_e('Disabled', 'chat-board'); ?></option>
                            <option value="show" <?php selected(chatboard_isset($settings, 'visibility-type'), 'show'); ?>><?php esc_html_e('Show', 'chat-board'); ?></option>
                            <option value="hide" <?php selected(chatboard_isset($settings, 'visibility-type'), 'hide'); ?>><?php esc_html_e('Hide', 'chat-board'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Choose whether to show or hide the chat on certain pages or post types.', 'chat-board'); ?></p>
                        <label for="chatboard-visibility-ids"><?php esc_html_e('Page IDs', 'chat-board'); ?></label>
                        <input type="text" id="chatboard-visibility-ids" name="chatboard-visibility-ids" 
                               value="<?php echo esc_attr(chatboard_isset($settings, 'visibility-ids')); ?>" />
                        <p class="description"><?php esc_html_e('Comma-separated list of page IDs.', 'chat-board'); ?></p>
                        <label for="chatboard-visibility-post-types"><?php esc_html_e('Post Type Slugs', 'chat-board'); ?></label>
                        <input type="text" id="chatboard-visibility-post-types" name="chatboard-visibility-post-types" 
                               value="<?php echo esc_attr(chatboard_isset($settings, 'visibility-post-types')); ?>" />
                        <p class="description"><?php esc_html_e('Comma-separated list of post type slugs.', 'chat-board'); ?></p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="chatboard-synch-wp-users"><?php esc_html_e('Synchronize WP Users', 'chat-board'); ?></label></th>
                    <td>
                        <input type="checkbox" id="chatboard-synch-wp-users" name="chatboard-synch-wp-users"
                               <?php checked(chatboard_isset($settings, 'synch-wp-users')); ?> />
                        <p class="description"><?php esc_html_e('Sync WordPress users with Chat Board.', 'chat-board'); ?></p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="chatboard-force-language"><?php esc_html_e('Force Language', 'chat-board'); ?></label></th>
                    <td>
                        <select id="chatboard-force-language" name="chatboard-force-language">
							<option value="" <?php if (empty($force_language)) echo 'selected'; ?>><?php esc_html_e('Disabled', 'chat-board'); ?></option>
							<option value="am" <?php if ($force_language == 'am') echo 'selected'; ?>><?php esc_html_e('Armenian', 'chat-board'); ?></option>
							<option value="bg" <?php if ($force_language == 'bg') echo 'selected'; ?>><?php esc_html_e('Bulgarian', 'chat-board'); ?></option>
							<option value="cs" <?php if ($force_language == 'cs') echo 'selected'; ?>><?php esc_html_e('Czech', 'chat-board'); ?></option>
							<option value="da" <?php if ($force_language == 'da') echo 'selected'; ?>><?php esc_html_e('Danish', 'chat-board'); ?></option>
							<option value="de" <?php if ($force_language == 'de') echo 'selected'; ?>><?php esc_html_e('German', 'chat-board'); ?></option>
							<option value="el" <?php if ($force_language == 'el') echo 'selected'; ?>><?php esc_html_e('Greek', 'chat-board'); ?></option>
							<option value="en" <?php if ($force_language == 'en') echo 'selected'; ?>><?php esc_html_e('English', 'chat-board'); ?></option>
							<option value="es" <?php if ($force_language == 'es') echo 'selected'; ?>><?php esc_html_e('Spanish', 'chat-board'); ?></option>
							<option value="et" <?php if ($force_language == 'et') echo 'selected'; ?>><?php esc_html_e('Estonian', 'chat-board'); ?></option>
							<option value="fa" <?php if ($force_language == 'fa') echo 'selected'; ?>><?php esc_html_e('Persian', 'chat-board'); ?></option>
							<option value="fi" <?php if ($force_language == 'fi') echo 'selected'; ?>><?php esc_html_e('Finnish', 'chat-board'); ?></option>
							<option value="fr" <?php if ($force_language == 'fr') echo 'selected'; ?>><?php esc_html_e('French', 'chat-board'); ?></option>
							<option value="he" <?php if ($force_language == 'he') echo 'selected'; ?>><?php esc_html_e('Hebrew', 'chat-board'); ?></option>
							<option value="hi" <?php if ($force_language == 'hi') echo 'selected'; ?>><?php esc_html_e('Hindi', 'chat-board'); ?></option>
							<option value="hr" <?php if ($force_language == 'hr') echo 'selected'; ?>><?php esc_html_e('Croatian', 'chat-board'); ?></option>
							<option value="hu" <?php if ($force_language == 'hu') echo 'selected'; ?>><?php esc_html_e('Hungarian', 'chat-board'); ?></option>
							<option value="id" <?php if ($force_language == 'id') echo 'selected'; ?>><?php esc_html_e('Indonesian', 'chat-board'); ?></option>
							<option value="it" <?php if ($force_language == 'it') echo 'selected'; ?>><?php esc_html_e('Italian', 'chat-board'); ?></option>
							<option value="ja" <?php if ($force_language == 'ja') echo 'selected'; ?>><?php esc_html_e('Japanese', 'chat-board'); ?></option>
							<option value="ka" <?php if ($force_language == 'ka') echo 'selected'; ?>><?php esc_html_e('Georgian', 'chat-board'); ?></option>
							<option value="ko" <?php if ($force_language == 'ko') echo 'selected'; ?>><?php esc_html_e('Korean', 'chat-board'); ?></option>
							<option value="mk" <?php if ($force_language == 'mk') echo 'selected'; ?>><?php esc_html_e('Macedonian', 'chat-board'); ?></option>
							<option value="mn" <?php if ($force_language == 'mn') echo 'selected'; ?>><?php esc_html_e('Mongolian', 'chat-board'); ?></option>
							<option value="my" <?php if ($force_language == 'my') echo 'selected'; ?>><?php esc_html_e('Burmese', 'chat-board'); ?></option>
							<option value="nl" <?php if ($force_language == 'nl') echo 'selected'; ?>><?php esc_html_e('Dutch', 'chat-board'); ?></option>
							<option value="no" <?php if ($force_language == 'no') echo 'selected'; ?>><?php esc_html_e('Norwegian', 'chat-board'); ?></option>
							<option value="pl" <?php if ($force_language == 'pl') echo 'selected'; ?>><?php esc_html_e('Polish', 'chat-board'); ?></option>
							<option value="pt" <?php if ($force_language == 'pt') echo 'selected'; ?>><?php esc_html_e('Portuguese', 'chat-board'); ?></option>
							<option value="ro" <?php if ($force_language == 'ro') echo 'selected'; ?>><?php esc_html_e('Romanian', 'chat-board'); ?></option>
							<option value="ru" <?php if ($force_language == 'ru') echo 'selected'; ?>><?php esc_html_e('Russian', 'chat-board'); ?></option>
							<option value="sk" <?php if ($force_language == 'sk') echo 'selected'; ?>><?php esc_html_e('Slovak', 'chat-board'); ?></option>
							<option value="sl" <?php if ($force_language == 'sl') echo 'selected'; ?>><?php esc_html_e('Slovenian', 'chat-board'); ?></option>
							<option value="sq" <?php if ($force_language == 'sq') echo 'selected'; ?>><?php esc_html_e('Albanian', 'chat-board'); ?></option>
							<option value="sr" <?php if ($force_language == 'sr') echo 'selected'; ?>><?php esc_html_e('Serbian', 'chat-board'); ?></option>
							<option value="su" <?php if ($force_language == 'su') echo 'selected'; ?>><?php esc_html_e('Sundanese', 'chat-board'); ?></option>
							<option value="sv" <?php if ($force_language == 'sv') echo 'selected'; ?>><?php esc_html_e('Swedish', 'chat-board'); ?></option>
							<option value="th" <?php if ($force_language == 'th') echo 'selected'; ?>><?php esc_html_e('Thai', 'chat-board'); ?></option>
							<option value="tr" <?php if ($force_language == 'tr') echo 'selected'; ?>><?php esc_html_e('Turkish', 'chat-board'); ?></option>
							<option value="uk" <?php if ($force_language == 'uk') echo 'selected'; ?>><?php esc_html_e('Ukrainian', 'chat-board'); ?></option>
							<option value="vi" <?php if ($force_language == 'vi') echo 'selected'; ?>><?php esc_html_e('Vietnamese', 'chat-board'); ?></option>
							<option value="zh" <?php if ($force_language == 'zh') echo 'selected'; ?>><?php esc_html_e('Chinese', 'chat-board'); ?></option>
						</select>
                        <p class="description"><?php esc_html_e('Force the chat to use a specific language.', 'chat-board'); ?></p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" class="button-primary" name="chatboard_submit" value="<?php esc_attr_e('Save changes', 'chat-board'); ?>" />
            </p>
        </form>
    </div>
    <?php
}

// Helper function for isset check
function chatboard_isset($array, $key, $default = '') {
    return isset($array[$key]) && $array[$key] !== '' ? $array[$key] : $default;
}

// Fix script tag ID
function chatboard_script_id_fix($tag, $handle, $src) {
    if ('chat-init' === $handle) {
        $tag = str_replace('<script ', '<script id="chat-init" ', $tag);
    }
    return $tag;
}
add_filter('script_loader_tag', 'chatboard_script_id_fix', 10, 3);

// Add a settings link to the plugin actions
function chatboard_add_settings_link($links) {
    $settings_link = '<a href="' . esc_url(admin_url('options-general.php?page=chat-board')) . '">' . esc_html__('Settings', 'chat-board') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

$plugin_file = plugin_basename(__FILE__);
add_filter("plugin_action_links_$plugin_file", 'chatboard_add_settings_link');

// Register activation hook
function chatboard_plugin_activation() {
    add_option('chatboard_show_activation_notice', true);
}
register_activation_hook(__FILE__, 'chatboard_plugin_activation');

// Display admin notice after plugin activation
function chatboard_admin_activation_notice() {
    if (get_option('chatboard_show_activation_notice')) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php 
                echo esc_html__('Chat Board has been activated successfully!', 'chat-board'); 
                ?>
                <a href="<?php echo esc_url(admin_url('options-general.php?page=chat-board')); ?>">
                    <?php esc_html_e('Go to Settings', 'chat-board'); ?>
                </a>
            </p>
        </div>
        <?php
        delete_option('chatboard_show_activation_notice');
    }
}
add_action('admin_notices', 'chatboard_admin_activation_notice');

?>