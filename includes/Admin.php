<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package FacebookCommerce
 */

namespace SkyVerge\WooCommerce\Facebook;

defined( 'ABSPATH' ) or exit;

/**
 * Admin handler.
 */
class Admin {


	/**
	 * Admin constructor.
	 */
	public function __construct() {

		// add admin notification in case of site URL change
		add_action( 'admin_notices', [ $this, 'validate_cart_url' ] );

		// add column for displaying Facebook sync enabled/disabled
		add_filter( 'manage_product_posts_columns',       [ $this, 'add_product_list_table_column' ] );
		add_action( 'manage_product_posts_custom_column', [ $this, 'add_product_list_table_column_content' ] );

		// add input to filter products by Facebook sync enabled
		add_action( 'restrict_manage_posts', [ $this, 'add_products_by_sync_enabled_input_filter' ], 40 );
		add_filter( 'request',               [ $this, 'filter_products_by_sync_enabled' ] );

		// add bulk actions to manage products sync
		add_filter( 'bulk_actions-edit-product',        [ $this, 'add_products_sync_bulk_actions' ], 40 );
		add_action( 'handle_bulk_actions-edit-product', [ $this, 'handle_products_sync_bulk_actions' ] );

		// add Product data tab
		add_filter( 'woocommerce_product_data_tabs', [ $this, 'fb_new_product_tab' ] );
		add_action( 'woocommerce_product_data_panels', [ $this, 'fb_new_product_tab_content' ] );
	}


	/**
	 * Adds a column for Facebook Sync in the products edit screen.
	 *
	 * @internal
	 *
	 * @param array $columns array of keys and labels
	 * @return array
	 */
	public function add_product_list_table_column( $columns ) {

		$columns['facebook_sync_enabled'] = __( 'FB Sync Enabled', 'facebook-for-woocommerce' );

		return $columns;
	}


	/**
	 * Outputs sync information for products in the edit screen.
	 *
	 * @internal
	 *
	 * @param string $column the current column in the posts table
	 */
	public function add_product_list_table_column_content( $column ) {
		global $post;

		if ( 'facebook_sync_enabled' === $column ) {

			$product = wc_get_product( $post );

			if ( $product && Products::is_sync_enabled_for_product( $product ) ) {
				esc_html_e( 'Enabled', 'facebook-for-woocommerce' );
			} else {
				esc_html_e( 'Disabled', 'facebook-for-woocommerce' );
			}
		}
	}


	/**
	 * Adds a dropdown input to let shop managers filter products by sync setting.
	 *
	 * @internal
	 */
	public function add_products_by_sync_enabled_input_filter() {
		global $typenow;

		if ( 'product' !== $typenow ) {
			return;
		}

		$choice = isset( $_GET['fb_sync_enabled'] ) ? (string) $_GET['fb_sync_enabled'] : '';

		?>
		<select name="fb_sync_enabled">
			<option value="" <?php selected( $choice, '' ); ?>><?php esc_html_e( 'Filter by Facebook sync setting', 'facebook-for-woocommerce' ); ?></option>
			<option value="yes" <?php selected( $choice, 'yes' ); ?>><?php esc_html_e( 'Facebook sync enabled', 'facebook-for-woocommerce' ); ?></option>
			<option value="no" <?php selected( $choice, 'no' ); ?>><?php esc_html_e( 'Facebook sync disabled', 'facebook-for-woocommerce' ); ?></option>
		</select>
		<?php
	}


	/**
	 * Filters products by Facebook sync setting.
	 *
	 * @internal
	 *
	 * @param array $query_vars product query vars for the edit screen
	 * @return array
	 */
	public function filter_products_by_sync_enabled( $query_vars ) {

		if ( isset( $_REQUEST['fb_sync_enabled'] ) && in_array( $_REQUEST['fb_sync_enabled'], [ 'yes', 'no' ], true ) ) {

			// by default use an "AND" clause if multiple conditions exist for a meta query
			if ( ! empty( $query_vars['meta_query'] ) ) {
				$query_vars['meta_query']['relation'] = 'AND';
			} else {
				$query_vars['meta_query'] = [];
			}

			// when checking for products with sync enabled we need to check both "yes" and meta not set, this requires adding an "OR" clause
			if ( 'yes' === $_REQUEST['fb_sync_enabled'] ) {

				$query_vars['meta_query']['relation'] = 'OR';
				$query_vars['meta_query'][]           = [
					'key'   => Products::SYNC_ENABLED_META_KEY,
					'value' => 'yes',
				];
				$query_vars['meta_query'][]           = [
					'key'     => Products::SYNC_ENABLED_META_KEY,
					'compare' => 'NOT EXISTS',
				];

			} else {

				$query_vars['meta_query'][] = [
					'key'   => Products::SYNC_ENABLED_META_KEY,
					'value' => 'no',
				];
			}
		}

		return $query_vars;
	}


	/**
	 * Adds bulk actions in the products edit screen.
	 *
	 * @internal
	 *
	 * @param array $bulk_actions array of bulk action keys and labels
	 * @return array
	 */
	public function add_products_sync_bulk_actions( $bulk_actions ) {

		$bulk_actions['facebook_include'] = __( 'Include in Facebook sync', 'facebook-for-woocommerce' );
		$bulk_actions['facebook_exclude'] = __( 'Exclude from Facebook sync', 'facebook-for-woocommerce' );

		return $bulk_actions;
	}


	/**
	 * Handles a Facebook product sync bulk action.
	 *
	 * @internal
	 *
	 * @param string $redirect admin URL used by WordPress to redirect after performing the bulk action
	 * @return string
	 */
	public function handle_products_sync_bulk_actions( $redirect ) {

		// primary dropdown at the top of the list table
		$action = isset( $_REQUEST['action'] ) && -1 !== (int) $_REQUEST['action'] ? $_REQUEST['action'] : null;

		// secondary dropdown at the bottom of the list table
		if ( ! $action ) {
			$action = isset( $_REQUEST['action2'] ) && -1 !== (int) $_REQUEST['action2'] ? $_REQUEST['action2'] : null;
		}

		if ( $action && in_array( $action, [ 'facebook_include', 'facebook_exclude' ], true ) ) {

			$products    = [];
			$product_ids = isset( $_REQUEST['post'] ) && is_array( $_REQUEST['post'] ) ? array_map( 'absint', $_REQUEST['post'] ) : [];

			if ( ! empty( $product_ids ) ) {

				foreach ( $product_ids as $product_id ) {

					if ( $product = wc_get_product( $product_id ) ) {

						$products[] = $product;
					}
				}

				if ( 'facebook_include' === $action ) {
					Products::enable_sync_for_products( $products );
				} elseif ( 'facebook_exclude' === $action ) {
					Products::disable_sync_for_products( $products );
				}
			}
		}

		return $redirect;
	}


	/**
	 * Prints a notice on products page in case the current cart URL is not the original sync URL.
	 *
	 * @internal
	 *
	 * TODO: update this method to use the notice handler once we framework the plugin {CW 2020-01-09}
	 *
	 * @since x.y.z
	 */
	public function validate_cart_url() {
		global $current_screen;

		if ( isset( $current_screen->id ) && in_array( $current_screen->id, [ 'edit-product', 'product' ], true ) ) :

			$cart_url = get_option( \WC_Facebookcommerce_Integration::FB_CART_URL, '' );

			if ( ! empty( $cart_url ) && $cart_url !== wc_get_cart_url() ) :

				?>
				<div class="notice notice-warning">
					<?php printf(
						/* translators: Placeholders: %1$s - Facebook for Woocommerce, %2$s - opening HTML <a> link tag, %3$s - closing HTML </a> link tag */
						'<p>' . esc_html__( '%1$s: One or more of your products is using a checkout URL that may be different than your shop checkout URL. %2$sRe-sync your products to update checkout URLs on Facebook%3$s.', 'facebook-for-woocommerce' ) . '</p>',
						'<strong>' . esc_html__( 'Facebook for WooCommerce', 'facebook-for-woocommerce' ) . '</strong>',
						'<a href="' . esc_url( WOOCOMMERCE_FACEBOOK_PLUGIN_SETTINGS_URL ) . '">',
						'</a>'
					); ?>
				</div>
				<?php

			endif;

		endif;
	}


	/**
	 * Adds a new tab to the Product edit page.
	 *
	 * @param $tabs
	 * @return mixed
	 */
	public function fb_new_product_tab( $tabs ) {

		$tabs['fb_commerce_tab'] = [
			'label'  => __( 'Facebook', 'facebook-for-woocommerce' ),
			'target' => 'facebook_options',
			'class'  => [ 'show_if_simple', 'show_if_variable' ],
		];

		return $tabs;
	}


	/**
	 * Adds content to the new Facebook tab on the Product edit page.
	 */
	public function fb_new_product_tab_content() {
		global $post;

		$woo_product = new \WC_Facebook_Product( $post->ID );
		$description = get_post_meta( $post->ID, \WC_Facebookcommerce_Integration::FB_PRODUCT_DESCRIPTION, true );
		$price       = get_post_meta( $post->ID, \WC_Facebook_Product::FB_PRODUCT_PRICE, true );
		$image       = get_post_meta( $post->ID, \WC_Facebook_Product::FB_PRODUCT_IMAGE, true );

		$image_setting = null;
		if ( \WC_Facebookcommerce_Utils::is_variable_type( $woo_product->get_type() ) ) {
			$image_setting = $woo_product->get_use_parent_image();
		}

		// 'id' attribute needs to match the 'target' parameter set above
		?>
		<div id='facebook_options' class='panel woocommerce_options_panel'>
			<div class='options_group'>
				<?php

				woocommerce_wp_textarea_input( [
					'id'          => \WC_Facebookcommerce_Integration::FB_PRODUCT_DESCRIPTION,
					'label'       => __( 'Facebook Description', 'facebook-for-woocommerce' ),
					'desc_tip'    => 'true',
					'description' => __(
						'Custom (plain-text only) description for product on Facebook. If blank, product description will be used. If product description is blank, shortname will be used.',
						'facebook-for-woocommerce'
					),
					'cols'        => 40,
					'rows'        => 20,
					'value'       => $description,
				] );

				woocommerce_wp_textarea_input( [
					'id'          => \WC_Facebook_Product::FB_PRODUCT_IMAGE,
					'label'       => __( 'Facebook Product Image', 'facebook-for-woocommerce' ),
					'desc_tip'    => 'true',
					'description' => __(
						'Image URL for product on Facebook. Must be an absolute URL e.g. https://... This can be used to override the primary image that will be used on Facebook for this product. If blank, the primary product image in Woo will be used as the primary image on FB.',
						'facebook-for-woocommerce'
					),
					'cols'        => 40,
					'rows'        => 10,
					'value'       => $image,
				] );

				woocommerce_wp_text_input( [
					'id'          => \WC_Facebook_Product::FB_PRODUCT_PRICE,
					'label'       => sprintf(
					/* translators: Placeholders %1$s - WC currency symbol */
						__( 'Facebook Price (%1$s)', 'facebook-for-woocommerce' ),
						get_woocommerce_currency_symbol()
					),
					'desc_tip'    => 'true',
					'description' => __(
						'Custom price for product on Facebook. Please enter in monetary decimal (.) format without thousand separators and currency symbols. If blank, product price will be used.',
						'facebook-for-woocommerce'
					),
					'cols'        => 40,
					'rows'        => 60,
					'value'       => $price,
				] );

				if ( $image_setting !== null ) {

					woocommerce_wp_checkbox( [
						'id'          => \WC_Facebookcommerce_Integration::FB_VARIANT_IMAGE,
						'label'       => __( 'Use Parent Image', 'facebook-for-woocommerce' ),
						'required'    => false,
						'desc_tip'    => 'true',
						'description' => __(
							'By default, the primary image uploaded to Facebook is the image specified in each variant, if provided. However, if you enable this setting, the image of the parent will be used as the primary image for this product and all its variants instead.',
							'facebook-for-woocommerce'
						),
						'value'       => $image_setting ? 'yes' : 'no',
					] );
				}

				?>
			</div>
		</div>
		<?php
	}


}
