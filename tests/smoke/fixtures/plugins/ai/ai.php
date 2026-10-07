<?php
/* Plugin Name: AI stub (fixture) */

// The real plugin's deactivation hook decrypts every stored key back into a plaintext connectors option.
register_deactivation_hook(
	__FILE__,
	static function () {
		update_option( 'connectors_ai_openai_api_key', 'sk-test-decrypted-on-deactivation' );
	}
);
