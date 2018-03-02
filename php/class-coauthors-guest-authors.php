<?php
/**
 * Co-Authors Guest Authors
 *
 * Key idea: Create guest authors to assign as bylines on a post without having
 * to give them access to the dashboard through a WP_User account
 */

class CoAuthors_Guest_Authors
{

	var $post_type = 'guest-author';
	var $parent_page = 'users.php';
	var $list_guest_authors_cap = 'list_users';

	/**
	 * Initialize our Guest Authors class and establish common hooks
	 */
	function __construct() {
		global $coauthors_plus;

		// Add the guest author management menu
		add_action( 'admin_menu', array( $this, 'action_admin_menu' ) );

		// WP List Table for breaking out our Guest Authors
		require_once( dirname( __FILE__ ) . '/class-coauthors-wp-list-table.php' );

		// Any CSS or JS
		add_action( 'admin_enqueue_scripts', array( $this, 'action_admin_enqueue_scripts' ) );

		// Handle actions to create or delete guest author accounts
		add_action( 'admin_init', array( $this, 'handle_create_guest_author_action' ) );

		// Redirect if the user is mapped to a guest author
		add_action( 'parse_request', array( $this, 'action_parse_request' ) );

		// Filter author links and such
		add_filter( 'author_link', array( $this, 'filter_author_link' ), 10, 3 );

		// Add metaboxes for our guest author management interface
		add_action( 'add_meta_boxes', array( $this, 'action_add_meta_boxes' ), 10, 2 );
		add_action( 'wp_insert_post_data', array( $this, 'manage_guest_author_filter_post_data' ), 10, 2 );
		add_action( 'save_post', array( $this, 'manage_guest_author_save_meta_fields' ), 10, 2 );

		// Allow admins to create or edit guest author profiles from the Manage Users listing
		add_filter( 'user_row_actions', array( $this, 'filter_user_row_actions' ), 10, 2 );

		// Add support for featured thumbnails that we can use for guest author avatars
		add_action( 'after_setup_theme', array( $this, 'action_after_setup_theme' ) );
		add_filter( 'get_avatar', array( $this, 'filter_get_avatar' ),10 ,5 );

		// Allow users to change where this is placed in the WordPress admin
		$this->parent_page = apply_filters( 'coauthors_guest_author_parent_page', $this->parent_page );

		// Allow users to change the required cap for modifying guest authors
		$this->list_guest_authors_cap = apply_filters( 'coauthors_guest_author_manage_cap', $this->list_guest_authors_cap );

		// Set up default labels, but allow themes to modify
		$this->labels = apply_filters( 'coauthors_guest_author_labels', array(
			'singular' => __( 'Guest Author', 'co-authors-plus' ),
			'plural' => __( 'Guest Authors', 'co-authors-plus' ),
			'all_items' => __( 'All Guest Authors', 'co-authors-plus' ),
			'add_new_item' => __( 'Add New Guest Author', 'co-authors-plus' ),
			'edit_item' => __( 'Edit Guest Author', 'co-authors-plus' ),
			'new_item' => __( 'New Guest Author', 'co-authors-plus' ),
			'view_item' => __( 'View Guest Author', 'co-authors-plus' ),
			'search_items' => __( 'Search Guest Authors', 'co-authors-plus' ),
			'not_found' => __( 'No guest authors found', 'co-authors-plus' ),
			'not_found_in_trash' => __( 'No guest authors found in Trash', 'co-authors-plus' ),
			'update_item' => __( 'Update Guest Author', 'co-authors-plus' ),
			'metabox_about' => __( 'About the guest author', 'co-authors-plus' ),
		) );

		// Register a post type to store our authors that aren't WP.com users
		$args = array(
				'label' => $this->labels['singular'],
				'labels' => array(
						'name' => $this->labels['plural'],
						'singular_name' => $this->labels['singular'],
						'add_new' => _x( 'Add New', 'co-authors-plus' ),
						'all_items' => $this->labels['all_items'],
						'add_new_item' => $this->labels['add_new_item'],
						'edit_item' => $this->labels['edit_item'],
						'new_item' => $this->labels['new_item'],
						'view_item' => $this->labels['view_item'],
						'search_items' => $this->labels['search_items'],
						'not_found' => $this->labels['not_found'],
						'not_found_in_trash' => $this->labels['not_found_in_trash'],
					),
				'public' => true,
				'publicly_queryable' => false,
				'exclude_from_search' => true,
				'show_in_menu' => false,
				'supports' => array(
						'thumbnail',
					),
				'taxonomies' => array(
						$coauthors_plus->coauthor_taxonomy,
					),
				'rewrite' => false,
				'query_var' => false,
			);
		register_post_type( $this->post_type, $args );

		// Hacky way to remove the title and the editor
		remove_post_type_support( $this->post_type, 'title' );
		remove_post_type_support( $this->post_type, 'editor' );

	}

	/**
	 * Handle the admin action to create a guest author based
	 * on an existing WordPress user
	 *
	 * @since 2.7
	 */
	function handle_create_guest_author_action() {

		if ( !isset( $_GET['action'], $_GET['nonce'], $_GET['user_id'] ) || $_GET['action'] != 'cap-create-guest-author' )
			return;

		if ( !wp_verify_nonce( $_GET['nonce'], 'create-guest-author' ) )
			wp_die( __( "Doin' something fishy, huh?", 'co-authors-plus' ) );

		// @todo permissions check

		// @todo Check to see if the user already has a guest profile
		$user_id = intval( $_GET['user_id'] );

		// Create the guest author
		$post_id = $this->create_guest_author_from_user_id( $user_id );
		if ( is_wp_error( $post_id ) )
			wp_die( $post_id->get_error_message() );

		// Redirect to the edit Guest Author screen
		$edit_link = get_edit_post_link( $post_id, 'redirect' );
		$redirect_to = add_query_arg( 'message', 'guest-author-created', $edit_link );
		wp_safe_redirect( $redirect_to );
		exit;

	}

	/**
	 * Some redirection we need to do for linked accounts
	 *
	 * @todo support author ID query vars
	 */
	function action_parse_request( $query ) {

		if ( !isset( $query->query_vars['author_name'] ) )
			return $query;

		$coauthor = $this->get_guest_author_by( 'linked_account', sanitize_title( $query->query_vars['author_name'] ) );
		if ( is_object( $coauthor ) ) {
			global $wp_rewrite;
			$link = $wp_rewrite->get_author_permastruct();

			if ( empty($link) ) {
				$file = home_url( '/' );
				$link = $file . '?author_name=' . $coauthor->user_login;
			} else {
				$link = str_replace('%author%', $coauthor->user_login, $link);
				$link = home_url( user_trailingslashit( $link ) );
			}
			wp_safe_redirect( $link );
			exit;
		}

		return $query;
	}

	/**
	 * Add the admin menus for seeing all co-authors
	 */
	function action_admin_menu() {

		add_submenu_page( $this->parent_page, $this->labels['plural'], $this->labels['plural'], $this->list_guest_authors_cap, 'view-guest-authors', array( $this, 'view_guest_authors_list' ) );

	}

	/**
	 * Enqueue any scripts or styles used for Guest Authors
	 */
	function action_admin_enqueue_scripts() {
		global $pagenow;
		// Enqueue our guest author CSS on the related pages
		if ( $this->parent_page == $pagenow && isset( $_GET['page'] ) && $_GET['page'] == 'view-guest-authors' ) {
			wp_enqueue_style( 'guest-authors-css', COAUTHORS_PLUS_URL . 'css/guest-authors.css', false, COAUTHORS_PLUS_VERSION );
		}
	}

	/**
	 * Register the metaboxes used for Guest Authors
	 */
	function action_add_meta_boxes() {
		global $coauthors_plus;

		$post_type = $coauthors_plus->get_current_post_type();

		if ( $post_type == $this->post_type ) {
			// Remove the submitpost metabox because we have our own
			remove_meta_box( 'submitdiv', $post_type, 'side' );
			remove_meta_box( 'slugdiv', $post_type, 'normal' );
			add_meta_box( 'coauthors-manage-guest-author-save', __( 'Save', 'co-authors-plus'), array( $this, 'metabox_manage_guest_author_save' ), $post_type, 'side', 'default' );
			add_meta_box( 'coauthors-manage-guest-author-slug', __( 'Unique Slug', 'co-authors-plus'), array( $this, 'metabox_manage_guest_author_slug' ), $post_type, 'side', 'default' );
			// Our metaboxes with co-author details
			add_meta_box( 'coauthors-manage-guest-author-name', __( 'Name', 'co-authors-plus'), array( $this, 'metabox_manage_guest_author_name' ), $post_type, 'normal', 'default' );
			add_meta_box( 'coauthors-manage-guest-author-contact-info', __( 'Contact Info', 'co-authors-plus'), array( $this, 'metabox_manage_guest_author_contact_info' ), $post_type, 'normal', 'default' );
			add_meta_box( 'coauthors-manage-guest-author-bio', $this->labels['metabox_about'], array( $this, 'metabox_manage_guest_author_bio' ), $post_type, 'normal', 'default' );
		}
	}

	/**
	 *
	 */
	function view_guest_authors_list() {

		echo '<div class="wrap">';
		echo '<div class="icon32" id="icon-users"><br/></div>';
		echo '<h2>' . $this->labels['plural'];
		// @todo caps check for creating a new user
		$add_new_link = admin_url( "post-new.php?post_type=$this->post_type" );
		echo '<a href="' . $add_new_link . '" class="add-new-h2">' . esc_html( __( 'Add New', 'co-authors-plus' ) ) . '</a>';
		echo '</h2>';
		$cap_list_table = new CoAuthors_WP_List_Table();
		$cap_list_table->prepare_items();
		echo '<form id="guest-authors-filter" action="" method="GET">';
		echo '<input type="hidden" name="page" value="view-guest-authors" />';
		$cap_list_table->display();
		echo '</form>';
		echo '</div>';

	}

	/**
	 * Metabox for saving or updating a Guest Author
	 */
	function metabox_manage_guest_author_save() {
		global $post, $coauthors_plus;

		if ( $post->post_status == 'pending' )
			$button_text = $this->labels['update_item'];
		else
			$button_text = $this->labels['add_new_item'];
		submit_button( $button_text, 'primary', 'publish', false );

		// Secure all of our requests
		wp_nonce_field( 'guest-author-nonce', 'guest-author-nonce' );

	}

	/**
	 * Metabox for editing this guest author's slug
	 */
	function metabox_manage_guest_author_slug() {
		global $post;

		$pm_key = $this->get_post_meta_key( 'user_login' );
		$existing_slug = get_post_meta( $post->ID, $pm_key, true );

		echo '<input type="text" disabled="disabled" name="' . esc_attr( $pm_key ) . '" value="' . esc_attr( urldecode( $existing_slug ) ) . '" />';

		// Taken from grist_authors.
		$linked_account_key = $this->get_post_meta_key( 'linked_account' );
		$linked_account = get_user_by( 'login', get_post_meta( $post->ID, $linked_account_key, true ) );
		if ( is_object( $linked_account ) )
			$linked_account_id = $linked_account->ID;
		else
			$linked_account_id = -1;

		echo '<p><label>' . __( 'WordPress User Mapping', 'co-authors-plus' ) . '</label> ';
		wp_dropdown_users( array(
			'show_option_none' => __( '-- Not mapped --', 'co-authors-plus' ),
			'name' => esc_attr( $this->get_post_meta_key( 'linked_account' ) ),
			// If we're adding an author or if there is no post author (0), then use -1 (which is show_option_none).
			// We then take -1 on save and convert it back to 0. (#blamenacin)
			'selected' => $linked_account_id,
		) );
		echo '</p>';
	}

	/**
	 * Metabox to display all of the pertient names for a Guest Author without a user account
	 */
	function metabox_manage_guest_author_name() {
		global $post;

		$fields = $this->get_guest_author_fields( 'name' );
		echo '<table class="form-table"><tbody>';
		foreach( $fields as $field ) {
			$pm_key = $this->get_post_meta_key( $field['key'] );
			$value = get_post_meta( $post->ID, $pm_key, true );
			echo '<tr><th>';
			echo '<label for="' . esc_attr( $pm_key ) . '">' . $field['label'] . '</label>';
			echo '</th><td>';
			echo '<input type="text" name="' . esc_attr( $pm_key ) . '" value="' . esc_attr( $value ) . '" class="regular-text" />';
			echo '</td></tr>';
		}
		echo '</tbody></table>';

	}

	/**
	 * Metabox to display all of the pertient contact details for a Guest Author without a user account
	 */
	function metabox_manage_guest_author_contact_info() {
		global $post;

		$fields = $this->get_guest_author_fields( 'contact-info' );
		echo '<table class="form-table"><tbody>';
		foreach( $fields as $field ) {
			$pm_key = $this->get_post_meta_key( $field['key'] );
			$value = get_post_meta( $post->ID, $pm_key, true );
			echo '<tr><th>';
			echo '<label for="' . esc_attr( $pm_key ) . '">' . $field['label'] . '</label>';
			echo '</th><td>';
			echo '<input type="text" name="' . esc_attr( $pm_key ) . '" value="' . esc_attr( $value ) . '" class="regular-text" />';
			echo '</td></tr>';
		}
		echo '</tbody></table>';

	}

	/**
	 * Metabox to edit the bio and other biographical details of the Guest Author
	 */
	function metabox_manage_guest_author_bio() {
		global $post;

		$fields = $this->get_guest_author_fields( 'about' );
		echo '<table class="form-table"><tbody>';
		foreach( $fields as $field ) {
			$pm_key = $this->get_post_meta_key( $field['key'] );
			$value = get_post_meta( $post->ID, $pm_key, true );
			echo '<tr><th>';
			echo '<label for="' . esc_attr( $pm_key ) . '">' . $field['label'] . '</label>';
			echo '</th><td>';
			echo '<textarea style="width:300px;margin-bottom:6px;" name="' . esc_attr( $pm_key ) . '">' . esc_textarea( $value ) . '</textarea>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';

	}

	/**
	 * When a guest author is created or updated, we need to properly create
	 * the post_name based on some data provided by the user
	 */
	function manage_guest_author_filter_post_data( $post_data, $original_args ) {

		if ( $post_data['post_type'] != $this->post_type )
			return $post_data;

		// @todo caps check
		if ( !isset( $_POST['guest-author-nonce'] ) || !wp_verify_nonce( $_POST['guest-author-nonce'], 'guest-author-nonce' ) )
			return $post_data;

		global $post;

		$post_data['post_title'] = sanitize_text_field( $_POST['cap-display_name'] );
		$slug = get_post_meta( $post->ID, $this->get_post_meta_key( 'user_login' ), true );
		if ( !$slug )
			$slug = sanitize_title( $_POST['cap-display_name'] );
		$post_data['post_name'] = $this->get_post_meta_key( $slug );
		return $post_data;
	}

	/**
	 * Save the various meta fields associated with our guest author model
	 */
	function manage_guest_author_save_meta_fields( $post_id, $post ) {
		global $coauthors_plus;

		if ( $post->post_type != $this->post_type )
			return;

		// @todo caps check
		if ( !isset( $_POST['guest-author-nonce'] ) || !wp_verify_nonce( $_POST['guest-author-nonce'], 'guest-author-nonce' ) )
			return;

		// Save our data to post meta
		$author_fields = $this->get_guest_author_fields();
		foreach( $author_fields as $author_field ) {

			$key = $this->get_post_meta_key( $author_field['key'] );
			// 'user_login' should only be saved on post update if it doesn't exist
			if ( 'user_login' == $author_field['key'] && !get_post_meta( $post_id, $key, true ) ) {
				$display_name_key = $this->get_post_meta_key( 'display_name' );
				$temp_slug = sanitize_title( $_POST[$display_name_key] );
				update_post_meta( $post_id, $key, $temp_slug );
				continue;
			}
			if ( 'linked_account' == $author_field['key'] ) {
				$linked_account_key = $this->get_post_meta_key( 'linked_account' );
				$user_id = intval( $_POST[$linked_account_key] );
				$user = get_user_by( 'id', $user_id );
				if ( $user_id > 0 && is_object( $user ) )
					$user_login = $user->user_login;
				else
					$user_login = '';
				update_post_meta( $post_id, $key, $user_login );
				continue;
			}
			if ( !isset( $_POST[$key] ) )
				continue;
			if ( isset( $author_field['sanitize_function'] ) && function_exists( $author_field['sanitize_function'] ) )
				$value = $author_field['sanitize_function']( $_POST[$key] );
			else
				$value = sanitize_text_field( $_POST[$key] );
			update_post_meta( $post_id, $key, $value );
		}

		// Ensure there's a proper 'author' term for this coauthor
		$author_slug = get_post_meta( $post->ID, $this->get_post_meta_key( 'user_login' ), true );
		if ( empty( $author_slug ) && isset( $temp_slug ) )
			$author_slug = $temp_slug;
		if( !term_exists( $author_slug, $coauthors_plus->coauthor_taxonomy ) ) {
			$args = array( 'slug' => $author_slug );
			wp_insert_term( $author_slug, $coauthors_plus->coauthor_taxonomy, $args );
		}
		// Add the author as a post term
		wp_set_post_terms( $post_id, array( $author_slug ), $coauthors_plus->coauthor_taxonomy, false );

		// Update the taxonomy term to include details about the user for searching
		$search_values = array();
		$guest_author = $this->get_guest_author_by( 'id', $post_id );
		$term = get_term_by( 'slug', $guest_author->user_login, $coauthors_plus->coauthor_taxonomy );
		foreach( $coauthors_plus->ajax_search_fields as $search_field ) {
			$search_values[] = $guest_author->$search_field;
		}
		$args = array(
				'description' => implode( ' ', $search_values ),
			);
		wp_update_term( $term->term_id, $coauthors_plus->coauthor_taxonomy, $args );
	}

	/**
	 * Return a simulated WP_User object based on the post ID
	 * of a guest author
	 *
	 */
	function get_guest_author_by( $key, $value ) {
		global $wpdb;

		switch( $key ) {
			case 'id':
				$query = $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE ID=%d", $value );
				$post_id = $wpdb->get_var( $query );
				if ( empty( $post_id ) )
					return false;
				break;
			case 'post_name':
				// @todo look for a more performant way of gathering this data
				$value = $this->get_post_meta_key( $value );
				$query = $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_name=%s AND post_type = %s", $value, $this->post_type );
				$post_id = $wpdb->get_var( $query );
				if ( empty( $post_id ) )
					return false;
				break;
			case 'login':
			case 'user_login':
			case 'linked_account':
				if ( 'login' == $key )
					$key = 'user_login';
				$query = $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key=%s AND meta_value=%s;", $this->get_post_meta_key( $key ), $value );
				$post_id = $wpdb->get_var( $query );
				if ( empty( $post_id ) ) {
					if ( 'user_login' == $key )
						return $this->get_guest_author_by( 'post_name', $value ); // fallback to post_name in case the guest author isn't a linked account
					return false;
				}
				break;
			default:
				$post_id = false;
				break;
		}

		if ( !$post_id )
			return false;

		$guest_author = array(
			'ID' => $post_id,
		);

		// Load the guest author fields
		$fields = $this->get_guest_author_fields();
		foreach( $fields as $field ) {
			$key = $field['key'];
			$pm_key = $this->get_post_meta_key( $field['key'] );
			$guest_author[$key] = get_post_meta( $post_id, $pm_key, true );
		}
		// Support for non-Latin characters. They're stored as urlencoded slugs
		$guest_author['user_login'] = urldecode( $guest_author['user_login'] );

		// Hack to model the WP_User object
		$guest_author['user_nicename'] = $guest_author['user_login'];
		$guest_author['type'] = 'guest-author';
		return (object)$guest_author;
	}

	/**
	 * Get all of the meta fields that can be associated with a guest author
	 */
	function get_guest_author_fields( $groups = 'all' ) {

		$groups = (array)$groups;
		$global_fields = array(
				// Hidden (included in object, no UI elements)
				array(
						'key'      => 'ID',
						'label'    => __( 'ID', 'co-authors-plus' ),
						'group'    => 'hidden',
					),
				// Name
				array(
						'key'      => 'display_name',
						'label'    => __( 'Display Name', 'co-authors-plus'),
						'group'    => 'name',
					),
				array(
						'key'      => 'first_name',
						'label'    => __( 'First Name', 'co-authors-plus'),
						'group'    => 'name',
					),
				array(
						'key'      => 'last_name',
						'label'    => __( 'Last Name', 'co-authors-plus'),
						'group'    => 'name',
					),
				array(
						'key'      => 'user_login',
						'label'    => __( 'Slug', 'co-authors-plus'),
						'group'    => 'slug',
					),
				// Contact info
				array(
						'key'      => 'user_email',
						'label'    => __( 'E-mail', 'co-authors-plus' ),
						'group'    => 'contact-info',
					),
				array(
						'key'      => 'linked_account',
						'label'    => __( 'Linked Account', 'co-authors-plus' ),
						'group'    => 'slug',
					),
				array(
						'key'      => 'website',
						'label'    => __( 'Website', 'co-authors-plus' ),
						'group'    => 'contact-info',
					),
				array(
						'key'      => 'aim',
						'label'    => __( 'AIM', 'co-authors-plus' ),
						'group'    => 'contact-info',
					),
				array(
						'key'      => 'yahooim',
						'label'    => __( 'Yahoo IM', 'co-authors-plus' ),
						'group'    => 'contact-info',
					),
				array(
						'key'      => 'jabber',
						'label'    => __( 'Jabber / Google Talk', 'co-authors-plus' ),
						'group'    => 'contact-info',
					),
				array(
						'key'      => 'description',
						'label'    => __( 'Biographical Info', 'co-authors-plus' ),
						'group'    => 'about',
						'sanitize_function' => 'wp_filter_post_kses',
					),
			);
		$fields_to_return = array();
		foreach( $global_fields as $single_field ) {
			if ( in_array( $single_field['group'], $groups ) || $groups[0] == 'all' && $single_field['group'] != 'hidden' )
				$fields_to_return[] = $single_field;
		}

		return apply_filters( 'coauthors_guest_author_fields', $fields_to_return, $groups );

	}

	/**
	 * Gets a postmeta key by prefixing it with 'cap-'
	 * if not yet prefixed
	 *
	 * @since 0.7
	 */
	function get_post_meta_key( $key ) {

		if ( 0 !== stripos( $key, 'cap-' ) )
			$key = 'cap-' . $key;

		return $key;
	}

	/**
	 * Create a guest author from an existing WordPress user
	 */
	function create_guest_author_from_user_id( $user_id ) {
		global $coauthors_plus;

		$user = get_user_by( 'id', $user_id );
		if ( !$user )
			return new WP_Error( 'invalid-user', __( 'No user exists with that ID', 'co-authors-plus' ) );

		$post_name = $this->get_post_meta_key( $user->user_login );
		if ( $this->get_guest_author_by( 'post_name', $post_name ) )
			return new WP_Error( 'profile-exists', __( "Profile already exists for {$user->user_login}", 'co-authors-plus' ) );

		// Create the user as a new guest
		$new_post = array(
				'post_title' => $user->display_name,
				'post_name' => $post_name,
				'post_type' => $this->post_type,
			);
		$post_id = wp_insert_post( $new_post, true );
		if ( is_wp_error( $post_id ) )
			return $post_id;

		$fields = $this->get_guest_author_fields();
		foreach( $fields as $field ) {
			$key = $field['key'];
			$pm_key = $this->get_post_meta_key( $field['key'] );
			update_post_meta( $post_id, $pm_key, $user->$key );
		}

		// Ensure there's an 'author' term for this user/guest author
		if( !term_exists( $user->user_login, $coauthors_plus->coauthor_taxonomy ) ) {
			$args = array(
				'slug' => $user->user_login
			);
			wp_insert_term( $user->user_login, $coauthors_plus->coauthor_taxonomy, $args );
		}
		// Add the author as a post term
		wp_set_post_terms( $post_id, array( $user->user_login ), $coauthors_plus->coauthor_taxonomy, false );

		// Update the taxonomy term to include details about the user for searching
		$search_values = array();
		$guest_author = $this->get_guest_author_by( 'id', $post_id );
		$term = get_term_by( 'slug', $user->user_login, $coauthors_plus->coauthor_taxonomy );
		foreach( $coauthors_plus->ajax_search_fields as $search_field ) {
			$search_values[] = $guest_author->$search_field;
		}
		$args = array(
				'description' => implode( ' ', $search_values ),
			);
		wp_update_term( $term->term_id, $coauthors_plus->coauthor_taxonomy, $args );

		return $post_id;
	}

	/**
	 * On the User Management view, add action links to create or edit
	 * guest author profiles
	 *
	 * @todo The text of these links is definitely up in the air
	 *
	 * @param array $actions The existing actions to perform on a user
	 * @param object $user_object A WP_User object
	 * @return array $actions Modified actions
	 */
	function filter_user_row_actions( $actions, $user_object ) {

		$new_actions = array();
		if ( $guest_author = $this->get_guest_author_by( 'linked_account', $user_object->user_login ) ) {
			$edit_guest_author_link = get_edit_post_link( $guest_author->ID );
			$new_actions['edit-guest-author'] = '<a href="' . esc_url( $edit_guest_author_link ) . '">' . __( 'Edit CA+ Profile', 'co-authors-plus' ) . '</a>';
		} else {
			$query_args = array(
					'action' => 'cap-create-guest-author',
					'user_id' => $user_object->ID,
					'nonce' => wp_create_nonce( 'create-guest-author' ),
				);
			$create_guest_author_link = add_query_arg( $query_args, admin_url( $this->parent_page ) );
			$new_actions['create-guest-author'] = '<a href="' . esc_url( $create_guest_author_link ) . '">' . __( 'Create CA+ Profile', 'co-authors-plus' ) . '</a>';
		}

		return $new_actions + $actions;
	}

		/**
	 * Anything to do after the theme has been set up
	 */
	function action_after_setup_theme() {
		add_theme_support( 'post-thumbnails', array( $this->post_type ) );

		// @todo identify a few of the common image sizes used by get_avatar()
		add_image_size( 'guest-author-32', 32, 32, true );
	}

	/**
	 * Filter 'get_avatar' to replace with our own avatar if one exists
	 *
	 * @todo support for multiple avatar sizes
	 */
	function filter_get_avatar( $avatar, $id_or_email, $size, $default ) {

		if ( is_object( $id_or_email ) || !is_email( $id_or_email ) )
			return $avatar;

		// @todo we need a better way of looking to see whether this email exists in our system to override
		// it probably should be cached too. maybe produce a URL that's a HTTP request against the site, and then serve
		// the image from that?
		global $wpdb;
		$query = $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='cap-user_email' AND meta_value=%s", $id_or_email );
		$results = $wpdb->get_results( $query );
		if ( empty( $results ) )
			return $avatar;

		$post_id = $results[0]->post_id;
		if ( !has_post_thumbnail( $post_id ) )
			return $avatar;

		$args = array(
				'class' => 'avatar avatar-32 photo',
			);
		$avatar = get_the_post_thumbnail( $post_id, 'guest-author-32', $args );

		return $avatar;
	}

	/**
	 * Filter the URL used in functions like the_author_posts_link()
	 *
	 * @since 2.7
	 */
	function filter_author_link( $link, $author_id, $author_nicename ) {

		if ( $author_id ) {
			return $link;
		}
		// If we're using this at the top of the loop on author.php,
		// our queried object should be set correctly
		if ( !$author_nicename && is_author() && get_queried_object() )
			$author_nicename = get_queried_object()->user_nicename;

		global $wp_rewrite;
		$link = $wp_rewrite->get_author_permastruct();

		if ( empty($link) ) {
			$file = home_url( '/' );
			$link = $file . '?author_name=' . $author_nicename;
		} else {
			$link = str_replace('%author%', $author_nicename, $link);
			$link = home_url( user_trailingslashit( $link ) );
		}
		return $link;

	}

}