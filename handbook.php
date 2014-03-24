<?php
/**
 * Plugin Name: Handbook
 * Description: Features for a handbook, complete with glossary and table of contents
 * Author: Nacin
 */

require_once dirname( __FILE__ ) . '/inc/glossary.php';
require_once dirname( __FILE__ ) . '/inc/table-of-contents.php';
require_once dirname( __FILE__ ) . '/inc/email-post-changes.php';

WPorg_Handbook_Glossary::init();
new WPorg_Handbook_TOC;

class WPorg_Handbook {

	static function caps() {
		return array(
			'edit_handbook_pages', 'edit_others_handbook_pages',
			'edit_published_handbook_pages',
		);
	}

	static function editor_caps() {
		return array(
			'publish_handbook_pages',
			'delete_handbook_pages', 'delete_others_handbook_pages',
			'delete_published_handbook_pages', 'delete_private_handbook_pages',
			'edit_private_handbook_pages', 'read_private_handbook_pages',
		);
	}

	function __construct() {
		add_filter( 'user_has_cap', array( $this, 'grant_handbook_caps' ) );
		add_filter( 'init', array( $this, 'register_post_type' ) );
		add_filter( 'init', array( $this, 'register_taxonomy' ) );
		add_action( 'init', array( $this, 'session_initialize' ) );
		add_action( 'admin_page_access_denied', array( $this, 'admin_page_access_denied' ) );
		add_action( 'admin_menu', array( $this, 'taxonomy_meta_box' ) );
		add_filter( 'post_type_link', array( $this, 'post_type_link' ), 10, 2 );
		add_filter( 'pre_get_posts', array( $this, 'pre_get_posts' ) );
		add_action( 'widgets_init', array( $this, 'handbook_sidebar' ), 11 ); // After P2
		add_action( 'wporg_email_changes_for_post_types', array( $this, 'wporg_email_changes_for_post_types' ) );
		add_filter( 'parse_query' , array( $this, 'filter_pages' ) );
		add_action( 'restrict_manage_posts', array( $this, 'restrict_handbook_pages_by_type' ) );
	}

	function session_initialize() {

	    if ( ! session_id() ) {
	        session_start();
	    }
	}

	function grant_handbook_caps( $caps ) {
		if ( ! is_user_logged_in() )
			return $caps;
		foreach ( self::caps() as $cap ) {
			$caps[ $cap ] = true;
		}
		if ( ! empty( $caps['edit_pages'] ) ) {
			foreach ( self::editor_caps() as $cap ) {
				$caps[ $cap ] = true;
			}
		}
		return $caps;
	}

	function register_post_type() {
		register_post_type( 'handbook', array(
			'labels' => array(
				'name' => 'Handbook Pages',
				'singular_name' => 'Handbook Page',
				'menu_name' => 'Handbook Pages',
			),
			'public' => true,
			'show_ui' => true,
			'capability_type' => 'handbook_page',
			'map_meta_cap' => true,
			'has_archive' => true,
			'hierarchical' => true,
			'menu_position' => 11,
			'rewrite' => true,
			'delete_with_user' => false,
			'supports' => array( 'title', 'editor', 'author', 'thumbnail', 'page-attributes', 'custom-fields', 'comments', 'revisions' ),
		) );
	}

	function register_taxonomy() {
		register_taxonomy( 'handbook_type', 'handbook', array(
			'label'                 => __( 'Handbook Type', 'wporg' ),
			'public'                => true,
			'rewrite'               => array( 'slug' => 'handbooks' ),
			'sort'                  => false,
		) );
	}

	function taxonomy_meta_box() {
	    if ( ! is_admin() )
	        return;
		remove_meta_box( 'tagsdiv-handbook_type','handbook','side') ;
    	add_meta_box( 'handbook_type_box_ID', __( 'Handbook Type' ), array( $this, 'add_taxonomy_dropdown' ), 'handbook', 'side', 'core' );
	}

	// This function gets called in edit-form-advanced.php
	function add_taxonomy_dropdown( $post ) {

	    $output = '<input type="hidden" name="taxonomy_noncename" id="taxonomy_noncename" value="' . wp_create_nonce( 'taxonomy_handbook_type' ) . '" />';

	    // Get all handbook_type taxonomy terms
	    $handbook_types = get_terms( 'handbook_type', 'hide_empty=0' );
		$output .= "<select name='post_handbook_type' id='post_handbook_type'>";
		$names = wp_get_object_terms( $post->ID, 'handbook_type' );
		    foreach ( $handbook_types as $handbook_type ) {
				$selected = ( !is_wp_error( $names ) && !empty( $names ) && !strcmp( $handbook_type->slug, $names[0]->slug ) ) ? ' selected' : '';
		    	$output .= sprintf( "<option class='handbook_type-option' value='%s' %s>%s</option>\n", $handbook_type->slug, $selected, $handbook_type->name );
	   		}
		$output .= "</select>";
		echo $output;
	}

	function admin_page_access_denied() {
		if ( ! current_user_can( 'read' ) ) {
			wp_redirect( admin_url( 'edit.php?post_type=handbook' ) );
			exit;
		}
	}

	function filter_pages( $query ) {
	    global $pagenow;
	    global $typenow;
	    if ( $pagenow =='edit.php' && $typenow = 'handbook' ) {
	    	$qv = &$query->query_vars;
		    if ( isset( $qv['handbook_type'] ) && is_numeric( $qv['handbook_type'] ) ) {
		        $term = get_term_by( 'id', $qv['handbook_type'], 'handbook_type' );
		        $qv['handbook_type'] = $term->slug;
        		$_SESSION['handbook_type'] = $term->term_id;
		    } else {
		    	if ( isset( $_SESSION['handbook_type'] ) ) {
		        	$term = get_term_by( 'id', $_SESSION['handbook_type'], 'handbook_type' );
					$qv['handbook_type'] = $term->slug;
    			} else {
			    	$terms = get_terms( 'handbook_type', 'hide_empty=0&fields=id=>slug' );
			    	$qv['handbook_type'] = array_shift ( $terms );
    			}
		    }
	    }
	}

	function restrict_handbook_pages_by_type() {
	    global $typenow;
	    global $wp_query;
	    $selected = ( isset( $_SESSION['handbook_type'] ) ) ? $_SESSION['handbook_type'] : $wp_query->query['handbook_type'];
	    if ( $typenow =='handbook' ) {
	        wp_dropdown_categories( array(
	            'taxonomy'        => 'handbook_type',
	            'name'            => 'handbook_type',
	            'orderby'         => 'name',
	            'selected'        => $selected,
	            'hierarchical'    => true,
	            'depth'           => 3,
	            'hide_empty'      => false, // show all listings
	        ) );
    	}
    }

	function post_type_link( $link, $post ) {
		if ( $post->post_type === 'handbook' && $post->post_name === 'handbook' )
			return get_post_type_archive_link( 'handbook' );
		return $link;
	}

	function pre_get_posts( $query ) {
		if ( $query->is_main_query() && ! $query->is_admin && $query->is_post_type_archive( 'handbook' ) ) {
			$query->set( 'handbook', 'handbook' );
		}
	}

	function handbook_sidebar() {
		#if ( ! class_exists( 'P2' ) )
		#	return;

		register_sidebar( array( 'id' => 'handbook', 'name' => 'Handbook', 'description' => 'Used on handbook pages' ) );

		require_once dirname( __FILE__ ) . '/inc/widgets.php';
		register_widget( 'WPorg_Handbook_Pages_Widget' );
	}

	function wporg_email_changes_for_post_types( $post_types ) {
		if ( ! in_array( 'handbook', $post_types ) ) {
			$post_types[] = 'handbook';
		}
		return $post_types;
	}
}

new WPorg_Handbook;
