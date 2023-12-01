<?php

/**
 * Remove the notice about premium functionality
 */
function rent_fetch_remove_premium_functionality_notice() {
	remove_action( 'rent_fetch_do_settings_general', 'rent_fetch_settings_sync_functionality_notice', 25 );
}
add_action( 'wp_loaded', 'rent_fetch_remove_premium_functionality_notice' );
 
/**
 * Add the sync options section
 */
add_action( 'rent_fetch_do_settings_general', 'rent_fetch_settings_sync', 25 );
function rent_fetch_settings_sync() {
	?>
	<div class="row">
		<div class="column">
			<label for="rentfetch_options_data_sync">Data Sync</label>
			<p class="description">When you start syncing from data from your management software, it generally takes 5-15 seconds per property to sync. <strong>Rome wasn't built in a day.</strong></p>
		</div>
		<div class="column">
			<ul class="radio">
				<li>
					<label>
						<input type="radio" name="rentfetch_options_data_sync" id="rentfetch_options_data_sync" value="nosync" <?php checked( get_option( 'rentfetch_options_data_sync' ), 'nosync' ); ?>>
						Pause all syncing from all APIs
					</label>
				</li>
				<li>
					<label>
						<input type="radio" name="rentfetch_options_data_sync" id="rentfetch_options_data_sync" value="updatesync" <?php checked( get_option( 'rentfetch_options_data_sync' ), 'updatesync' ); ?>>
						Update data on this site with data from the API. This option should never modify manually-added properties/floorplans, nor should it overwrite any custom data you've added to otherwise synced properties/floorplans.
					</label>
				</li>
				<li>
					<label>
						<input type="radio" name="rentfetch_options_data_sync" id="rentfetch_options_data_sync" value="delete" <?php checked( get_option( 'rentfetch_options_data_sync' ), 'delete' ); ?>>
						<span style="color: red;">Delete all data that's been pulled from a third-party API. <strong style="color: white; background-color: red; padding: 3px 5px; border-radius: 3px;">This will take place immediately upon saving. There is no undo.</strong></span>
					</label>
				</li>
			</ul>
		</div>
	</div>
	
	<!-- <div class="row">
		<div class="column">
			<label for="rentfetch_options_sync_term">Sync Term</label>
		</div>
		<div class="column">
			<p class="description">If you're seeing repeated API failures, pausing this temporarily can often clean up zombie tasks that apply to properties no longer listed in your API settings. It can also serve to reset overdue tasks in the event that API functionality was unavailable for a period (for example, on a staging site behind basic authentication).</p>
			<select name="rentfetch_options_sync_term" id="rentfetch_options_sync_term" value="<?php echo esc_attr( get_option( 'rentfetch_options_sync_term' ) ); ?>">
				<option value="paused" <?php selected( get_option( 'rentfetch_options_sync_term' ), 'paused' ); ?>>Paused</option>
				<option value="hourly" <?php selected( get_option( 'rentfetch_options_sync_term' ), 'hourly' ); ?>>Hourly</option>
			</select>
		</div>
	</div> -->
	
	<div class="row">
		<div class="column">
			<label for="rentfetch_options_enabled_integrations">Enabled Integrations</label>
		</div>
		<div class="column">
			<script type="text/javascript">
				jQuery(document).ready(function( $ ) {
	
					$( '.integration' ).hide();
					
					// on load and on change of input[name="rentfetch_options_enabled_integrations[]"], show/hide the integration options
					$( 'input[name="rentfetch_options_enabled_integrations[]"]' ).on( 'change', function() {
						
						// hide all the integration options
						$( '.integration' ).hide();
						
						// show the integration options for the checked integrations
						$( 'input[name="rentfetch_options_enabled_integrations[]"]:checked' ).each( function() {
							$( '.integration.' + $( this ).val() ).show();
						});
						
					}).trigger( 'change' );
					
				});
				
			</script>
			<ul class="checkboxes">
				<li>
					<label>
						<input type="checkbox" name="rentfetch_options_enabled_integrations[]" value="yardi" <?php checked( in_array( 'yardi', get_option( 'rentfetch_options_enabled_integrations', array() ) ) ); ?>>
						Yardi/RentCafe
					</label>
				</li>
				<li>
					<label>
						<input type="checkbox" name="rentfetch_options_enabled_integrations[]" value="entrata" <?php checked( in_array( 'entrata', get_option( 'rentfetch_options_enabled_integrations', array() ) ) ); ?>>
						Entrata
					</label>
				</li>
				<li>
					<label>
						<input type="checkbox" name="rentfetch_options_enabled_integrations[]" value="realpage" <?php checked( in_array( 'realpage', get_option( 'rentfetch_options_enabled_integrations', array() ) ) ); ?>>
						RealPage
					</label>
				</li>
				<li>
					<label>
						<input type="checkbox" name="rentfetch_options_enabled_integrations[]" value="appfolio" <?php checked( in_array( 'appfolio', get_option( 'rentfetch_options_enabled_integrations', array() ) ) ); ?>>
						Appfolio
					</label>
				</li>
			</ul>
		</div>
	</div>
	
	<div class="row integration yardi">
		<div class="column">
			<label>Yardi/RentCafe</label>
		</div>
		<div class="column">
			<div class="white-box">
				<label for="rentfetch_options_yardi_integration_creds_yardi_api_key">Yardi API Key</label>
				<input type="text" name="rentfetch_options_yardi_integration_creds_yardi_api_key" id="rentfetch_options_yardi_integration_creds_yardi_api_key" value="<?php echo esc_attr( get_option( 'rentfetch_options_yardi_integration_creds_yardi_api_key' ) ); ?>">
			</div>
			<div class="white-box">
				<label for="rentfetch_options_yardi_integration_creds_yardi_voyager_code">Yardi Voyager Codes</label>
				<textarea rows="10" style="width: 100%;" name="rentfetch_options_yardi_integration_creds_yardi_voyager_code" id="rentfetch_options_yardi_integration_creds_yardi_voyager_code"><?php echo esc_attr( get_option( 'rentfetch_options_yardi_integration_creds_yardi_voyager_code' ) ); ?></textarea>
				<p class="description">Multiple property codes should be entered separated by commas. Please note that on save, these will be automatically converted to property codes. If you have hundreds, this can take a minute or two.</p>
			</div>
			<div class="white-box">
				<label for="rentfetch_options_yardi_integration_creds_yardi_property_code">Yardi Property Codes</label>
				<textarea rows="10" style="width: 100%;" name="rentfetch_options_yardi_integration_creds_yardi_property_code" id="rentfetch_options_yardi_integration_creds_yardi_property_code"><?php echo esc_attr( get_option( 'rentfetch_options_yardi_integration_creds_yardi_property_code' ) ); ?></textarea>
				<p class="description">Multiple property codes should be entered separated by commas</p>
			</div>
			<!-- <div class="white-box">
				<label for="rentfetch_options_yardi_integration_creds_enable_yardi_api_lead_generation">
					<input type="checkbox" name="rentfetch_options_yardi_integration_creds_enable_yardi_api_lead_generation" id="rentfetch_options_yardi_integration_creds_enable_yardi_api_lead_generation" <?php checked( get_option( 'rentfetch_options_yardi_integration_creds_enable_yardi_api_lead_generation' ), true ); ?>>
					Enable Yardi API Lead Generation
				</label>
				<p class="description">Adds a lightbox form on the single properties template which can send leads directly to the Yardi API.</p>
			</div> -->
			<div class="white-box">
				<label for="rentfetch_options_yardi_integration_creds_yardi_username">Yardi Username</label>
				<input type="text" name="rentfetch_options_yardi_integration_creds_yardi_username" id="rentfetch_options_yardi_integration_creds_yardi_username" value="<?php echo esc_attr( get_option( 'rentfetch_options_yardi_integration_creds_yardi_username' ) ); ?>">
			</div>
			<div class="white-box">
				<label for="rentfetch_options_yardi_integration_creds_yardi_password">Yardi Password</label>
				<input type="text" name="rentfetch_options_yardi_integration_creds_yardi_password" id="rentfetch_options_yardi_integration_creds_yardi_password" value="<?php echo esc_attr( get_option( 'rentfetch_options_yardi_integration_creds_yardi_password' ) ); ?>">
			</div>
		</div>
	</div>
	
	<div class="row integration entrata">
		<div class="column">
			<label>Entrata</label>
		</div>
		<div class="column">
			<div class="white-box">
				<label for="rentfetch_options_entrata_integration_creds_entrata_user">Entrata Username</label>
				<input type="text" name="rentfetch_options_entrata_integration_creds_entrata_user" id="rentfetch_options_entrata_integration_creds_entrata_user" value="<?php echo esc_attr( get_option( 'rentfetch_options_entrata_integration_creds_entrata_user' ) ); ?>">
			</div>
			<div class="white-box">
				<label for="rentfetch_options_entrata_integration_creds_entrata_pass">Entrata Password</label>
				<input type="text" name="rentfetch_options_entrata_integration_creds_entrata_pass" id="rentfetch_options_entrata_integration_creds_entrata_pass" value="<?php echo esc_attr( get_option( 'rentfetch_options_entrata_integration_creds_entrata_pass' ) ); ?>">
			</div>
			<div class="white-box">
				<label for="rentfetch_options_entrata_integration_creds_entrata_property_ids">Entrata Property IDs</label>
				<textarea rows="10" style="width: 100%;" name="rentfetch_options_entrata_integration_creds_entrata_property_ids" id="rentfetch_options_entrata_integration_creds_entrata_property_ids"><?php echo esc_attr( get_option( 'rentfetch_options_entrata_integration_creds_entrata_property_ids' ) ); ?></textarea>
				<p class="description">If there are multiple properties to be pulled in, enter those separated by commas</p>
			</div>
		</div>
	</div>
	
	<div class="row integration realpage">
		<div class="column">
			<label>RealPage</label>
		</div>
		<div class="column">
			<div class="white-box">
				<label for="rentfetch_options_realpage_integration_creds_realpage_user">RealPage Username</label>
				<input type="text" name="rentfetch_options_realpage_integration_creds_realpage_user" id="rentfetch_options_realpage_integration_creds_realpage_user" value="<?php echo esc_attr( get_option( 'rentfetch_options_realpage_integration_creds_realpage_user' ) ); ?>">
			</div>
			<div class="white-box">
				<label for="rentfetch_options_realpage_integration_creds_realpage_pass">RealPage Password</label>
				<input type="text" name="rentfetch_options_realpage_integration_creds_realpage_pass" id="rentfetch_options_realpage_integration_creds_realpage_pass" value="<?php echo esc_attr( get_option( 'rentfetch_options_realpage_integration_creds_realpage_pass' ) ); ?>">
			</div>
			<div class="white-box">
				<label for="rentfetch_options_realpage_integration_creds_realpage_pmc_id">RealPage PMC ID</label>
				<input type="text" name="rentfetch_options_realpage_integration_creds_realpage_pmc_id" id="rentfetch_options_realpage_integration_creds_realpage_pmc_id" value="<?php echo esc_attr( get_option( 'rentfetch_options_realpage_integration_creds_realpage_pmc_id' ) ); ?>">
			</div>
			<div class="white-box">
				<label for="rentfetch_options_realpage_integration_creds_realpage_site_ids">RealPage Site IDs</label>
				<textarea rows="10" style="width: 100%;" name="rentfetch_options_realpage_integration_creds_realpage_site_ids" id="rentfetch_options_realpage_integration_creds_realpage_site_ids"><?php echo esc_attr( get_option( 'rentfetch_options_realpage_integration_creds_realpage_site_ids' ) ); ?></textarea>
				<p class="description">If there are multiple properties to be pulled in, enter those separated by commas</p>
			</div>
		</div>
	</div>
	
	<div class="row integration appfolio">
		<div class="column">
			<label>AppFolio</label>
		</div>
		<div class="column">
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
	<?php
}