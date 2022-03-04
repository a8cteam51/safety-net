'use strict';

(function (window, document, $) {
	const anonymizeUsersButton = document.getElementById( 'anonymizer-anonymize-users' );
	const deleteUsersButton = document.getElementById( 'anonymizer-delete-users' );

	console.log(anonymizeUsersButton)
	console.log(deleteUsersButton)

	function anonymizeUsers() {
		alert( 'Are you user you want to anonymize all users? This cannot be undone!');
	}

	function deleteUsers() {
		alert( 'Are you user you want to delete all users? This cannot be undone!');
	}

	anonymizeUsersButton.addEventListener( 'click', anonymizeUsers );
	deleteUsersButton.addEventListener( 'click', deleteUsers );
})(window, document, jQuery);