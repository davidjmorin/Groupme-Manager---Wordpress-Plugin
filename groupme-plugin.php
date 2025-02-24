<?php
/*
Plugin Name: GroupMe Admin User List & Add Users
Description: A plugin to fetch and display GroupMe group members from multiple groups in the WordPress admin panel, grouped by user, and allows adding users to selected groups.
Version: 1.5
Author: Diamond Dave
License: GPL2
*/

if (!defined('ABSPATH')) {
    exit; 
}

function groupme_admin_menu() {
    add_menu_page(
        'GroupMe Users',          
        'GroupMe Users',          
        'manage_options',         
        'groupme-users',          
        'groupme_admin_page',     
        'dashicons-groups',       
        25                        
    );

    add_submenu_page(
        'groupme-users',          
        'GroupMe Settings',       
        'Settings',               
        'manage_options',         
        'groupme-settings',       
        'groupme_settings_page'   
    );
}
add_action('admin_menu', 'groupme_admin_menu');

function groupme_admin_page() {
    ?>
    <style>

        .group-checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .group-checkbox input[type="checkbox"] {
            flex-shrink: 0;
            transform: scale(1.2);
        }

        .group-checkbox label {
            display: flex;
            align-items: center;
            line-height: 1.2;
        }
    </style>
    <div class="container-fluid mt-4">
        <div class="row">
            <!-- Left Column (Fixed Form) -->
            <div class="col-md-3">
                <div class="card shadow-sm position-sticky" style="top: 20px;">
                    <div class="card-body">
                        <h5 class="card-title">Add/Remove User from Group</h5>
                        <?php echo groupme_add_user_form(); ?>
                    </div>
                </div>
            </div>

            <!-- Right Column (Full Width User List) -->
            <div class="col-md-9">
                <h5 class="card-title">Group Members</h5>
                <input type="text" id="groupmeUserSearch" class="form-control mb-3" placeholder="Search users...">
                <div class="table-responsive" style="max-height: 75vh; overflow-y: auto;">
                    <?php echo groupme_get_users(); ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function groupme_enqueue_scripts() {

    wp_enqueue_style('bootstrap-css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css');

    wp_enqueue_script('bootstrap-js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js', array('jquery'), null, true);

    wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css');
    wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/js/select2.min.js', array('jquery'), null, true);

    wp_add_inline_script('select2', '
        jQuery(document).ready(function($) {
            $("#user_select").select2();
        });
    ');

    wp_add_inline_script('jquery', '
        jQuery(document).ready(function($) {
            $("#groupmeUserSearch").on("keyup", function() {
                var value = $(this).val().toLowerCase();
                $("table tbody tr").filter(function() {
                    $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
                });
            });
        });
    ');
}
add_action('admin_enqueue_scripts', 'groupme_enqueue_scripts');

function groupme_settings_page() {

    if (isset($_POST['groupme_settings_submit'])) {

        $api_token = isset($_POST['groupme_api_token']) ? sanitize_text_field($_POST['groupme_api_token']) : '';
        update_option('groupme_api_token', $api_token);

        $group_ids_input = isset($_POST['groupme_group_ids']) ? $_POST['groupme_group_ids'] : '';
        $group_ids = array_map('sanitize_text_field', explode(',', $group_ids_input));
        $group_ids = array_filter($group_ids); 
        update_option('groupme_group_ids', $group_ids);

        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }

    $api_token = get_option('groupme_api_token');
    $group_ids = get_option('groupme_group_ids', []); 
    $group_ids_string = implode(',', $group_ids); 
    ?>
    <div class="wrap">
        <h1>GroupMe API Settings</h1>
        <form method="post" action="">
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">GroupMe API Token</th>
                    <td>
                        <input type="text" name="groupme_api_token" value="<?php echo esc_attr($api_token); ?>" class="regular-text" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Group IDs (comma-separated)</th>
                    <td>
                        <input type="text" name="groupme_group_ids" value="<?php echo esc_attr($group_ids_string); ?>" class="regular-text" />
                        <p class="description">Enter Group IDs separated by commas (e.g., 12345,67890).</p>
                    </td>
                </tr>
            </table>
            <?php wp_nonce_field('groupme_settings_nonce'); ?>
            <input type="submit" name="groupme_settings_submit" class="button button-primary" value="Save Settings" />
        </form>
    </div>
    <?php
}

function groupme_get_users() {
    $access_token = get_option('groupme_api_token');
    $group_ids = get_option('groupme_group_ids', []);

    if (!$access_token || empty($group_ids)) {
        return '<div class="alert alert-warning">Please configure your API token and Group IDs in the settings page.</div>';
    }

    $users = [];

    foreach ($group_ids as $group_id) {
        $url = "https://api.groupme.com/v3/groups/$group_id?token=$access_token";
        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            error_log('GroupMe API error for group ' . $group_id . ': ' . $response->get_error_message());
            continue; 
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['response']['members'])) {
            error_log('No members found or invalid response for group ' . $group_id);
            continue;
        }

        $group_name = isset($data['response']['name']) ? esc_html($data['response']['name']) : 'Unknown Group';

        foreach ($data['response']['members'] as $member) {
            $nickname = esc_html($member['nickname']);
            $user_id = esc_attr($member['user_id']);

            if (!isset($users[$user_id])) {
                $users[$user_id] = [
                    'nickname' => $nickname,
                    'user_id' => $user_id,
                    'groups' => []
                ];
            }
            $users[$user_id]['groups'][] = $group_name;
        }
    }

    $output = '<table class="table table-striped table-hover">';
    $output .= '<thead class="table-dark"><tr><th>Nickname</th><th>User ID</th><th>Groups</th></tr></thead><tbody>';

    foreach ($users as $user) {
        $groups = implode(', ', $user['groups']);
        $output .= '<tr>';
        $output .= '<td>' . $user['nickname'] . '</td>';
        $output .= '<td>' . $user['user_id'] . '</td>';
        $output .= '<td>' . $groups . '</td>';
        $output .= '</tr>';
    }

    $output .= '</tbody></table>';
    return $output;
}

function groupme_add_user_form() {
    $group_ids = get_option('groupme_group_ids', []);
    $access_token = get_option('groupme_api_token');

    if (!$access_token || empty($group_ids)) {
        return '<div class="alert alert-warning">Please configure your API token and Group IDs in the settings page.</div>';
    }

    $users = groupme_get_users_list($access_token, $group_ids);

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'groupme_add_remove_user')) {
            return '<div class="alert alert-danger">Security check failed. Please try again.</div>';
        }
        $user_select = isset($_POST['user_select']) ? sanitize_text_field($_POST['user_select']) : '';
        $selected_groups = isset($_POST['groups']) ? array_map('sanitize_text_field', $_POST['groups']) : [];

        if (empty($user_select) || empty($selected_groups)) {
            return '<div class="alert alert-danger">Please select a user and at least one group.</div>';
        }

        if (isset($_POST['add'])) {
            return groupme_add_user_to_groups($user_select, $selected_groups, $access_token);
        }

        if (isset($_POST['remove'])) {
           return groupme_remove_user_from_groups($user_select, $selected_groups, $access_token);
        }
    }

    $output = '<form method="POST" action="" class="mt-3">';
    $output .= wp_nonce_field('groupme_add_remove_user'); 
    $output .= '<div class="mb-3">';
    $output .= '<label for="user_select" class="form-label">Select Brother:</label>';
    $output .= '<select name="user_select" id="user_select" class="form-select" required>';
    $output .= '<option value="">Select Brother</option>';

    foreach ($users as $user) {
        $output .= '<option value="' . esc_attr($user['user_id']) . '">' . esc_html($user['nickname']) . '</option>';
    }

    $output .= '</select></div>';

    $output .= '<div class="mb-3"><label class="form-label">Select Groups:</label>';

    $group_names = [];
    foreach ($group_ids as $group_id) {
        $url = "https://api.groupme.com/v3/groups/$group_id?token=$access_token";
        $response = wp_remote_get($url);
        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            if (isset($data['response']['name'])) {
                $group_names[$group_id] = esc_html($data['response']['name']);
            }
        }
    }

    foreach ($group_ids as $group_id) {
        $group_name = isset($group_names[$group_id]) ? $group_names[$group_id] : "Group ID: $group_id";
        $output .= '<div class="form-check group-checkbox">';
        $output .= '<input class="form-check-input" type="checkbox" name="groups[]" value="' . esc_attr($group_id) . '" id="group_' . esc_attr($group_id) . '">';
        $output .= '<label class="form-check-label" for="group_' . esc_attr($group_id) . '">' . $group_name . '</label>';
        $output .= '</div>';
    }
    $output .= '</div>';

    $output .= '<div class="d-flex justify-content-between">';
    $output .= '<button type="submit" name="add" class="btn btn-primary">Add User</button>';
    $output .= '<button type="submit" name="remove" class="btn btn-danger">Remove User</button>';
    $output .= '</div>';
    $output .= '</form>';

    return $output;
}

function groupme_add_user_to_groups($user_id, $selected_groups, $access_token) {
    $results = [];
    foreach ($selected_groups as $group_id) {
        $url = "https://api.groupme.com/v3/groups/$group_id/members/add?token=$access_token";

        $body = json_encode([
            'members' => [
                ['user_id' => $user_id]
            ]
        ]);

        $response = wp_remote_post($url, [
            'method'    => 'POST',
            'headers'   => [
                'Content-Type' => 'application/json'
            ],
            'body'      => $body,
            'timeout'   => 15 
        ]);

        if (is_wp_error($response)) {
            $results[] = '<div class="alert alert-danger">Error adding user to group ' . $group_id . ': ' . $response->get_error_message() . '</div>';
        } else {
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code >= 200 && $response_code < 300) {
                $results[] = '<div class="alert alert-success">User successfully added to group ' . $group_id . '.</div>';
            } else {
                $body = wp_remote_retrieve_body($response);
                 $data = json_decode($body, true);
                 $error_message = isset($data['meta']['errors'][0]) ? $data['meta']['errors'][0] : 'Unknown error';
                $results[] = '<div class="alert alert-danger">Error adding user to group ' . $group_id . '.  Response code: ' . $response_code . ' Error: ' . $error_message. '</div>';
            }
        }
    }
    return implode("", $results);
}

function groupme_remove_user_from_groups($user_id, $selected_groups, $access_token) {
    $results = [];
    foreach ($selected_groups as $group_id) {

        $group_url = "https://api.groupme.com/v3/groups/$group_id?token=$access_token";
        $group_response = wp_remote_get($group_url);

        if (is_wp_error($group_response)) {
            $results[] = '<div class="alert alert-danger">Error getting group details for ' . $group_id . ': ' . $group_response->get_error_message() . '</div>';
            continue;
        }

        $group_body = wp_remote_retrieve_body($group_response);
        $group_data = json_decode($group_body, true);
        $membership_id = null;

        if (isset($group_data['response']['members'])) {
            foreach ($group_data['response']['members'] as $member) {
                if ($member['user_id'] == $user_id) {
                    $membership_id = $member['id'];
                    break;
                }
            }
        }

        if (!$membership_id) {
            $results[] = '<div class="alert alert-warning">User is not a member of group ' . $group_id . '.</div>';
            continue;
        }

        $url = "https://api.groupme.com/v3/groups/$group_id/members/$membership_id/remove?token=$access_token";

        $response = wp_remote_post($url, [
            'method'    => 'POST',
            'headers'   => [
                'Content-Type' => 'application/json'
            ],
            'timeout'   => 15
        ]);

        if (is_wp_error($response)) {
            $results[] = '<div class="alert alert-danger">Error removing user from group ' . $group_id . ': ' . $response->get_error_message() . '</div>';
        }
         else {
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code >= 200 && $response_code < 300) {
                $results[] = '<div class="alert alert-success">User successfully removed from group ' . $group_id . '.</div>';
            } else {
                $body = wp_remote_retrieve_body($response);
                 $data = json_decode($body, true);
                 $error_message = isset($data['meta']['errors'][0]) ? $data['meta']['errors'][0] : 'Unknown error';
                $results[] = '<div class="alert alert-danger">Error Removing user to group ' . $group_id . '.  Response code: ' . $response_code . ' Error: ' . $error_message. '</div>';
            }
        }

    }
    return implode("", $results);
}

function groupme_get_users_list($access_token, $group_ids) {
    $users = [];

    foreach ($group_ids as $group_id) {
        $url = "https://api.groupme.com/v3/groups/$group_id?token=$access_token";

        $response = wp_remote_get($url);
        if (is_wp_error($response)) {
            error_log('GroupMe API error for group ' . $group_id . ': ' . $response->get_error_message());
            continue; 
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['response']['members'])) {
            error_log('No members found for group ' . $group_id);
            continue;
        }

        foreach ($data['response']['members'] as $member) {
            $nickname = esc_html($member['nickname']);
            $user_id = $member['user_id']; 

            if (!isset($users[$user_id])) {
                 $users[$user_id] = [
                    'nickname' => $nickname,
                    'user_id' => $user_id
                ];
            }
        }
    }

    return $users;
}

?>
