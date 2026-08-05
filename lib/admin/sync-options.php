<?php

/**
 * Split a saved property identifier list into unique values.
 *
 * @param string|array $value Saved identifiers.
 * @return array
 */
function rfs_parse_property_identifiers( $value ) {
	$identifiers = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );
	$identifiers = array_filter( $identifiers, 'is_scalar' );
	$identifiers = array_map( 'sanitize_text_field', $identifiers );

	return array_values( array_unique( array_filter( $identifiers, 'strlen' ) ) );
}

/**
 * Get property names and existing sync-status classes for a settings field.
 *
 * @param string       $integration Property source.
 * @param string|array $value       Saved identifiers.
 * @return array
 */
function rfs_get_property_tag_data( $integration, $value ) {
	$identifiers = rfs_parse_property_identifiers( $value );
	$data        = array();

	foreach ( $identifiers as $identifier ) {
		$data[ $identifier ] = array(
			'id'           => $identifier,
			'name'         => '',
			'status'       => 'sync-gray',
			'status_label' => 'Not synced',
		);
	}

	if ( empty( $identifiers ) ) {
		return array();
	}

	$post_ids = get_posts(
		array(
			'post_type'      => 'properties',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'AND',
				array(
					'key'     => 'property_id',
					'value'   => $identifiers,
					'compare' => 'IN',
				),
				array(
					'key'   => 'property_source',
					'value' => sanitize_key( $integration ),
				),
			),
		)
	);

	$status_labels = array(
		'sync-green'  => 'Synced within 24 hours',
		'sync-yellow' => 'Last synced 1–3 days ago',
		'sync-orange' => 'Partially synced',
		'sync-red'    => 'Sync failed or is stale',
		'sync-gray'   => 'Not synced',
	);

	foreach ( $post_ids as $post_id ) {
		$identifier = (string) get_post_meta( $post_id, 'property_id', true );
		if ( ! isset( $data[ $identifier ] ) ) {
			continue;
		}

		$title  = trim( wp_strip_all_tags( html_entity_decode( get_the_title( $post_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		$status = function_exists( 'rentfetch_get_sync_status_class' ) ? rentfetch_get_sync_status_class( $post_id ) : 'sync-gray';
		$status = isset( $status_labels[ $status ] ) ? $status : 'sync-gray';

		$data[ $identifier ] = array(
			'id'           => $identifier,
			'name'         => 0 === strcasecmp( $title, $identifier ) ? '' : $title,
			'status'       => $status,
			'status_label' => $status_labels[ $status ],
		);
	}

	return array_values( $data );
}

/**
 * Render a progressively enhanced comma-separated property field.
 *
 * @param array $args Field arguments.
 * @return void
 */
function rfs_render_property_tag_field( $args ) {
	$value          = (string) get_option( $args['name'], '' );
	$description_id = $args['name'] . '_description';
	?>
	<div class="white-box">
		<label for="<?php echo esc_attr( $args['name'] ); ?>"><?php echo esc_html( $args['label'] ); ?></label>
		<textarea rows="5" name="<?php echo esc_attr( $args['name'] ); ?>" id="<?php echo esc_attr( $args['name'] ); ?>" class="rfs-property-tag-source" data-description-id="<?php echo esc_attr( $description_id ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
		<script type="application/json" class="rfs-property-tag-data"><?php echo wp_json_encode( rfs_get_property_tag_data( $args['integration'], $value ), JSON_HEX_TAG | JSON_HEX_AMP ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
		<p class="description" id="<?php echo esc_attr( $description_id ); ?>"><?php echo esc_html( $args['description'] ); ?></p>
	</div>
	<?php
}

/**
 * Save the Rent Manager properties disabled on the rendered settings screen.
 *
 * @return void
 */
function rfs_save_disabled_rentmanager_properties() {
	if ( ! isset( $_POST['rentfetch_options_rentmanager_property_toggles'] ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	check_admin_referer( 'rentfetch_main_options_nonce_action', 'rentfetch_main_options_nonce_field' );

	$rendered = isset( $_POST['rentfetch_options_rentmanager_rendered_property_shortnames'] )
		? rfs_parse_property_identifiers( wp_unslash( $_POST['rentfetch_options_rentmanager_rendered_property_shortnames'] ) )
		: array();
	$enabled  = isset( $_POST['rentfetch_options_rentmanager_enabled_property_shortnames'] )
		? rfs_parse_property_identifiers( wp_unslash( $_POST['rentfetch_options_rentmanager_enabled_property_shortnames'] ) )
		: array();

	$properties = get_option( 'rentfetch_options_rentmanager_integration_creds_rentmanager_property_shortnames', array() );
	if ( ! is_array( $properties ) ) {
		return;
	}

	$all        = rfs_parse_property_identifiers( array_column( $properties, 'ShortName' ) );
	$disabled   = rfs_parse_property_identifiers( get_option( 'rentfetch_options_rentmanager_disabled_property_shortnames', array() ) );
	$disabled   = array_intersect( $disabled, $all );
	$disabled   = array_intersect( array_unique( array_merge( array_diff( $disabled, $rendered ), array_diff( $rendered, $enabled ) ) ), $all );

	update_option( 'rentfetch_options_rentmanager_disabled_property_shortnames', array_values( $disabled ) );

	rfs_rentmanager_remove_units_that_shouldnt_be_synced_property_deleted();
	rfs_rentmanager_remove_floorplans_that_shouldnt_be_synced_property_deleted();
	rfs_rentmanager_remove_properties_that_shouldnt_be_synced_property_deleted();
}
add_action( 'rentfetch_save_settings', 'rfs_save_disabled_rentmanager_properties', 5 );

/**
 * Remove the notice about premium functionality
 */
function rentfetch_remove_premium_functionality_notice() {
	remove_action( 'rentfetch_do_settings_general_data_sync', 'rentfetch_settings_sync_functionality_notice', 25 );
}
add_action( 'wp_loaded', 'rentfetch_remove_premium_functionality_notice' );
 
/**
 * Add the sync options section
 */
add_action( 'rentfetch_do_settings_general_data_sync', 'rentfetch_settings_sync', 25 );
function rentfetch_settings_sync() {
	?>
	<div class="header">
		<h2 class="title">Data Sync</h2>
		<p class="description">Configure data sync behavior and integration credentials.</p>
	</div>

		<div class="row">
			<div class="section">
				<label class="label-large" for="rentfetch_options_data_sync_nosync">Data Sync</label>
				<p class="description">When you start syncing from data from your management software, it generally takes 5-15 seconds per property to sync. <strong>Rome wasn't built in a day.</strong></p>
				<ul class="radio">
					<li>
						<label>
							<input type="radio" name="rentfetch_options_data_sync" id="rentfetch_options_data_sync_nosync" value="nosync" <?php checked( get_option( 'rentfetch_options_data_sync' ), 'nosync' ); ?>>
							Pause all syncing from all APIs
						</label>
					</li>
					<li>
						<label>
							<input type="radio" name="rentfetch_options_data_sync" id="rentfetch_options_data_sync_updatesync" value="updatesync" <?php checked( get_option( 'rentfetch_options_data_sync' ), 'updatesync' ); ?>>
							<span class="rfs-data-sync-copy">
								<span>Update data on this site with data from the API.</span>
								<span class="description rfs-settings-note">This option should never modify manually-added properties/floor plans, nor should it overwrite any custom data you've added to otherwise synced properties/floor plans.</span>
							</span>
						</label>
					</li>
				</ul>
				<?php
				if ( function_exists( 'rfs_render_accelerated_sync_settings_controls' ) ) {
					rfs_render_accelerated_sync_settings_controls();
				}
				if ( function_exists( 'rfs_render_delete_synced_data_settings_controls' ) ) {
					rfs_render_delete_synced_data_settings_controls();
				}
				?>
			</div>
		</div>
		<style>
			.integration-settings { display: none; }
			label:has(input[name="rentfetch_options_enabled_integrations[]"]:checked) ~ .integration-settings { display: block; }
			
			/* Toggle switch styles for integration checkboxes */
			label:has(input[name="rentfetch_options_enabled_integrations[]"]) {
				position: relative;
				display: flex;
				align-items: center;
				cursor: pointer;
				font-weight: normal;
				margin-bottom: 0;
				padding-left: 34px;
				min-height: 24px;
				line-height: 1.4;
			}
			
			label:has(input[name="rentfetch_options_enabled_integrations[]"]) input {
				position: absolute;
				opacity: 0;
				cursor: pointer;
				height: 0;
				width: 0;
			}
			
			label:has(input[name="rentfetch_options_enabled_integrations[]"])::before {
				content: "";
				position: absolute;
				left: 0;
				top: 50%;
				transform: translateY(-50%);
				height: 16px;
				width: 26px;
				background: #afafaf;
				border-radius: 12px;
				transition: all 250ms ease-in-out;
			}
			
			label:has(input[name="rentfetch_options_enabled_integrations[]"])::after {
				content: "";
				position: absolute;
				left: 2px;
				top: 50%;
				transform: translateY(-50%);
				height: 12px;
				width: 12px;
				background: white;
				border-radius: 50%;
				transition: all 250ms ease-in-out;
			}
			
			label:has(input[name="rentfetch_options_enabled_integrations[]"]:checked)::before {
				background: var(--rentfetch-branding-contrast, #007cba);
			}
			
			label:has(input[name="rentfetch_options_enabled_integrations[]"]:checked)::after {
				transform: translate(10px, -50%);
			}

			.rentfetch-yardi-credentials-row {
				display: grid;
				grid-template-columns: repeat(2, minmax(0, 1fr));
				gap: 16px;
			}

			.rfs-property-tag-editor {
				display: grid;
				gap: 6px;
			}
			.rfs-property-tag-editor > .description,
			form.rent-fetch-options .section .rfs-settings-note { margin: 0; color: #646970; font-size: 12px; line-height: 1.35; }
			.rfs-settings-note + input { margin-top: 6px; }
			.rfs-data-sync-copy { display: grid; gap: 2px; }
			form.rent-fetch-options .rfs-property-tag-input {
				height: 40px;
				padding: 0 12px;
				line-height: 40px;
			}
			.rfs-property-tag-tools {
				display: flex;
				align-items: center;
				justify-content: space-between;
				min-height: 22px;
				color: #646970;
				font-size: 12px;
			}
			.rfs-property-tag-tools[hidden] { display: none; }
			.rfs-property-tag-actions { display: flex; gap: 12px; }
			.rfs-property-tags-action {
				padding: 0;
				background: transparent;
				border: 0;
				color: var(--wp-admin-theme-color, #2271b1);
				font: inherit;
				text-decoration: underline;
				cursor: pointer;
			}
			.rfs-property-tags-action:hover { color: var(--wp-admin-theme-color-darker-20, #135e96); }
			.rfs-property-tags-action:focus-visible { border-radius: 2px; outline: 2px solid var(--wp-admin-theme-color, #2271b1); outline-offset: 2px; }
			.rfs-property-tags {
				position: relative;
				display: flex;
				flex-wrap: wrap;
				align-content: flex-start;
				gap: 4px;
				max-height: 180px;
				padding: 6px;
				overflow-y: auto;
				background: #f6f7f7;
				border: 1px solid var(--rentfetch-base-3, #d4d4d4);
				border-radius: 3px;
				box-sizing: border-box;
			}
			.rfs-property-tags:empty {
				display: none;
			}
			.rfs-property-tag,
			.rfs-rentmanager-property {
				--rfs-tag-bg: #f4f5f5;
				--rfs-tag-border: #c3c4c7;
				--rfs-tag-color: #50575e;
				background: var(--rfs-tag-bg);
				border: 1px solid var(--rfs-tag-border);
				color: var(--rfs-tag-color);
			}
			.rfs-property-tag.sync-green, .rfs-rentmanager-property.sync-green { --rfs-tag-bg: #e7f5ea; --rfs-tag-border: #8ecb99; --rfs-tag-color: #1d672a; }
			.rfs-property-tag.sync-yellow, .rfs-rentmanager-property.sync-yellow { --rfs-tag-bg: #fff8df; --rfs-tag-border: #ddc46a; --rfs-tag-color: #705900; }
			.rfs-property-tag.sync-orange, .rfs-rentmanager-property.sync-orange { --rfs-tag-bg: #fff1df; --rfs-tag-border: #dfa55e; --rfs-tag-color: #8a4c00; }
			.rfs-property-tag.sync-red, .rfs-rentmanager-property.sync-red { --rfs-tag-bg: #fff0f1; --rfs-tag-border: #e5a4aa; --rfs-tag-color: #a1262f; }
			.rfs-property-tag {
				display: inline-flex;
				align-items: baseline;
				gap: 4px;
				min-height: 24px;
				padding: 1px 2px 1px 7px;
				border-radius: 4px;
				box-sizing: border-box;
				font-size: 12px;
			}
			.rfs-property-tag::before {
				content: "";
				align-self: center;
				width: 7px;
				height: 7px;
				border-radius: 50%;
				background: currentColor;
				flex: 0 0 7px;
			}
			.rfs-property-tag-name { max-width: 18ch; overflow: hidden; font-weight: 600; text-overflow: ellipsis; white-space: nowrap; }
			.rfs-property-tag-id { font: 11px/1.2 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; opacity: .82; }
			.rfs-property-tag-remove {
				display: grid;
				align-self: center;
				place-items: center;
				width: 20px;
				height: 20px;
				padding: 0 0 2px;
				background: transparent;
				border: 0;
				border-radius: 50%;
				color: inherit;
				font-size: 16px;
				line-height: 1;
				cursor: pointer;
			}
			.rfs-property-tag-remove:hover { background: rgba(0, 0, 0, .09); }
			.rfs-property-tag-remove:focus-visible { background: rgba(0, 0, 0, .09); box-shadow: 0 0 0 2px currentColor; outline: 0; }
			.rfs-rentmanager-properties { display: grid; gap: 8px; margin: 8px 0 0; }
			.rfs-settings-label { color: var(--rentfetch-branding-contrast); font-size: var(--rentfetch-font-size-small); font-weight: 500; line-height: 1.4; margin-bottom: 5px; }
			form.rent-fetch-options .white-box label.rfs-rentmanager-property {
				display: flex;
				align-items: center;
				gap: 12px;
				margin: 0;
				padding: 10px 12px;
				border-radius: 4px;
				box-sizing: border-box;
				width: auto;
			}
			.rfs-rentmanager-property .rfs-property-details { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 8px; min-width: 0; }
			.rfs-rentmanager-property .rfs-property-details:not(:has(strong)) { transform: translateY(2px); }
			.rfs-rentmanager-property code { color: inherit; background: transparent; padding: 0; }
			.rfs-property-toggle { position: relative; display: block; flex: 0 0 34px; width: 34px; height: 20px; }
			.rfs-property-toggle input { position: absolute; opacity: 0; }
			.rfs-property-toggle span { position: absolute; inset: 0; border-radius: 999px; background: #8c8f94; transition: .2s; }
			.rfs-property-toggle span::after { content: ""; position: absolute; width: 16px; height: 16px; top: 2px; left: 2px; border-radius: 50%; background: #fff; transition: .2s; }
			.rfs-property-toggle input:checked + span { background: var(--rentfetch-branding-contrast, #1f313b); }
			.rfs-property-toggle input:checked + span::after { transform: translateX(14px); }
			.rfs-property-toggle input:focus-visible + span { outline: 2px solid var(--wp-admin-theme-color, #2271b1); outline-offset: 2px; }
			@media (max-width: 782px) {
				.rentfetch-yardi-credentials-row {
					grid-template-columns: 1fr;
				}
			}
		</style>
		<div class="row integration yardi">
			<div class="section">
				<label for="rentfetch_options_enabled_integrations_yardi" class="label-large toggle-label" style="margin-bottom: 0 !important;">
					<input type="checkbox" id="rentfetch_options_enabled_integrations_yardi" name="rentfetch_options_enabled_integrations[]" value="yardi" <?php checked( in_array( 'yardi', get_option( 'rentfetch_options_enabled_integrations', array() ) ) ); ?>>
					Yardi/RentCafe
				</label>
				<div class="integration-settings">
					<div class="rentfetch-yardi-credentials-row">
						<div class="white-box">
							<label for="rentfetch_options_yardi_integration_creds_yardi_api_key">Yardi API token</label>
							<input type="password" name="rentfetch_options_yardi_integration_creds_yardi_api_key" id="rentfetch_options_yardi_integration_creds_yardi_api_key" value="<?php echo esc_attr( get_option( 'rentfetch_options_yardi_integration_creds_yardi_api_key' ) ); ?>" autocomplete="off">
						</div>
						<div class="white-box">
							<label for="rentfetch_options_yardi_integration_creds_yardi_company_code">Yardi company code</label>
							<input type="text" name="rentfetch_options_yardi_integration_creds_yardi_company_code" id="rentfetch_options_yardi_integration_creds_yardi_company_code" value="<?php echo esc_attr( get_option( 'rentfetch_options_yardi_integration_creds_yardi_company_code' ) ); ?>">				
						</div>
					</div>
					<!-- <div class="white-box">
						<label for="rentfetch_options_yardi_integration_creds_yardi_voyager_code">Yardi Voyager Codes</label>
						<textarea rows="10" style="width: 100%;" name="rentfetch_options_yardi_integration_creds_yardi_voyager_code" id="rentfetch_options_yardi_integration_creds_yardi_voyager_code"><?php // echo esc_attr( get_option( 'rentfetch_options_yardi_integration_creds_yardi_voyager_code' ) ); ?></textarea>
						<p class="description">Multiple property codes should be entered separated by commas. Please note that on save, these will be automatically converted to property codes. If you have hundreds, this can take a minute or two.</p>
					</div> -->
					<?php
					rfs_render_property_tag_field(
						array(
							'name'        => 'rentfetch_options_yardi_integration_creds_yardi_property_code',
							'label'       => 'Yardi Property Codes',
							'integration' => 'yardi',
							'description' => 'Paste property codes separated by commas, spaces, or line breaks.',
						)
					);
					?>
					<!-- <div class="white-box">
						<label for="rentfetch_options_yardi_integration_creds_enable_yardi_api_lead_generation">
							<input type="checkbox" name="rentfetch_options_yardi_integration_creds_enable_yardi_api_lead_generation" id="rentfetch_options_yardi_integration_creds_enable_yardi_api_lead_generation" <?php checked( get_option( 'rentfetch_options_yardi_integration_creds_enable_yardi_api_lead_generation' ), true ); ?>>
							Enable Yardi API Lead Generation
						</label>
						<p class="description">Adds a lightbox form on the single properties template which can send leads directly to the Yardi API.</p>
					</div> -->
					<!-- <div class="white-box">
						<label for="rentfetch_options_yardi_integration_creds_yardi_username">Yardi Username</label>
						<input type="text" name="rentfetch_options_yardi_integration_creds_yardi_username" id="rentfetch_options_yardi_integration_creds_yardi_username" value="<?php echo esc_attr( get_option( 'rentfetch_options_yardi_integration_creds_yardi_username' ) ); ?>">
					</div>
					<div class="white-box">
						<label for="rentfetch_options_yardi_integration_creds_yardi_password">Yardi Password</label>
						<input type="text" name="rentfetch_options_yardi_integration_creds_yardi_password" id="rentfetch_options_yardi_integration_creds_yardi_password" value="<?php echo esc_attr( get_option( 'rentfetch_options_yardi_integration_creds_yardi_password' ) ); ?>">
					</div> -->
				</div>
			</div>
		</div>

		<div class="row integration engrain">
			<div class="section">
				<label for="rentfetch_options_enabled_integrations_engrain" class="label-large toggle-label" style="margin-bottom: 0 !important;">
					<input type="checkbox" id="rentfetch_options_enabled_integrations_engrain" name="rentfetch_options_enabled_integrations[]" value="engrain" <?php checked( in_array( 'engrain', get_option( 'rentfetch_options_enabled_integrations', array() ), true ) ); ?>>
					Engrain / SightMap
				</label>
				<div class="integration-settings">
					<?php
					rfs_render_property_tag_field(
						array(
							'name'        => 'rentfetch_options_engrain_integration_creds_engrain_asset_ids',
							'label'       => 'Engrain Asset IDs',
							'integration' => 'engrain',
							'description' => 'Paste SightMap asset IDs separated by commas, spaces, or line breaks.',
						)
					);
					?>
				</div>
			</div>
		</div>
		
		<div class="row integration entrata">
			<div class="section">
				<label for="rentfetch_options_enabled_integrations_entrata" class="label-large toggle-label" style="margin-bottom: 0 !important;">
					<input type="checkbox" id="rentfetch_options_enabled_integrations_entrata" name="rentfetch_options_enabled_integrations[]" value="entrata" <?php checked( in_array( 'entrata', get_option( 'rentfetch_options_enabled_integrations', array() ) ) ); ?>>
					Entrata
				</label>
				<div class="integration-settings">
					<div class="white-box">
						<label for="rentfetch_options_entrata_integration_creds_entrata_subdomain">Entrata Subdomain </label>
						<p class="description rfs-settings-note">This is the subdomain of your entrata account. For example, if your account is at https://myaccount.entrata.com, you would enter "myaccount" here.</p>
						<input type="text" name="rentfetch_options_entrata_integration_creds_entrata_subdomain" id="rentfetch_options_entrata_integration_creds_entrata_subdomain" value="<?php echo esc_attr( get_option( 'rentfetch_options_entrata_integration_creds_entrata_subdomain' ) ); ?>">
					</div>
					<?php
					rfs_render_property_tag_field(
						array(
							'name'        => 'rentfetch_options_entrata_integration_creds_entrata_property_ids',
							'label'       => 'Entrata Property IDs',
							'integration' => 'entrata',
							'description' => 'Paste property IDs separated by commas, spaces, or line breaks.',
						)
					);
					?>
				</div>
			</div>
		</div>
		
		<div class="row integration rentmanager">
			<div class="section">
				<label for="rentfetch_options_enabled_integrations_rentmanager" class="label-large toggle-label" style="margin-bottom: 0 !important;">
					<input type="checkbox" id="rentfetch_options_enabled_integrations_rentmanager" name="rentfetch_options_enabled_integrations[]" value="rentmanager" <?php checked( in_array( 'rentmanager', get_option( 'rentfetch_options_enabled_integrations', array() ) ) ); ?>>
					Rent Manager
				</label>
				<div class="integration-settings">
					<div class="white-box">
						<label for="rentfetch_options_rentmanager_integration_creds_rentmanager_companycode">Rent Manager Company Code</label>
						<input type="text" placeholder="e.g. companycode.api.rentmanager.com" name="rentfetch_options_rentmanager_integration_creds_rentmanager_companycode" id="rentfetch_options_rentmanager_integration_creds_rentmanager_companycode" value="<?php echo esc_attr( get_option( 'rentfetch_options_rentmanager_integration_creds_rentmanager_companycode' ) ); ?>">
					</div>
					<div class="white-box">
						<div class="rfs-settings-label">Properties</div>
						<?php

						$option_value = get_option( 'rentfetch_options_rentmanager_integration_creds_rentmanager_property_shortnames' );

						if ( is_string( $option_value ) ) {
							printf( '<p class="description">%s</p>', esc_html( $option_value ) );
						} elseif ( isset( $option_value[0] ) && is_string( $option_value[0] ) ) {
							printf( '<p class="description">%s</p>', esc_html( $option_value[0] ) );
						} elseif ( isset( $option_value[0] ) && is_array( $option_value ) ) {
							$shortnames = rfs_parse_property_identifiers( array_column( $option_value, 'ShortName' ) );
							$tag_data   = array_column( rfs_get_property_tag_data( 'rentmanager', $shortnames ), null, 'id' );
							$disabled   = rfs_parse_property_identifiers( get_option( 'rentfetch_options_rentmanager_disabled_property_shortnames', array() ) );
							?>
							<input type="hidden" name="rentfetch_options_rentmanager_property_toggles" value="1">
							<p class="description rfs-settings-note">Properties are enabled by default. Disable one to stop syncing it without removing it from Rent Manager.</p>
							<div class="rfs-rentmanager-properties">
								<?php foreach ( $option_value as $property ) : ?>
									<?php
									$shortname = sanitize_text_field( $property['ShortName'] ?? '' );
									if ( '' === $shortname ) {
										continue;
									}
									$status   = $tag_data[ $shortname ]['status'] ?? 'sync-gray';
									$tooltip  = $tag_data[ $shortname ]['status_label'] ?? 'Not synced';
									$api_name = sanitize_text_field( $property['Name'] ?? '' );
									$name     = $tag_data[ $shortname ]['name'] ?? $api_name;
									$name     = 0 === strcasecmp( $name, $shortname ) ? '' : $name;
									?>
									<label class="rfs-rentmanager-property <?php echo esc_attr( $status ); ?>" title="<?php echo esc_attr( $tooltip ); ?>">
										<span class="rfs-property-toggle">
											<input type="checkbox" name="rentfetch_options_rentmanager_enabled_property_shortnames[]" value="<?php echo esc_attr( $shortname ); ?>" <?php checked( ! in_array( $shortname, $disabled, true ) ); ?>>
											<span aria-hidden="true"></span>
										</span>
										<span class="rfs-property-details">
											<?php if ( '' !== $name ) : ?><strong><?php echo esc_html( $name ); ?></strong><?php endif; ?>
											<code><?php echo esc_html( $shortname ); ?></code>
											<span class="screen-reader-text"><?php echo esc_html( $tooltip ); ?></span>
										</span>
									</label>
									<input type="hidden" name="rentfetch_options_rentmanager_rendered_property_shortnames[]" value="<?php echo esc_attr( $shortname ); ?>">
								<?php endforeach; ?>
							</div>
							<?php

						}

						?>
					</div>
				</div>
			</div>
		</div>
		
		<!-- <div class="row integration appfolio">
			<div class="section">
				<label for="rentfetch_options_enabled_integrations_appfolio" class="label-large toggle-label">
					<input type="checkbox" id="rentfetch_options_enabled_integrations_appfolio" name="rentfetch_options_enabled_integrations[]" value="appfolio" <?php // checked( in_array( 'appfolio', get_option( 'rentfetch_options_enabled_integrations', array() ) ) ); ?>>
					AppFolio
				</label>
				<div class="integration-settings">
					<div class="white-box">
						<label for="rentfetch_options_appfolio_integration_creds_appfolio_database_name">Appfolio Database Name</label>
						<input type="text" name="rentfetch_options_appfolio_integration_creds_appfolio_database_name" id="rentfetch_options_appfolio_integration_creds_appfolio_database_name" value="<?php echo esc_attr( get_option( 'rentfetch_options_appfolio_integration_creds_appfolio_database_name' ) ); ?>">
						<p class="description">Typically this is xxxxxxxxxxx.appfolio.com</p>
					</div>
					<div class="white-box">
						<label for="rentfetch_options_appfolio_integration_creds_appfolio_client_id">Appfolio Client ID</label>
						<input type="text" name="rentfetch_options_appfolio_integration_creds_appfolio_client_id" id="rentfetch_options_appfolio_integration_creds_appfolio_client_id" value="<?php echo esc_attr( get_option( 'rentfetch_options_appfolio_integration_creds_appfolio_client_id' ) ); ?>">
					</div>
					<div class="white-box">
						<label for="rentfetch_options_appfolio_integration_creds_appfolio_client_secret">Appfolio Client Secret</label>
						<input type="text" name="rentfetch_options_appfolio_integration_creds_appfolio_client_secret" id="rentfetch_options_appfolio_integration_creds_appfolio_client_secret" value="<?php echo esc_attr( get_option( 'rentfetch_options_appfolio_integration_creds_appfolio_client_secret' ) ); ?>">
					</div>
					<div class="white-box">
						<label for="rentfetch_options_appfolio_integration_creds_appfolio_property_ids">Appfolio Property IDs</label>
						<textarea rows="10" style="width: 100%;" name="rentfetch_options_appfolio_integration_creds_appfolio_property_ids" id="rentfetch_options_appfolio_integration_creds_appfolio_property_ids"><?php echo esc_attr( get_option( 'rentfetch_options_appfolio_integration_creds_appfolio_property_ids' ) ); ?></textarea>
						<p class="description">For AppFolio, this is an optional field. If left blank, Rent Fetch will simply fetch all of the properties in the account, which may or not be your preference. Please note that if property IDs are present here, all *other* synced properties through AppFolio will be deleted when the site next syncs.</p>
					</div>
				</div>
			</div>
		</div> -->
	<?php
}
