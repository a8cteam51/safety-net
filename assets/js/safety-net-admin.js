'use strict';

(function (window, document, $) {
	const scrubOptionsButton = document.getElementById( 'safety-net-scrub-options' );
	const deactivatePluginsButton = document.getElementById( 'safety-net-deactivate-plugins' );
	const deleteUsersButton = document.getElementById( 'safety-net-delete-users' );
	const settingsTitle = document.getElementById( 'safety-net-settings-title' );

	function scrubOptions() {
		if ( ! confirm( 'Are you sure you want to scrub options? This cannot be undone!') ) {
			return;
		}

		ajax({
			action: 'safety_net_scrub_options',
			nonce: scrubOptionsButton.dataset.nonce,
		});
	}

	function deactivatePlugins() {
		if ( ! confirm( 'Are you sure you want to deactivate plugins? Make sure you scrub options first!') ) {
			return;
		}

		ajax({
			action: 'safety_net_deactivate_plugins',
			nonce: deactivatePluginsButton.dataset.nonce,
		});
	}


	function deleteUsers() {
		if ( ! confirm( 'Are you sure you want to delete all users? This cannot be undone!') ) {
			return;
		}

		ajax({
			action: 'safety_net_delete_users',
			nonce: deleteUsersButton.dataset.nonce,
		});
	}

	function ajax(data) {
		$.ajax(
			{
				type : 'POST',
				url : window.safety_net_params.ajax_url,
				data : data,
				dataType: 'json',
				beforeSend: function() {
					hideAdminNotice();

					// If the scrub options button exists, disable it.
					if (scrubOptionsButton) {
						scrubOptionsButton.disabled = true;
					}

					// If the deactivate plugins button exists, disable it.
					if (deactivatePluginsButton) {
						deactivatePluginsButton.disabled = true;
					}

					// If the delete users button exists, disable it.
					if (deleteUsersButton) {
						deleteUsersButton.disabled = true;
					}

					toggleLoadingOverlay();
				},
				error : function(request, status, error) {
					showAdminNotice({
						type : 'error',
						message : status + ': ' + error,
					});
				},
				success: function(response) {

					// If the scrub options button exists, enable it.
					if (scrubOptionsButton) {
						scrubOptionsButton.disabled = false;
					}

					// If the deactivate plugins button exists, enable it.
					if (deactivatePluginsButton) {
						deactivatePluginsButton.disabled = false;
					}

					// If the delete users button exists, enable it.
					if (deleteUsersButton) {
						deleteUsersButton.disabled = false;
					}

					toggleLoadingOverlay();

					if ( true === response.success ) {
						showAdminNotice({
							type : 'success',
							message : response.message,
						});
					} else {
						showAdminNotice({
							type : 'error',
							message : response.message,
						});
					}
				}
			}
		)
	}

	function toggleLoadingOverlay() {
		$('body').toggleClass('loading');
	}

	function showAdminNotice(options) {
		let classes = 'notice settings-error is-dismissible';

		if (undefined !== options.type) {
			classes += ' notice-' + options.type;
		}

		$(settingsTitle).after('<div class="' + classes + '"><p>' + options.message + '</p><button id="safety-net-dismiss-notice" type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button></div>');

		$('#safety-net-dismiss-notice').on('click', hideAdminNotice);
	}

	function hideAdminNotice() {
		const $notice = $('.notice.settings-error');

		$notice.fadeTo(100, 0, function() {
			$notice.slideUp(100, function() {
				$notice.remove();
			});
		});
	}

	// If the scrub options button exists, add a click event listener.
	if (scrubOptionsButton) {
		scrubOptionsButton.addEventListener('click', scrubOptions);
	}

	// If the deactivate plugins button exists, add a click event listener.
	if (deactivatePluginsButton) {
		deactivatePluginsButton.addEventListener('click', deactivatePlugins);
	}

	// If the delete users button exists, add a click event listener.
	if (deleteUsersButton) {
		deleteUsersButton.addEventListener('click', deleteUsers);
	}
})(window, document, jQuery);
