<?php
/** Keep API locks authoritative in the existing Rent Fetch settings. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function rfs_enforce_locked_sync_setting( $value ) {
	return rfs_is_sync_locked() ? 'nosync' : $value;
}
add_filter( 'pre_update_option_rentfetch_options_data_sync', 'rfs_enforce_locked_sync_setting' );

function rfs_sync_lock_notice_html() {
	$message = str_replace( 'support@brindledigital.com', '<a href="mailto:support@brindledigital.com">support@brindledigital.com</a>', esc_html( rfs_sync_locked_message() ) );
	$html = '<div class="notice notice-error rfs-sync-access-notice rfs-sync-locked-notice"><p><strong>Syncing Locked.</strong> ' . $message . '</p>';
	if ( current_user_can( 'manage_options' ) ) {
		$html .= '<p><a class="rfs-check-sync-access" href="#">Check sync access</a> <span class="rfs-sync-access-result" role="status" aria-live="polite"></span></p>';
	}
	return $html . '</div>';
}

function rfs_render_sync_lock_notice() {
	if ( rfs_is_sync_locked() ) {
		echo rfs_sync_lock_notice_html();
	}
}
add_action( 'all_admin_notices', 'rfs_render_sync_lock_notice' );

function rfs_sync_lock_admin_styles() {
	echo '<style>
	#wpbody-content .rfs-sync-access-notice{display:block;padding:12px 16px}
	.rfs-sync-locked-notice{background:#fcf0f1;border:1px solid #f0c6ca;border-left:4px solid #d63638;color:#8a2424}
	#wpbody-content .rfs-sync-access-notice p{color:inherit;margin:0 0 8px;font-size:14px;line-height:1.6}
	#wpbody-content .rfs-sync-access-notice p:last-child{margin-bottom:0}
	#wpbody-content .rfs-sync-access-notice a{color:inherit;text-decoration:underline}
	#wpbody-content .rfs-sync-access-notice a:focus{outline:2px solid currentColor;outline-offset:2px;box-shadow:none}
	#wpbody-content .rfs-sync-access-notice a[aria-disabled="true"]{opacity:.65;cursor:wait}
	.rfs-sync-access-notice.notice-success{background:#edfaef;border:1px solid #b8dfbf;border-left:4px solid #00a32a;color:#145523}
	.rfs-sync-access-result{margin-left:8px}
	</style>';
}
add_action( 'admin_head', 'rfs_sync_lock_admin_styles' );

function rfs_handle_check_sync_access() {
	check_ajax_referer( 'rfs_check_sync_access', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'You are not allowed to check sync access.' ), 403 );
	}
	$result = rfs_check_sync_access();
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => 'rentfetch_sync_locked' === $result->get_error_code() ? 'Syncing is still locked.' : 'Sync access could not be confirmed. Try again later.' ), 400 );
	}
	wp_send_json_success( rfs_get_accelerated_sync_status_payload() );
}
add_action( 'wp_ajax_rfs_check_sync_access', 'rfs_handle_check_sync_access' );

function rfs_sync_lock_admin_script() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<template id="rfs-sync-lock-notice-template"><?php echo rfs_sync_lock_notice_html(); ?></template>
	<script>
	(function() {
		var config = <?php echo wp_json_encode( array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'rfs_check_sync_access' ), 'settingsUrl' => admin_url( 'admin.php?page=rentfetch-options&tab=general&section=data-sync' ) ) ); ?>;
		document.addEventListener('rfs-sync-locked', function() {
			document.querySelectorAll('.rfs-sync-access-notice.notice-success').forEach(function(notice) { notice.remove(); });
			if (!document.querySelector('.rfs-sync-locked-notice')) {
				// Match WordPress's placement of notices rendered during a page load.
				var notice = document.getElementById('rfs-sync-lock-notice-template').content.cloneNode(true);
				var anchor = document.querySelector('.wp-header-end') || document.querySelector('#wpbody-content .wrap h1, #wpbody-content .wrap h2');
				if (anchor) {
					anchor.after(notice);
				} else {
					document.getElementById('wpbody-content').prepend(notice);
				}
			}
			var resume = document.getElementById('rentfetch_options_data_sync_updatesync');
			if (resume) resume.disabled = true;
			document.querySelectorAll('input[name="rentfetch_options_data_sync"]').forEach(function(input) {
				input.checked = input.value === 'nosync';
			});
		});
		document.addEventListener('click', function(event) {
			var link = event.target.closest('.rfs-check-sync-access');
			if (!link) return;
			event.preventDefault();
			if (link.getAttribute('aria-disabled') === 'true') return;
			var notice = link.closest('.rfs-sync-locked-notice');
			var result = notice.querySelector('.rfs-sync-access-result');
			link.setAttribute('aria-disabled', 'true');
			result.textContent = 'Checking…';
			var body = new URLSearchParams({action: 'rfs_check_sync_access', nonce: config.nonce});
			fetch(config.url, {method: 'POST', credentials: 'same-origin', body: body})
				.then(function(response) { return response.json(); })
				.then(function(response) {
					if (!response.success) {
						result.textContent = response.data && response.data.message ? response.data.message : 'Sync access could not be confirmed. Try again later.';
						return;
					}
					notice.classList.remove('notice-error', 'rfs-sync-locked-notice');
					notice.classList.add('notice-success');
					notice.setAttribute('role', 'status');
					var message = document.createElement('p');
					message.textContent = 'Sync access is unlocked. Syncing remains paused. ';
					var settings = document.createElement('a');
					settings.href = config.settingsUrl;
					settings.textContent = 'Resume syncing in Data Sync';
					message.append(settings);
					notice.replaceChildren(message);
					settings.focus();
					var resume = document.getElementById('rentfetch_options_data_sync_updatesync');
					if (resume) resume.disabled = false;
					document.querySelectorAll('input[name="rentfetch_options_data_sync"]').forEach(function(input) {
						input.checked = input.value === 'nosync';
					});
					document.dispatchEvent(new CustomEvent('rfs-sync-access-checked', {detail: response.data}));
				})
				.catch(function() { result.textContent = 'Sync access could not be confirmed. Try again later.'; })
				.finally(function() { link.removeAttribute('aria-disabled'); });
		});
	})();
	</script>
	<?php
}
add_action( 'admin_footer', 'rfs_sync_lock_admin_script' );
