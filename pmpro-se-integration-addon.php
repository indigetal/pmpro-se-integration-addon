<?php
/*
Plugin Name: PMPro SpacesEngine Integration Addon
Plugin URI: https://www.paidmembershipspro.com/wp/pmpro-customizations/
Description: Customizations for integrating Paid Memberships Pro with SpacesEngine
Version: 1.0
Author: Brandon Meyer
Author URI: https://collabanthnetwork.org
*/

//Disable the pmpro redirect to levels page when user tries to register
add_filter("pmpro_login_redirect", "__return_false");

//...And give all newly registered users membership level 1
function my_pmpro_default_registration_level($user_id) {
	pmpro_changeMembershipLevel(1, $user_id);
}
add_action('user_register', 'my_pmpro_default_registration_level');

// Restrict access to certain pages based on level group:
function my_pmpro_custom_redirects() {
    
    // The Level ID's associated with each "Level Group"
    $individual_levels = array('2', '3', '4', '5');
    $spacesengine_levels = array('6', '7', '8', '9');
    
    // The pages to be restricted
    $page_names = array('create-space-page', 'other-restricted-page');

    // First check if PMPro is active
    if ( ! function_exists( 'pmpro_hasMembershipLevel' ) ) {
        return;
    }

    global $pmpro_pages;

    foreach ( $page_names as $page_name ) {
        // Does the page name match?
        if ( strpos( $_SERVER['REQUEST_URI'], '/' . $page_name . '/' ) !== false ) {
            // Redirect members that don't have level ID's associated with level group 1 to the relevant plans page
            if ( $page_name == 'other-restricted-page' && ! pmpro_hasMembershipLevel( $individual_levels ) && ( ! is_page( $pmpro_pages['levels'] ) || ! is_page( $pmpro_pages['checkout'] ) ) ) {
                //The membership plans page listing the plans for Level Group 1
                wp_safe_redirect( '/membership/individual-plans-page/' );
                exit;
            // Redirect members that don't have level ID's associated with level group 2 to the relevant plans page    
            } elseif ( $page_name == 'create-space-page' && ! pmpro_hasMembershipLevel( $spacesengine_levels ) && ( ! is_page( $pmpro_pages['levels'] ) || ! is_page( $pmpro_pages['checkout'] ) ) ) {
                wp_safe_redirect( '/membership/spacesengine-plans-page/' );
                exit;
            }
        }
    }
}
add_action( 'template_redirect', 'my_pmpro_custom_redirects' );

/*
* Adjust the cost of a level at checkout based on the selection of a custom user field for 
* upselling additional options to a plan. Accounts for recurring plans, and adjusts the cost based 
* on if it's a monthly or annual plan (see https://gist.github.com/indigetal/e172e17230ceb1f9516b7c147b6898d4)
*/
function my_pmpro_adjustable_level_cost($level)
{
    // Specify the monthly and annual levels
    $monthly_levels = array(6, 8);
    $annual_levels = array(7, 9);
    
    // Set the field name here
    $field_name = 'upgrade_listing';
    
    // Set the available field options and their monthly and annual fees
    $options = array(
        'promoted' => array('monthly_fee' => 10, 'annual_fee' => 100),
        'featured' => array('monthly_fee' => 15, 'annual_fee' => 150)
    );
    // Stop editing. Enjoy!

    $extra_fee = 0; // Default additional fee

    if (!empty($_REQUEST[$field_name]) && isset($options[$_REQUEST[$field_name]])) {
        $option_values = $options[$_REQUEST[$field_name]];

        if (in_array($level->id, $monthly_levels)) {
            $extra_fee = $option_values['monthly_fee'];
        } elseif (in_array($level->id, $annual_levels)) {
            $extra_fee = $option_values['annual_fee'];
        }

        // Check if there is an extra fee
        if ($extra_fee > 0) {
            // Check if the level has a recurring subscription
            if (pmpro_isLevelRecurring($level)) {
                // Apply the additional fee for recurring plans
                $level->initial_payment = $level->initial_payment + $extra_fee;
                $level->billing_amount = $level->billing_amount + $extra_fee;
            } else {
                // Apply the additional fee for one-time payment plans
                $level->initial_payment = $level->initial_payment + $extra_fee;
            }
        }
    }

    return $level;
}
add_filter("pmpro_checkout_level", "my_pmpro_adjustable_level_cost");

/* 
* Please replace auto-renewal label with jQuery (see comment in 
* https://gist.github.com/indigetal/e172e17230ceb1f9516b7c147b6898d4)
*/
  
// Set the listing as promoted or featured if the user purchases that option:
function set_featured_space_meta( $post_id ) {
    $post_type = get_post_type( $post_id );

    if ( 'wpe_wpspace' === $post_type ) {
        $current_user_id = get_current_user_id();
        $upgrade_listing = get_user_meta( $current_user_id, 'upgrade_listing', true );

        if ( $upgrade_listing === 'promoted' ) {
            update_post_meta( $post_id, 'featured_space', 1 );
        } elseif ( $upgrade_listing === 'featured' ) {
            update_post_meta( $post_id, 'featured_space', 2 );
        }
    }
}
add_action( 'save_post', 'set_featured_space_meta' );

// Set a Member's Space to Draft and update upgrade_listing user meta to none when their SpacesEngine-based Plan is Removed
function pmpro_space_level_removed_actions( $level_id, $user_id ) {
    // Get the user roles
    $usermeta = get_userdata( $user_id );
    $user_roles = $usermeta->roles;

    // Check if the user has any of the specified roles and if their membership level is not in the array (6, 7, 8, 9)
    $allowed_roles = array(
        'subscriber',
        'editor',
        'contributor',
        'author'
    );

    if ( array_intersect( $allowed_roles, $user_roles ) && ! in_array( $level_id, array( 6, 7, 8, 9 ) ) ) {
        update_user_posts_to_draft( $user_id );
        update_user_upgrade_listing( $user_id, 'none' ); // Update upgrade_listing to 'none'
    }
}

function update_user_posts_to_draft( $user_id ) {
    // Get the user's posts
    $args = array(
        'author'      => $user_id,
        'post_type'   => 'wpe_wpspace',
    );
    $user_posts = get_posts( $args );
    foreach ( $user_posts as $user_post ) {
        $post = array( 'ID' => $user_post->ID, 'post_status' => 'draft' );
        wp_update_post( $post );
    }
}

function update_user_upgrade_listing( $user_id, $value ) {
    update_user_meta( $user_id, 'upgrade_listing', $value ); // Update upgrade_listing user meta
}

add_action( 'pmpro_after_change_membership_level', 'pmpro_space_level_removed_actions', 10, 2 );

/**
 * Add a setting to the edit level settings to show or hide a membership level on the level select page. 
 * See https://www.paidmembershipspro.com/memberships-levels-page-order-hide-display-skip-mega-post/#h-option-2-add-a-setting-to-hide-levels-from-display-on-the-memberships-edit-level-admin
 */
 
//Save the pmpro_show_level_ID field
function pmpro_hide_level_from_levels_page_save( $level_id ) {
	if ( $level_id <= 0 ) {
		return;
	}
	$limit = $_REQUEST['pmpro_show_level'];
	update_option( 'pmpro_show_level_'.$level_id, $limit );
}
add_action( 'pmpro_save_membership_level','pmpro_hide_level_from_levels_page_save' );
 
//Display the setting for the pmpro_show_level_ID field on the Edit Membership Level page
function pmpro_hide_level_from_levels_page_settings() {
	?>
	<h3 class='topborder'><?php esc_html_e( 'Membership Level Visibility', 'pmpro' ); ?></h3>
	<table class='form-table'>
		<tbody>
			<tr>
				<th scope='row' valign='top'><label for='pmpro_show_level'><?php esc_html_e( 'Show Level', 'pmpro' );?>:</label></th>
				<td>
					<?php		
						if ( isset( $_REQUEST['edit'] ) ) {
							$edit = $_REQUEST['edit'];
							$pmpro_show_level = get_option( 'pmpro_show_level_' . $edit );
							if ( $pmpro_show_level === false ) {
								$pmpro_show_level = 1;
							}
						} else {
							$limit = '';
						}
					?>
					<select id='pmpro_show_level' name='pmpro_show_level'>
						<option value='1' <?php if ( $pmpro_show_level == 1 ) { ?>selected='selected'<?php } ?>><?php esc_html_e( 'Yes, show this level in the [pmpro_levels] display.', 'pmpro' );?></option>
 
						<option value='0' <?php if ( ! $pmpro_show_level ) { ?>selected='selected'<?php } ?>><?php _e( 'No, hide this level in the [pmpro_levels] display.', 'pmpro' );?></option>
					</select>
				</td>
			</tr>
		</tbody>
	</table>
	<?php 
}
add_action( 'pmpro_membership_level_after_other_settings', 'pmpro_hide_level_from_levels_page_settings' );

?>
