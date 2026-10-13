'use strict';

(function (window, document, $) {
	const scrubOptionsButton = document.getElementById( 'safety-net-scrub-options' );
	const deactivatePluginsButton = document.getElementById( 'safety-net-deactivate-plugins' );
	const deleteUsersButton = document.getElementById( 'safety-net-delete-users' );
	const settingsTitle = document.getElementById( 'safety-net-settings-title' );
	const deleteTransientsButton = document.getElementById( 'safety-net-delete-transients' );
	const disableWebhooksButton = document.getElementById( 'safety-net-disable-webhooks' );

	const emailLogDialog      = document.getElementById( 'safety-net-email-log-dialog' );
	const emailLogDialogBody  = emailLogDialog ? emailLogDialog.querySelector( '.safety-net-email-log-dialog-body' ) : null;
	const emailLogDialogClose = emailLogDialog ? emailLogDialog.querySelector( '.safety-net-email-log-dialog-close' ) : null;
	let emailLogOpener        = null;
	let emailLogRequest       = 0;
	let emailLogPressOutside  = false;

	function deleteTransients() {
		if ( ! confirm( 'Are you sure you want to delete transients? This cannot be undone!') ) {
			return;
		}

		ajax({
			action: 'safety_net_delete_transients',
			nonce: deleteTransientsButton.dataset.nonce,
		});
	}

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

	function disableWebhooks() {
		if ( ! confirm( 'Are you sure you want to disable webhooks? This cannot be undone!') ) {
			return;
		}

		ajax({
			action: 'safety_net_disable_webhooks',
			nonce: disableWebhooksButton.dataset.nonce,
		});
	}

	function confirmSubmit( event ) {
		const form = event.target;

		if ( ! ( form instanceof HTMLFormElement ) || ! form.dataset.safetyNetConfirm ) {
			return;
		}

		if ( ! confirm( form.dataset.safetyNetConfirm ) ) {
			event.preventDefault();
		}
	}

	// Only switching on is confirmed; switching off is always safe.
	function confirmSwitchOn( event ) {
		const input = event.target;

		if ( ! ( input instanceof HTMLInputElement ) || 'checkbox' !== input.type || ! input.checked || ! input.dataset.safetyNetConfirm ) {
			return;
		}

		if ( ! confirm( input.dataset.safetyNetConfirm ) ) {
			input.checked = false;
		}
	}

	function initEmailLogTabs( root ) {
		for ( const tablist of root.querySelectorAll( '.safety-net-email-log-tabs' ) ) {
			const selected = tablist.querySelector( '[role="tab"][aria-selected="true"]' ) || tablist.querySelector( '[role="tab"]' );

			if ( selected ) {
				selectEmailLogTab( selected, false );
			}
		}
	}

	function selectEmailLogTab( tab, focus ) {
		const tablist = tab.closest( '[role="tablist"]' );

		if ( ! tablist ) {
			return;
		}

		for ( const other of tablist.querySelectorAll( '[role="tab"]' ) ) {
			const isSelected = other === tab;
			const panel      = document.getElementById( other.getAttribute( 'aria-controls' ) );

			other.setAttribute( 'aria-selected', isSelected ? 'true' : 'false' );
			other.tabIndex = isSelected ? 0 : -1;

			if ( panel ) {
				panel.hidden = ! isSelected;
			}
		}

		if ( focus ) {
			tab.focus();
		}
	}

	function getEmailLogTab( event ) {
		return event.target instanceof Element ? event.target.closest( '.safety-net-email-log-tabs [role="tab"]' ) : null;
	}

	function clickEmailLogTab( event ) {
		const tab = getEmailLogTab( event );

		if ( tab ) {
			selectEmailLogTab( tab, false );
		}
	}

	function moveEmailLogTab( event ) {
		const tab = getEmailLogTab( event );

		if ( ! tab ) {
			return;
		}

		const tabs = Array.from( tab.closest( '[role="tablist"]' ).querySelectorAll( '[role="tab"]' ) );
		let index  = tabs.indexOf( tab );

		switch ( event.key ) {
			case 'ArrowRight':
				index = ( index + 1 ) % tabs.length;
				break;
			case 'ArrowLeft':
				index = ( index - 1 + tabs.length ) % tabs.length;
				break;
			case 'Home':
				index = 0;
				break;
			case 'End':
				index = tabs.length - 1;
				break;
			default:
				return;
		}

		event.preventDefault();
		selectEmailLogTab( tabs[ index ], true );
	}

	function openEmailLogEntry( event ) {
		const link = event.target instanceof Element ? event.target.closest( 'a.safety-net-email-log-view' ) : null;

		// Without the dialog, or for a new tab or window, the link opens the entry's own page.
		if ( ! link || ! emailLogDialogBody || 'function' !== typeof emailLogDialog.showModal || ! window.fetch || ! window.safety_net_params ) {
			return;
		}

		if ( 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}

		event.preventDefault();

		const request = ++emailLogRequest;
		const url     = new URL( window.safety_net_params.ajax_url, window.location.href );

		url.searchParams.set( 'action', 'safety_net_email_log_entry' );
		url.searchParams.set( 'entry', link.dataset.entry );
		url.searchParams.set( '_wpnonce', emailLogDialog.dataset.nonce );

		emailLogOpener = link;
		showEmailLogMessage( emailLogDialog.dataset.loading );
		emailLogDialogBody.setAttribute( 'aria-busy', 'true' );

		if ( ! emailLogDialog.open ) {
			emailLogDialog.showModal();
		}

		speak( emailLogDialog.dataset.loading );

		window.fetch( url.toString(), { credentials: 'same-origin' } ).then( readJson ).then( showEntry ).catch( showError );

		function readJson( response ) {
			return response.json();
		}

		function showEntry( json ) {
			if ( request !== emailLogRequest ) {
				return;
			}

			if ( json && json.success && json.data && 'string' === typeof json.data.html ) {
				emailLogDialogBody.innerHTML = json.data.html;
				initEmailLogTabs( emailLogDialogBody );
				emailLogDialogBody.removeAttribute( 'aria-busy' );
				speak( emailLogDialog.dataset.loaded );
			} else {
				showEmailLogError( json && json.data && json.data.message ? json.data.message : emailLogDialog.dataset.error );
			}
		}

		function showError() {
			if ( request === emailLogRequest ) {
				showEmailLogError( emailLogDialog.dataset.error );
			}
		}
	}

	function speak( message, politeness ) {
		if ( message && window.wp && window.wp.a11y && 'function' === typeof window.wp.a11y.speak ) {
			window.wp.a11y.speak( message, politeness );
		}
	}

	function showEmailLogMessage( message ) {
		const paragraph = document.createElement( 'p' );

		paragraph.textContent          = message;
		emailLogDialogBody.textContent = '';
		emailLogDialogBody.appendChild( paragraph );
	}

	function showEmailLogError( message ) {
		showEmailLogMessage( message );
		emailLogDialogBody.removeAttribute( 'aria-busy' );
		speak( message, 'assertive' );
	}

	function closeEmailLogDialog() {
		emailLogDialog.close();
	}

	// The backdrop and the dialog's scrollbar both target the dialog itself; only the backdrop is outside its box.
	function isOutsideEmailLogDialog( event ) {
		const rect = emailLogDialog.getBoundingClientRect();

		return event.target === emailLogDialog && ( event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom );
	}

	function pressEmailLogDialog( event ) {
		emailLogPressOutside = isOutsideEmailLogDialog( event );
	}

	// Only a press that also started on the backdrop: selecting text in the dialog and releasing outside it clicks the dialog too.
	function closeEmailLogDialogOutside( event ) {
		const pressedOutside = emailLogPressOutside;

		emailLogPressOutside = false;

		if ( pressedOutside && isOutsideEmailLogDialog( event ) ) {
			emailLogDialog.close();
		}
	}

	function resetEmailLogDialog() {
		++emailLogRequest;
		emailLogPressOutside           = false;
		emailLogDialogBody.textContent = '';
		emailLogDialogBody.removeAttribute( 'aria-busy' );

		if ( emailLogOpener ) {
			emailLogOpener.focus();
			emailLogOpener = null;
		}
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

	// If the delete transients button exists, add a click event listener.
	if (deleteTransientsButton) {
		deleteTransientsButton.addEventListener('click', deleteTransients);
	}

	// If the disable webhooks button exists, add a click event listener.
	if (disableWebhooksButton) {
		disableWebhooksButton.addEventListener('click', disableWebhooks);
	}

	// Delegated, so that the forms of an entry loaded into the dialog are handled too.
	document.addEventListener( 'submit', confirmSubmit );
	document.addEventListener( 'change', confirmSwitchOn );
	document.addEventListener( 'click', clickEmailLogTab );
	document.addEventListener( 'keydown', moveEmailLogTab );
	document.addEventListener( 'click', openEmailLogEntry );

	if ( emailLogDialogBody ) {
		emailLogDialog.addEventListener( 'close', resetEmailLogDialog );
		emailLogDialog.addEventListener( 'pointerdown', pressEmailLogDialog );
		emailLogDialog.addEventListener( 'click', closeEmailLogDialogOutside );
	}

	if ( emailLogDialogClose ) {
		emailLogDialogClose.addEventListener( 'click', closeEmailLogDialog );
	}

	initEmailLogTabs( document );
})(window, document, jQuery);
